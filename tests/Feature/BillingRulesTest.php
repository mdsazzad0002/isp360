<?php

namespace Tests\Feature;

use App\Models\BillingNote;
use App\Models\Branch;
use App\Models\Connection;
use App\Models\ConnectionHistory;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\User;
use App\Services\Isp\ConnectionService;
use App\Services\Isp\IspSettings;
use App\Services\Isp\OverdueService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// Market billing rules (roadmap 2.7): grace period, notice before suspension, late fees.
// All off by default, which keeps the original behaviour: suspended the minute the time ends.
class BillingRulesTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private Branch $branch;
    private int $areaId;
    private Package $package;
    private int $phone = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['isp.network_driver' => \App\Services\Network\NullNetworkDriver::class]);
        $this->admin = User::where('role', 'Superadmin')->firstOrFail();
        $this->branch = Branch::firstOrFail();
        Carbon::setTestNow('2026-09-15 10:00:00');
        $this->areaId = $this->api('/area', ['name' => 'BR Area'])->json('id');
        $this->api('/isp/package', ['name' => 'BR 1000', 'download_mbps' => 10, 'upload_mbps' => 5, 'price' => 1000, 'billing_cycle' => 'monthly'])->assertOk();
        $this->package = Package::where('name', 'BR 1000')->whereNull('reseller_id')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        IspSettings::flush();
        parent::tearDown();
    }

    private function api(string $uri, array $data = [])
    {
        return $this->actingAs($this->admin)->withSession(['branch' => $this->branch])->postJson($uri, $data);
    }

    private function settings(array $values): void
    {
        IspSettings::save($this->branch->id, $values);
    }

    // A connection whose first month is paid: time runs 15 Sep 10:00 → 15 Oct 10:00.
    private function paidConnection(bool $pay = true): Connection
    {
        $phone = '0176666' . str_pad((string) (2000 + ++$this->phone), 4, '0', STR_PAD_LEFT);
        $this->api('/customer', ['name' => "BR Customer {$this->phone}", 'phone' => $phone, 'area_id' => $this->areaId])->assertOk();
        $customer = Customer::where('phone', $phone)->firstOrFail();
        $id = $this->api('/isp/connection', [
            'customer_id' => $customer->id, 'package_id' => $this->package->id, 'connection_type' => 'pppoe',
            'pppoe_username' => 'br_user_' . $customer->id, 'activate_now' => true,
        ])->assertOk()->json('id');
        if ($pay) {
            $this->api('/isp/payment', ['customer_id' => $customer->id, 'amount' => 1000, 'method' => 'cash', 'payment_date' => '2026-09-15'])->assertOk();
        }
        return Connection::findOrFail($id);
    }

    private function runAt(string $time): void
    {
        Carbon::setTestNow($time);
        // both every-minute jobs, as the scheduler runs them
        $this->artisan('isp:generate-invoices')->assertSuccessful();
        $this->artisan('isp:process-overdue')->assertSuccessful();
    }

    public function test_defaults_suspend_the_moment_the_time_ends(): void
    {
        $connection = $this->paidConnection();
        $this->assertSame('2026-10-15 10:00:00', $connection->expire_at->toDateTimeString());
        $this->runAt('2026-10-15 09:59:00');
        $this->assertSame('active', $connection->fresh()->status);
        $this->runAt('2026-10-15 10:00:00');
        $this->assertSame('suspended', $connection->fresh()->status);
        $this->assertNull($connection->fresh()->expiry_notice_at); // no notices unless switched on
    }

    public function test_grace_period_keeps_the_line_on(): void
    {
        $this->settings(['grace_days' => 3]);
        $connection = $this->paidConnection();

        $this->runAt('2026-10-16 10:00:00');
        $connection->refresh();
        $this->assertSame('active', $connection->status);
        $this->assertSame('2026-10-18 10:00:00', OverdueService::suspendAt($connection)->toDateTimeString());
        $this->assertFalse(ConnectionService::needsPayment($connection)); // in grace: staff may switch it back on

        $this->runAt('2026-10-18 09:59:00');
        $this->assertSame('active', $connection->fresh()->status);
        $this->runAt('2026-10-18 10:00:00');
        $this->assertSame('suspended', $connection->fresh()->status);
        $this->assertTrue(ConnectionService::needsPayment($connection->fresh()));

        // paying brings it back at once
        $this->api('/isp/payment', ['customer_id' => $connection->customer_id, 'amount' => 1000, 'method' => 'cash', 'payment_date' => '2026-10-18'])->assertOk();
        $this->assertSame('active', $connection->fresh()->status);
    }

    public function test_notice_goes_out_once_before_suspension(): void
    {
        $this->settings(['grace_days' => 1, 'notice_days' => 2, 'sms_notice' => true]);
        $connection = $this->paidConnection();

        $this->runAt('2026-10-14 09:59:00'); // suspension 16 Oct 10:00: notice window opens 14 Oct 10:00
        $this->assertNull($connection->fresh()->expiry_notice_at);
        $this->runAt('2026-10-14 10:00:00');
        $this->runAt('2026-10-14 10:05:00');
        $connection->refresh();
        $this->assertSame('2026-10-14 10:00:00', $connection->expiry_notice_at->toDateTimeString());
        $this->assertSame(1, ConnectionHistory::where('connection_id', $connection->id)->where('action', 'expiry_notice')->count());

        // a renewal is a new paid time: it gets its own notice
        $this->api('/isp/payment', ['customer_id' => $connection->customer_id, 'amount' => 1000, 'method' => 'cash', 'payment_date' => '2026-10-14'])->assertOk();
        $this->assertSame('2026-11-15 10:00:00', $connection->fresh()->expire_at->toDateTimeString());
        $this->runAt('2026-11-14 10:00:00');
        $this->assertSame(2, ConnectionHistory::where('connection_id', $connection->id)->where('action', 'expiry_notice')->count());
    }

    public function test_required_notice_postpones_the_suspension(): void
    {
        $connection = $this->paidConnection();
        // switched on late: the line would be due at 10:00 but has had no notice
        $this->settings(['notice_days' => 2, 'notice_required' => true]);
        $this->runAt('2026-10-15 10:30:00');
        $connection->refresh();
        $this->assertSame('active', $connection->status);
        $this->assertSame('2026-10-15 10:30:00', $connection->expiry_notice_at->toDateTimeString());
        $this->assertSame('2026-10-17 10:30:00', OverdueService::suspendAt($connection)->toDateTimeString());

        $this->runAt('2026-10-17 10:29:00');
        $this->assertSame('active', $connection->fresh()->status);
        $this->runAt('2026-10-17 10:30:00');
        $this->assertSame('suspended', $connection->fresh()->status);

        // the settings page refuses "required" without a notice period
        $settings = $this->api('/isp/get-settings')->json();
        $this->api('/isp/settings', ['notice_days' => 0, 'notice_required' => true] + $settings)->assertStatus(422);
    }

    public function test_late_fee_once(): void
    {
        $this->settings(['late_fee_type' => 'fixed', 'late_fee_amount' => '50', 'late_fee_after_days' => 5]);
        $connection = $this->paidConnection(pay: false); // bill of 1000 due 15 Sep, never paid
        $bill = Invoice::where('connection_id', $connection->id)->firstOrFail();

        $this->runAt('2026-09-20 12:00:00');
        $this->assertEquals(1000, (float) $bill->fresh()->total);
        $this->runAt('2026-09-21 00:01:00');
        $this->runAt('2026-09-21 00:02:00');
        $bill->refresh();
        $this->assertEquals([1050.0, 1050.0, 1, 50.0], [(float) $bill->total, (float) $bill->due, $bill->late_fee_count, (float) $bill->late_fee_total]);
        $note = BillingNote::where('invoice_id', $bill->id)->sole();
        $this->assertEquals(['debit', 50.0, 0.0], [$note->type, (float) $note->amount, (float) $note->tax_amount]);
        $this->assertStringStartsWith('Late fee', $note->reason);

        // "once": nothing more a month later
        $this->runAt('2026-10-25 00:01:00');
        $this->assertSame(1, BillingNote::where('invoice_id', $bill->id)->count());
        $this->artisan('isp:ledger-check')->assertSuccessful();
    }

    public function test_monthly_percent_late_fee_is_capped_and_never_on_a_fee(): void
    {
        $this->settings(['late_fee_type' => 'percent', 'late_fee_amount' => '2', 'late_fee_after_days' => 0, 'late_fee_repeat' => 'monthly', 'late_fee_max' => 2]);
        $connection = $this->paidConnection(pay: false);
        $bill = Invoice::where('connection_id', $connection->id)->firstOrFail();

        $this->runAt('2026-09-16 00:01:00');
        $this->assertEquals(1020.0, (float) $bill->fresh()->total);
        $this->runAt('2026-10-10 00:01:00'); // under 30 days since the last fee
        $this->assertEquals(1020.0, (float) $bill->fresh()->total);
        $this->runAt('2026-10-16 00:02:00');
        $this->assertEquals(1040.0, (float) $bill->fresh()->total); // 2% of 1000 again, not of 1020
        $this->runAt('2026-11-20 00:02:00');
        $this->assertEquals(1040.0, (float) $bill->fresh()->total); // capped at 2

        // paid invoices get no fee
        $this->api('/isp/payment', ['customer_id' => $connection->customer_id, 'amount' => 1040, 'method' => 'cash', 'payment_date' => '2026-11-20'])->assertOk();
        $this->assertSame('paid', $bill->fresh()->status);
        $this->artisan('isp:ledger-check')->assertSuccessful();
    }

    public function test_postpaid_runs_on_credit_and_suspends_only_when_a_bill_is_overdue(): void
    {
        $this->settings(['postpaid_due_days' => 10]);
        $this->api('/isp/package', ['name' => 'BR Post', 'download_mbps' => 10, 'upload_mbps' => 5, 'price' => 1000, 'billing_cycle' => 'monthly', 'billing_mode' => 'postpaid'])->assertOk();
        $this->package = Package::where('name', 'BR Post')->whereNull('reseller_id')->firstOrFail();
        $this->assertTrue($this->package->isPostpaid());

        // on without paying: the first month runs on credit, the bill is due 10 days in
        $connection = $this->paidConnection(pay: false);
        $bill = Invoice::where('connection_id', $connection->id)->firstOrFail();
        $connection->refresh();
        $this->assertSame(['active', '2026-10-15 10:00:00'], [$connection->status, $connection->expire_at->toDateTimeString()]);
        $this->assertSame('2026-09-25', $bill->due_date->toDateString());
        $this->assertNotNull($bill->credit_at);
        $this->assertFalse(ConnectionService::needsPayment($connection));

        $this->runAt('2026-09-25 23:59:00');
        $this->assertSame('active', $connection->fresh()->status);
        $this->runAt('2026-09-26 00:01:00');
        $this->assertSame(['suspended', 'Overdue'], [$connection->fresh()->status, $connection->fresh()->suspension_reason]);
        $this->assertTrue(ConnectionService::needsPayment($connection->fresh()));

        // paying the overdue bill brings it back; the paid time didn't move
        $this->api('/isp/payment', ['customer_id' => $connection->customer_id, 'amount' => 1000, 'method' => 'cash', 'payment_date' => '2026-09-26'])->assertOk();
        $connection->refresh();
        $this->assertSame(['active', '2026-10-15 10:00:00'], [$connection->status, $connection->expire_at->toDateTimeString()]);

        // next period billed 3 days ahead, on credit, due 10 days into it
        $this->settings(['grace_days' => 2]);
        $this->runAt('2026-10-12 10:00:00');
        $next = Invoice::where('connection_id', $connection->id)->latest('id')->firstOrFail();
        $this->assertNotSame($bill->id, $next->id);
        $this->assertSame('2026-10-25', $next->due_date->toDateString());
        $this->assertSame('2026-11-15 10:00:00', $connection->fresh()->expire_at->toDateTimeString());
        $this->runAt('2026-10-12 10:05:00');
        $this->assertSame(2, Invoice::where('connection_id', $connection->id)->count()); // once per period

        // unpaid: stays on through the due date and the 2 grace days
        $this->runAt('2026-10-27 12:00:00');
        $this->assertSame('active', $connection->fresh()->status);
        $this->runAt('2026-10-28 00:01:00');
        $this->assertSame('suspended', $connection->fresh()->status);
        $this->api('/isp/payment', ['customer_id' => $connection->customer_id, 'amount' => 1000, 'method' => 'cash', 'payment_date' => '2026-10-28'])->assertOk();
        $this->assertSame('active', $connection->fresh()->status);
        $this->artisan('isp:ledger-check')->assertSuccessful();
    }

    public function test_first_bill_is_prorated_to_the_billing_day(): void
    {
        $this->settings(['bill_day' => 1]);
        $connection = $this->paidConnection(pay: false); // activated 15 Sep 10:00
        $bill = Invoice::with('items')->where('connection_id', $connection->id)->firstOrFail();
        // one month (15 Sep → 15 Oct, 30 days) + 17 days to 1 Nov at 1000/30 a day
        $this->assertSame([1, 17], [$bill->service_months, $bill->service_days]);
        $this->assertEquals([1000.0, 566.67], $bill->items->pluck('unit_price')->map(fn ($v) => (float) $v)->all());
        $this->assertEquals(1566.67, (float) $bill->total);

        $this->api('/isp/payment', ['customer_id' => $connection->customer_id, 'amount' => 1566.67, 'method' => 'cash', 'payment_date' => '2026-09-15'])->assertOk();
        $this->assertSame('2026-11-01 10:00:00', $connection->fresh()->expire_at->toDateTimeString());

        // later bills are whole cycles, from the billing day
        $this->runAt('2026-10-29 10:00:00');
        $next = Invoice::where('connection_id', $connection->id)->latest('id')->firstOrFail();
        $this->assertSame([1, 0, 1000.0], [$next->service_months, $next->service_days, (float) $next->total]);
        $this->api('/isp/payment', ['customer_id' => $connection->customer_id, 'amount' => 1000, 'method' => 'cash', 'payment_date' => '2026-10-29'])->assertOk();
        $this->assertSame('2026-12-01 10:00:00', $connection->fresh()->expire_at->toDateTimeString());
        $this->artisan('isp:ledger-check')->assertSuccessful();
    }

    public function test_termination_can_credit_the_unused_days(): void
    {
        $connection = $this->paidConnection(); // 1000 for 15 Sep → 15 Oct (30 days)
        Carbon::setTestNow('2026-09-25 10:00:00');
        $res = $this->api('/isp/connection-action', ['id' => $connection->id, 'action' => 'terminate', 'reason' => 'Moving away', 'credit_unused' => true])->assertOk();
        $this->assertStringContainsString('unused time credited', $res->json('message'));
        $this->assertSame('terminated', $connection->fresh()->status);
        // 20 whole days left of 30: 666.67 back as advance credit
        $this->assertEquals(-666.67, (float) Customer::find($connection->customer_id)->ledger_balance);
        $this->artisan('isp:ledger-check')->assertSuccessful();

        // off by default: nothing credited
        $other = $this->paidConnection();
        $this->api('/isp/connection-action', ['id' => $other->id, 'action' => 'terminate', 'reason' => 'Closed'])->assertOk();
        $this->assertEquals(0.0, (float) Customer::find($other->customer_id)->ledger_balance);
    }

    public function test_security_deposit_is_held_apart_then_applied_or_refunded(): void
    {
        $connection = $this->paidConnection(pay: false); // bill of 1000 due
        $customerId = $connection->customer_id;
        $receives = \Illuminate\Support\Facades\DB::table('receives')->count();
        $statusBefore = $connection->fresh()->status;

        $this->api('/isp/deposit', ['customer_id' => $customerId, 'amount' => 2000, 'method' => 'bank'])->assertStatus(422); // account needed
        $id = $this->api('/isp/deposit', ['customer_id' => $customerId, 'connection_id' => $connection->id, 'amount' => 2000, 'method' => 'cash', 'notes' => 'ONU'])->assertOk()->json('id');
        $deposit = \App\Models\CustomerDeposit::findOrFail($id);
        // in the cash book, but not in the customer's balance: the bill is still due
        $this->assertSame($receives + 1, \Illuminate\Support\Facades\DB::table('receives')->count());
        $this->assertEquals(1000.0, (float) Customer::find($customerId)->ledger_balance);
        $this->assertSame($statusBefore, $connection->fresh()->status); // a deposit doesn't pay the bill
        $this->assertEquals(2000.0, $this->api('/isp/get-customer-profile', ['id' => $customerId])->json('summary.deposit_held'));

        // applied to dues: pays the bill, no second cash-book entry
        $this->api('/isp/deposit-apply', ['id' => $id, 'amount' => 1000, 'reason' => 'Moving out'])->assertOk();
        $this->assertEquals(0.0, (float) Customer::find($customerId)->ledger_balance);
        $this->assertSame('paid', Invoice::where('connection_id', $connection->id)->first()->status);
        $this->assertSame($receives + 1, \Illuminate\Support\Facades\DB::table('receives')->count());
        $this->assertSame('deposit', \App\Models\CustomerPayment::where('reference', $deposit->deposit_no)->value('method'));

        // the rest is paid back, never more than is held
        $this->api('/isp/deposit-refund', ['id' => $id, 'amount' => 1500, 'method' => 'cash', 'reason' => 'Returned'])->assertStatus(422);
        $this->api('/isp/deposit-refund', ['id' => $id, 'amount' => 1000, 'method' => 'cash', 'reason' => 'Returned'])->assertOk();
        $deposit->refresh();
        $this->assertSame(['released', 0.0], [$deposit->status, $deposit->held]);
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('payments')->where('note', 'Security deposit refund ' . $deposit->deposit_no)->count());
        $this->artisan('isp:ledger-check')->assertSuccessful();
    }
}
