<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

// Where you're signed in (login_sessions), signing browsers out, the password policy.
class LoginSessionsTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::forceCreate([
            'code' => 'U-ls', 'name' => 'Session user', 'username' => 'ls_user', 'email' => 'ls@example.test', 'phone' => '1',
            'role' => 'admin', 'password' => Hash::make('first-pass-1'), 'status' => 'a', 'ipAddress' => '127.0.0.1', 'branch_id' => Branch::firstOrFail()->id,
        ]);
    }

    private function rows()
    {
        return DB::table('login_sessions')->where('guard', 'web')->where('account_id', $this->user->id);
    }

    // another browser signed in as the same user
    private function otherBrowser(): int
    {
        return DB::table('login_sessions')->insertGetId(['guard' => 'web', 'account_id' => $this->user->id, 'token' => hash('sha256', uniqid('', true)),
            'ip_address' => '203.0.113.9', 'user_agent' => 'Other browser', 'last_seen_at' => now(), 'created_at' => now()]);
    }

    public function test_login_is_tracked_listed_and_audited(): void
    {
        $this->postJson('/login', ['username' => 'ls_user', 'password' => 'first-pass-1', 'portal' => 'admin'])->assertOk();
        $this->assertSame(1, $this->rows()->whereNull('revoked_at')->count());
        $this->assertTrue(AuditLog::where('action', 'auth.login')->where('auditable_id', $this->user->id)->exists());

        $other = $this->otherBrowser();
        $list = $this->postJson('/my-sessions')->assertOk()->json();
        $this->assertCount(2, $list);
        $this->assertSame([true, false], collect($list)->sortByDesc('current')->pluck('current')->values()->all());

        // sign the other browser out; this one stays
        $this->postJson('/my-sessions/revoke', ['id' => $other])->assertOk();
        $this->assertNotNull(DB::table('login_sessions')->find($other)->revoked_at);
        $this->postJson('/my-sessions')->assertOk()->assertJsonCount(1);
    }

    public function test_a_revoked_session_is_signed_out_on_its_next_request(): void
    {
        $this->postJson('/login', ['username' => 'ls_user', 'password' => 'first-pass-1', 'portal' => 'admin'])->assertOk();
        $this->postJson('/my-sessions')->assertOk();
        $this->rows()->update(['revoked_at' => now()]); // e.g. from another browser, or an admin
        $this->postJson('/my-sessions')->assertStatus(401);
        $this->assertGuest('web');
    }

    public function test_a_new_password_follows_the_policy_and_signs_other_browsers_out(): void
    {
        $this->postJson('/login', ['username' => 'ls_user', 'password' => 'first-pass-1', 'portal' => 'admin'])->assertOk();
        $other = $this->otherBrowser();
        $base = ['name' => 'Session user', 'username' => 'ls_user', 'phone' => '1'];

        $this->postJson('/update-profile', $base + ['password' => 'short'])->assertStatus(422);
        $this->postJson('/update-profile', $base + ['password' => 'onlyletters'])->assertStatus(422);
        $this->postJson('/update-profile', $base + ['password' => 'new-pass-22'])->assertOk();

        $this->assertNotNull(DB::table('login_sessions')->find($other)->revoked_at);
        $this->postJson('/my-sessions')->assertOk()->assertJsonCount(1); // this browser is still in
    }

    public function test_deactivating_a_user_signs_them_out_everywhere(): void
    {
        $this->otherBrowser();
        $admin = User::where('role', 'Superadmin')->firstOrFail();
        $this->actingAs($admin, 'web')->withSession(['branch' => Branch::firstOrFail()])->postJson('/update-user', [
            'id' => $this->user->id, 'name' => 'Session user', 'username' => 'ls_user', 'phone' => '1', 'email' => 'ls@example.test', 'role' => 'admin', 'status' => 'p',
        ])->assertOk();
        $this->assertSame(0, $this->rows()->whereNull('revoked_at')->count());
    }
}
