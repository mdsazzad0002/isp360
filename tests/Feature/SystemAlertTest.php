<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;
use App\Support\SystemAlerts;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// The nightly `isp:ledger-check --alert`: an out-of-balance ledger raises an admin banner and one
// e-mail, and the next clean run clears it.
class SystemAlertTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('isp.system_alerts');
        config(['mail.default' => 'array', 'isp.alert_email' => 'noc@example.test']);
    }

    private function mails(): array
    {
        return app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()
            ->map(fn ($m) => $m->getOriginalMessage()->getSubject())->values()->all();
    }

    // the Inertia JSON page, to read the props every page shares
    private function sharedProps(User $user, Branch $branch): array
    {
        $version = app(\App\Http\Middleware\HandleInertiaRequests::class)->version(\Illuminate\Http\Request::create('/'));
        return $this->actingAs($user)->withSession(['branch' => $branch])
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $version])
            ->get('/panel/dashboard')->assertOk()->json('props');
    }

    public function test_an_out_of_balance_ledger_raises_an_alert_until_a_clean_run(): void
    {
        $branch = Branch::firstOrFail();
        $customer = Customer::forceCreate(['code' => 'C-ALERT', 'name' => 'Alert customer', 'phone' => '01711112222', 'branch_id' => $branch->id, 'status' => 'a']);
        // a debit with no invoice behind it and no cached balance: the invariant breaks
        $entry = DB::table('ledger_entries')->insertGetId(['customer_id' => $customer->id, 'entry_date' => now()->toDateString(), 'type' => 'adjustment',
            'description' => 'broken on purpose', 'debit' => 50, 'credit' => 0, 'branch_id' => $branch->id, 'created_at' => now()]);

        $this->artisan('isp:ledger-check --alert')->assertFailed();
        $this->artisan('isp:ledger-check --alert')->assertFailed();

        $alerts = SystemAlerts::all();
        $this->assertCount(1, $alerts);
        $this->assertStringContainsString('1 customer(s) out of balance', $alerts[0]['message']);
        $this->assertSame(1, AuditLog::where('action', 'alert.ledger')->count());
        $this->assertCount(1, $this->mails(), 'the second failing night does not e-mail again');

        // admins see it on every page, other staff don't
        $admin = User::where('role', 'Superadmin')->firstOrFail();
        $this->assertSame('ledger', $this->sharedProps($admin, $branch)['systemAlerts'][0]['key']);
        \App\Models\Role::forceCreate(['name' => 'AlertClerk', 'access' => json_encode(['connection']), 'status' => 'a', 'branch_id' => $branch->id]);
        $clerk = User::forceCreate(['code' => 'U-alert', 'name' => 'Clerk', 'username' => 'alert_clerk', 'email' => 'alert@example.test', 'phone' => '01700000000',
            'role' => 'AlertClerk', 'password' => bcrypt('secret-pass'), 'status' => 'a', 'ipAddress' => '127.0.0.1', 'branch_id' => $branch->id]);
        $this->assertSame([], $this->sharedProps($clerk, $branch)['systemAlerts']);

        // plain run (by hand): reports, but leaves the alert state alone
        DB::table('ledger_entries')->where('id', $entry)->delete();
        $this->artisan('isp:ledger-check')->assertSuccessful();
        $this->assertCount(1, SystemAlerts::all());

        $this->artisan('isp:ledger-check --alert')->assertSuccessful();
        $this->assertSame([], SystemAlerts::all());
        $this->assertSame(1, AuditLog::where('action', 'alert.ledger_recovered')->count());
        $this->assertCount(2, $this->mails());
    }

    public function test_the_check_is_scheduled_nightly_with_the_alert(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'isp:ledger-check --alert'));
        $this->assertCount(1, $events);
        $this->assertSame('40 3 * * *', $events->first()->expression);
    }
}
