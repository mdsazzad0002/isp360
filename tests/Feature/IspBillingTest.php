<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Isp\CollectionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// End-to-end ISP flow through the HTTP layer: setup -> connection -> billing ->
// collection -> correction -> reports -> customer portal. Runs inside a transaction.
class IspBillingTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        // never touch real routers from tests
        config(['isp.network_driver' => \App\Services\Network\NullNetworkDriver::class]);
        $this->admin = User::where('role', 'Superadmin')->firstOrFail();
        $this->branch = Branch::firstOrFail();
        Carbon::setTestNow('2026-09-15 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        \App\Services\Isp\IspSettings::flush();
        parent::tearDown();
    }

    private function api(string $uri, array $data = [])
    {
        return $this->actingAs($this->admin)->withSession(['branch' => $this->branch])->postJson($uri, $data);
    }

    public function test_full_billing_cycle_keeps_ledger_consistent(): void
    {
        $this->api('/isp/zone', ['name' => 'T Zone', 'code' => 'TZ'])->assertOk();
        $zoneId = DB::table('zones')->where('name', 'T Zone')->value('id');
        $this->api('/area', ['name' => 'T Area', 'zone_id' => $zoneId])->assertOk();
        $areaId = DB::table('areas')->where('name', 'T Area')->value('id');
        $this->api('/isp/box', ['name' => 'T-Box-1', 'area_id' => $areaId, 'capacity' => 1])->assertOk();
        $boxId = DB::table('boxes')->where('name', 'T-Box-1')->value('id');
        $this->api('/delete-area', ['id' => $areaId])->assertStatus(422); // still has a box
        $this->api('/isp/delete-zone', ['id' => $zoneId])->assertStatus(422); // still has an area
        $this->api('/isp/package', ['name' => 'T 20 Mbps', 'download_mbps' => 20, 'upload_mbps' => 10, 'price' => 800, 'billing_cycle' => 'monthly', 'installation_fee' => 500])->assertOk();
        $packageId = DB::table('packages')->where('name', 'T 20 Mbps')->value('id');

        // customer with an opening due -> opening balance invoice
        $this->api('/customer', ['name' => 'T Customer', 'phone' => '01799999001', 'area_id' => $areaId, 'box_id' => $boxId, 'previous_due' => 300])->assertOk();
        $customer = Customer::where('phone', '01799999001')->firstOrFail();
        $this->assertEquals(300.0, (float) $customer->ledger_balance);
        $line = fn () => DB::table('connections')->where('pppoe_username', 't_user_1')->first(['status', 'expire_at']);

        // connection with installation invoice: the 1-month bill is issued at once; unpaid, so no time and the line stays off
        $this->api('/isp/connection', [
            'customer_id' => $customer->id, 'package_id' => $packageId, 'box_id' => $boxId, 'connection_type' => 'pppoe',
            'pppoe_username' => 't_user_1', 'pppoe_password' => 'pw', 'activate_now' => true, 'charge_installation' => true,
        ])->assertOk()->assertJsonPath('status', true);
        $this->assertEquals('suspended', $line()->status);
        $this->assertNull($line()->expire_at);

        // box is now full
        $this->api('/isp/connection', ['customer_id' => $customer->id, 'package_id' => $packageId, 'box_id' => $boxId, 'connection_type' => 'pppoe', 'pppoe_username' => 't_user_2'])
            ->assertStatus(422);
        // duplicate PPPoE username is rejected
        $this->api('/isp/connection', ['customer_id' => $customer->id, 'package_id' => $packageId, 'connection_type' => 'pppoe', 'pppoe_username' => 't_user_1'])
            ->assertStatus(422);

        // an open service bill blocks another one
        $this->api('/isp/invoice-generate')->assertOk();
        $this->assertEquals(1, Invoice::where('customer_id', $customer->id)->whereNotNull('service_months')->count());

        // 300 opening + 500 installation + 800 month
        $this->assertEquals(1600.0, (float) $customer->fresh()->ledger_balance);

        // pay 1000 cash, oldest due first: the month bill (due today) is paid -> the line runs from now for 1 month
        $pay = $this->api('/isp/payment', ['customer_id' => $customer->id, 'amount' => 1000, 'method' => 'cash', 'payment_date' => '2026-09-15'])->assertOk();
        $paymentId = $pay->json('id');
        $this->assertEquals(600.0, (float) $customer->fresh()->ledger_balance);
        $this->assertEquals('active', $line()->status);
        $this->assertEquals('2026-10-15 10:00:00', $line()->expire_at);

        // non-cash needs an account
        $this->api('/isp/payment', ['customer_id' => $customer->id, 'amount' => 10, 'method' => 'bkash', 'payment_date' => '2026-09-15'])->assertStatus(422);

        // cash book got the mirror row, and the legacy screen cannot edit it
        $receiveId = DB::table('receives')->where('customer_payment_id', $paymentId)->value('id');
        $this->assertNotNull($receiveId);
        $this->api('/delete-receive', ['id' => $receiveId])->assertStatus(422);

        // wrong payment -> reverse: the time is taken back and the line goes off at once
        $this->api('/isp/payment-reverse', ['id' => $paymentId, 'reason' => 'Wrong amount'])->assertOk();
        $this->assertEquals(1600.0, (float) $customer->fresh()->ledger_balance);
        $this->assertEquals('d', DB::table('receives')->where('id', $receiveId)->value('status'));
        $this->assertEquals('suspended', $line()->status);
        $this->assertNull($line()->expire_at);

        // full payment -> back on at once, overpayment kept as advance
        $this->api('/isp/payment', ['customer_id' => $customer->id, 'amount' => 1700, 'method' => 'cash', 'payment_date' => '2026-09-15'])->assertOk();
        $this->assertEquals('active', $line()->status);
        $this->assertEquals('2026-10-15 10:00:00', $line()->expire_at);
        $this->assertEquals(-100.0, (float) $customer->fresh()->ledger_balance);
        $this->assertEquals(100.0, CollectionService::advanceCredit($customer->id));

        // price change does not touch issued invoices
        $first = Invoice::where('customer_id', $customer->id)->whereNotNull('service_months')->firstOrFail();
        $this->api('/isp/package', ['id' => $packageId, 'name' => 'T 20 Mbps', 'download_mbps' => 20, 'upload_mbps' => 10, 'price' => 900, 'billing_cycle' => 'monthly', 'price_change_reason' => 'Test'])->assertOk();
        $this->assertEquals(800.0, (float) $first->fresh()->total);
        $this->assertEquals('2026-09-15 10:00:00', $first->fresh()->period_start->toDateTimeString());

        // renewal bill 3 days before expiry; the advance is applied automatically
        Carbon::setTestNow('2026-10-12 10:00:00');
        // (the run bills the whole branch, so count only this customer's service bills)
        $this->api('/isp/invoice-generate')->assertOk();
        $this->api('/isp/invoice-generate')->assertOk();
        $this->assertEquals(2, Invoice::where('customer_id', $customer->id)->whereNotNull('service_months')->count());
        $renewal = Invoice::where('customer_id', $customer->id)->whereNotNull('service_months')->latest('id')->firstOrFail();
        $this->assertEquals(900.0, (float) $renewal->total);
        $this->assertEquals(100.0, (float) $renewal->paid);
        $this->assertEquals('2026-10-15', $renewal->due_date->toDateString());
        $this->api('/isp/invoice-note', ['id' => $renewal->id, 'type' => 'credit', 'amount' => 5000, 'reason' => 'Too much'])->assertStatus(422);
        $this->api('/isp/invoice-note', ['id' => $renewal->id, 'type' => 'credit', 'amount' => 100, 'reason' => 'Outage'])->assertOk();
        $this->assertEquals(700.0, (float) $renewal->fresh()->due);

        // reports & pages respond
        $this->api('/isp/get-customer-profile', ['id' => $customer->id])->assertOk()->assertJsonPath('summary.balance', 700);
        $this->api('/isp/get-customer-statement', ['id' => $customer->id])->assertOk()->assertJsonPath('closing', 700);
        $this->api('/isp/get-due-report', ['mode' => 'due'])->assertOk();
        $this->api('/isp/get-due-summary', ['group' => 'box'])->assertOk();
        $this->api('/isp/get-collection-report', ['dateFrom' => '2026-09-01', 'dateTo' => '2026-10-31'])->assertOk()->assertJsonPath('reversed', 1000);
        $this->api('/isp/get-dashboard')->assertOk()->assertJsonStructure(['outstanding', 'months', 'package_wise']);
        $this->api('/isp/get-audit-log')->assertOk();
        foreach (['/isp/zones', '/isp/areas', '/isp/boxes', '/isp/packages', '/isp/connections', '/isp/invoices', '/isp/payments', '/isp/due-report', '/isp/collection-report', '/isp/settings', '/isp/audit-log', "/isp/customer/{$customer->id}"] as $uri) {
            $this->actingAs($this->admin)->withSession(['branch' => $this->branch])->get($uri)->assertOk();
        }
        $this->artisan('isp:ledger-check')->assertSuccessful();

        // no grace: off the moment the paid time ends
        Carbon::setTestNow('2026-10-15 09:59:59');
        $this->artisan('isp:process-overdue', ['--branch' => $this->branch->id])->assertSuccessful();
        $this->assertEquals('active', $line()->status);
        Carbon::setTestNow('2026-10-15 10:00:00');
        $this->artisan('isp:process-overdue', ['--branch' => $this->branch->id])->assertSuccessful();
        $this->assertEquals('suspended', $line()->status);

        // paid a day late: the new month starts at the payment, not at the old expiry
        Carbon::setTestNow('2026-10-16 12:00:00');
        $this->api('/isp/payment', ['customer_id' => $customer->id, 'amount' => 700, 'method' => 'cash', 'payment_date' => '2026-10-16'])->assertOk();
        $this->assertEquals('active', $line()->status);
        $this->assertEquals('2026-11-16 12:00:00', $line()->expire_at);

        // paid early: added on top of the time left
        Carbon::setTestNow('2026-11-14 09:00:00');
        $this->api('/isp/invoice-generate')->assertOk();
        $this->assertEquals(3, Invoice::where('customer_id', $customer->id)->whereNotNull('service_months')->count());
        $this->api('/isp/payment', ['customer_id' => $customer->id, 'amount' => 900, 'method' => 'cash', 'payment_date' => '2026-11-14'])->assertOk();
        $this->assertEquals('2026-12-16 12:00:00', $line()->expire_at);
        $early = Invoice::where('customer_id', $customer->id)->whereNotNull('service_months')->latest('id')->firstOrFail();
        $this->assertEquals('2026-11-16 12:00:00', $early->period_start->toDateTimeString());

        // voiding it takes the month back (the money returns to advance)
        $this->api('/isp/invoice-void', ['id' => $early->id, 'reason' => 'Billed by mistake'])->assertOk();
        $this->assertEquals('2026-11-16 12:00:00', $line()->expire_at);
        $this->assertEquals('active', $line()->status);
        $this->artisan('isp:ledger-check')->assertSuccessful();

        // customer portal sees only its own data
        $customer->forceFill(['username' => 't_portal_user'])->save();
        $this->actingAs($customer->fresh(), 'customer')->get('/customer-portal/dashboard')->assertOk();
        $this->actingAs($customer->fresh(), 'customer')->postJson('/customer-portal/invoice', ['id' => $renewal->id])->assertOk();
        $other = Invoice::where('customer_id', '!=', $customer->id)->value('id');
        if ($other) {
            $this->actingAs($customer->fresh(), 'customer')->postJson('/customer-portal/invoice', ['id' => $other])->assertNotFound();
        }
    }

    // A new connection is invoiced at once: the customer's advance balance pays it, the rest stays due.
    public function test_new_connection_is_invoiced_and_paid_from_customer_balance(): void
    {
        $this->api('/isp/zone', ['name' => 'NB Zone', 'code' => 'NBZ'])->assertOk();
        $this->api('/area', ['name' => 'NB Area', 'zone_id' => DB::table('zones')->where('name', 'NB Zone')->value('id')])->assertOk();
        $areaId = DB::table('areas')->where('name', 'NB Area')->value('id');
        $this->api('/isp/package', ['name' => 'NB 10', 'download_mbps' => 10, 'upload_mbps' => 5, 'price' => 600, 'billing_cycle' => 'monthly'])->assertOk();
        $packageId = DB::table('packages')->where('name', 'NB 10')->value('id');
        $line = fn ($user) => DB::table('connections')->where('pppoe_username', $user)->first(['id', 'status', 'expire_at']);

        $this->api('/customer', ['name' => 'NB Rich', 'phone' => '01799999101', 'area_id' => $areaId])->assertOk();
        $rich = Customer::where('phone', '01799999101')->firstOrFail();
        $this->api('/isp/payment', ['customer_id' => $rich->id, 'amount' => 1000, 'method' => 'cash', 'payment_date' => '2026-09-15'])->assertOk();

        // paid from the 1000 advance at once -> runs one month from now
        $this->api('/isp/connection', [
            'customer_id' => $rich->id, 'package_id' => $packageId, 'connection_type' => 'pppoe', 'pppoe_username' => 'nb_user_1', 'activate_now' => true,
        ])->assertOk()->assertJsonPath('message', fn ($m) => str_contains($m, 'Tk 600.00 paid from balance') && ! str_contains($m, 'due'));
        $this->assertEquals('paid', Invoice::where('customer_id', $rich->id)->whereNotNull('service_months')->value('status'));
        $this->assertEquals(400.0, CollectionService::advanceCredit($rich->id));
        $this->assertEquals('active', $line('nb_user_1')->status);
        $this->assertEquals('2026-10-15 10:00:00', $line('nb_user_1')->expire_at);

        // no balance: invoiced, stays due, the line waits for payment
        $this->api('/customer', ['name' => 'NB Poor', 'phone' => '01799999102', 'area_id' => $areaId])->assertOk();
        $poor = Customer::where('phone', '01799999102')->firstOrFail();
        $this->api('/isp/connection', [
            'customer_id' => $poor->id, 'package_id' => $packageId, 'connection_type' => 'pppoe', 'pppoe_username' => 'nb_user_2', 'activate_now' => true,
        ])->assertOk()->assertJsonPath('message', fn ($m) => str_contains($m, 'Tk 600.00 due'));
        $this->assertEquals(600.0, (float) $poor->fresh()->ledger_balance);
        $this->assertEquals('suspended', $line('nb_user_2')->status);

        // the time starts at the payment
        Carbon::setTestNow('2026-09-15 16:45:00');
        $pay = $this->api('/isp/payment', ['customer_id' => $poor->id, 'amount' => 600, 'method' => 'cash', 'payment_date' => '2026-09-15'])->assertOk();
        $this->assertEquals('active', $line('nb_user_2')->status);
        $this->assertEquals('2026-10-15 16:45:00', $line('nb_user_2')->expire_at);
        $this->api('/isp/payment-reverse', ['id' => $pay->json('id'), 'reason' => 'Bounced'])->assertOk();
        $this->assertEquals('suspended', $line('nb_user_2')->status);
        $this->assertNull($line('nb_user_2')->expire_at);

        // saved without activating: the invoice is shown at once; paying it early does not
        // start the clock — it starts when the line is switched on
        $this->api('/isp/connection', [
            'customer_id' => $poor->id, 'package_id' => $packageId, 'connection_type' => 'pppoe', 'pppoe_username' => 'nb_user_3',
        ])->assertOk()->assertJsonPath('message', fn ($m) => str_contains($m, 'due'));
        $pending = $line('nb_user_3');
        $this->assertEquals(1, Invoice::where('connection_id', $pending->id)->whereNotNull('service_months')->count());
        $bill = Invoice::where('connection_id', $pending->id)->firstOrFail();
        CollectionService::receive($poor->fresh(), ['amount' => 600, 'method' => 'cash'], [$bill->id => 600], false);
        $this->assertEquals('pending', $line('nb_user_3')->status);
        $this->assertNull($line('nb_user_3')->expire_at);
        Carbon::setTestNow('2026-09-20 11:00:00');
        $this->api('/isp/connection-action', ['id' => $pending->id, 'action' => 'activate'])->assertOk();
        $this->assertEquals('active', $line('nb_user_3')->status);
        $this->assertEquals('2026-10-20 11:00:00', $line('nb_user_3')->expire_at);
        $this->assertEquals(1, Invoice::where('connection_id', $pending->id)->whereNotNull('service_months')->count());

        $this->artisan('isp:ledger-check')->assertSuccessful();
    }

    // Bonus days lengthen only the first paid time. A referrer gets the commission as wallet
    // credit once the new customer's first bill is paid, and it pays the referrer's own bill.
    public function test_bonus_days_and_referral_commission(): void
    {
        \App\Services\Isp\IspSettings::save($this->branch->id, ['referral_enabled' => true, 'referral_commission_type' => 'percent', 'referral_commission' => '10']);
        $areaId = $this->api('/area', ['name' => 'RF Area'])->json('id');
        $this->api('/isp/package', ['name' => 'RF 10', 'download_mbps' => 10, 'upload_mbps' => 5, 'price' => 500, 'billing_cycle' => 'monthly'])->assertOk();
        $packageId = DB::table('packages')->where('name', 'RF 10')->value('id');
        $this->api('/customer', ['name' => 'RF Old', 'phone' => '01799999201', 'area_id' => $areaId])->assertOk();
        $this->api('/customer', ['name' => 'RF New', 'phone' => '01799999202', 'area_id' => $areaId])->assertOk();
        $old = Customer::where('phone', '01799999201')->firstOrFail();
        $new = Customer::where('phone', '01799999202')->firstOrFail();

        // the referrer's own line, unpaid for now
        $this->api('/isp/connection', ['customer_id' => $old->id, 'package_id' => $packageId, 'connection_type' => 'pppoe', 'pppoe_username' => 'rf_old', 'activate_now' => true])->assertOk();

        // can't refer yourself
        $this->api('/isp/connection', ['customer_id' => $new->id, 'package_id' => $packageId, 'connection_type' => 'pppoe', 'pppoe_username' => 'rf_x', 'referred_by_id' => $new->id])->assertStatus(422);

        $this->api('/isp/connection', [
            'customer_id' => $new->id, 'package_id' => $packageId, 'connection_type' => 'pppoe', 'pppoe_username' => 'rf_new',
            'activate_now' => true, 'bonus_days' => 7, 'referred_by_id' => $old->id,
        ])->assertOk();
        $this->assertEquals($old->id, $new->fresh()->referred_by_id);

        // the list shows the unpaid bill so it can be paid from there
        $row = collect($this->api('/isp/get-connections', ['search' => 'rf_new'])->assertOk()->json('data'))->firstWhere('pppoe_username', 'rf_new');
        $this->assertEquals(500, (float) $row['open_due']);
        $this->assertNotNull($row['open_invoice_id']);

        // first bill paid: 1 month + 7 bonus days; referrer gets 10% = 50 in the wallet, applied to their open bill
        $this->api('/isp/payment', ['customer_id' => $new->id, 'amount' => 500, 'method' => 'cash', 'payment_date' => '2026-09-15'])->assertOk();
        $this->assertEquals('2026-10-22 10:00:00', DB::table('connections')->where('pppoe_username', 'rf_new')->value('expire_at'));
        $reward = DB::table('customer_payments')->where('customer_id', $old->id)->where('method', 'referral')->first();
        $this->assertEquals(50.0, (float) $reward->amount);
        $this->assertEquals(0, DB::table('receives')->where('customer_payment_id', $reward->id)->count()); // not cash
        $this->assertEquals(450.0, (float) $old->fresh()->ledger_balance);

        // once only: the renewal adds no bonus days and no second commission
        Carbon::setTestNow('2026-10-20 10:00:00');
        $this->api('/isp/invoice-generate')->assertOk();
        $this->api('/isp/payment', ['customer_id' => $new->id, 'amount' => 500, 'method' => 'cash', 'payment_date' => '2026-10-20'])->assertOk();
        $this->assertEquals('2026-11-22 10:00:00', DB::table('connections')->where('pppoe_username', 'rf_new')->value('expire_at'));
        $this->assertEquals(1, DB::table('customer_payments')->where('customer_id', $old->id)->where('method', 'referral')->count());
        $this->artisan('isp:ledger-check')->assertSuccessful();
    }

    // Pay by cycles from the connection list: the unpaid bill first, then extra months in advance.
    public function test_pay_connection_for_several_months(): void
    {
        $areaId = $this->api('/area', ['name' => 'PM Area'])->json('id');
        $this->api('/isp/package', ['name' => 'PM 10', 'download_mbps' => 10, 'upload_mbps' => 5, 'price' => 500, 'billing_cycle' => 'monthly'])->assertOk();
        $packageId = DB::table('packages')->where('name', 'PM 10')->value('id');
        $this->api('/customer', ['name' => 'PM Customer', 'phone' => '01799999301', 'area_id' => $areaId])->assertOk();
        $customer = Customer::where('phone', '01799999301')->firstOrFail();
        $id = $this->api('/isp/connection', ['customer_id' => $customer->id, 'package_id' => $packageId, 'connection_type' => 'pppoe', 'pppoe_username' => 'pm_user', 'activate_now' => true])->json('id');
        $line = fn () => DB::table('connections')->where('id', $id)->first(['status', 'expire_at']);
        $this->assertEquals('suspended', $line()->status); // due -> no service

        // quote: the unpaid bill is the first cycle; 3 cycles = 1500 until 15 Dec
        $quote = $this->api('/isp/connection-pay-quote', ['id' => $id])->assertOk()->json();
        $this->assertCount(1, $quote['open']);
        $three = collect($quote['options'])->firstWhere('cycles', 3);
        $this->assertEquals(1500, $three['amount']);
        $this->assertEquals('2026-12-15 10:00:00', $three['until']);

        $this->api('/isp/connection-pay', ['id' => $id, 'cycles' => 3, 'method' => 'bkash'])->assertStatus(422); // needs an account
        $this->api('/isp/connection-pay', ['id' => $id, 'cycles' => 3, 'method' => 'cash'])->assertOk();
        $this->assertEquals('active', $line()->status);
        $this->assertEquals('2026-12-15 10:00:00', $line()->expire_at);
        $this->assertEquals(3, Invoice::where('connection_id', $id)->where('status', 'paid')->whereNotNull('service_months')->count());
        $this->assertEquals(0.0, (float) $customer->fresh()->ledger_balance);

        // nothing due: one more month in advance stacks on the time left
        $quote = $this->api('/isp/connection-pay-quote', ['id' => $id])->assertOk()->json();
        $this->assertCount(0, $quote['open']);
        $this->assertEquals(['cycles' => 1, 'months' => 1, 'amount' => 500, 'until' => '2027-01-15 10:00:00'], $quote['options'][0]);
        $this->api('/isp/connection-pay', ['id' => $id, 'cycles' => 1, 'method' => 'cash'])->assertOk();
        $this->assertEquals('2027-01-15 10:00:00', $line()->expire_at);
        $this->artisan('isp:ledger-check')->assertSuccessful();
    }

    public function test_staff_without_permission_cannot_reverse_payments(): void
    {
        $role = \App\Models\Role::create(['name' => 'T Collector', 'access' => json_encode(['ispPayment'])]);
        $user = User::create(['name' => 'T Collector', 'username' => 't_collector_' . uniqid(), 'role' => $role->name, 'branch_id' => $this->branch->id, 'ipAddress' => '127.0.0.1']);

        $this->actingAs($user)->withSession(['branch' => $this->branch])->postJson('/isp/payment-reverse', ['id' => 1, 'reason' => 'x x x'])->assertForbidden();
        $this->actingAs($user)->withSession(['branch' => $this->branch])->postJson('/isp/invoice-void', ['id' => 1, 'reason' => 'x x x'])->assertForbidden();
        $this->actingAs($user)->withSession(['branch' => $this->branch])->postJson('/isp/settings', [])->assertForbidden();
    }

    // Quick zone -> area -> box entry (connection form) and the connection's area rules.
    public function test_connection_needs_an_area_and_box_must_match_it(): void
    {
        $zoneId = $this->api('/isp/zone', ['name' => 'Q Zone'])->assertOk()->json('id');
        $areaA = $this->api('/area', ['name' => 'Q Area A', 'zone_id' => $zoneId])->assertOk()->json('id');
        $areaB = $this->api('/area', ['name' => 'Q Area B', 'zone_id' => $zoneId])->assertOk()->json('id');
        $this->assertEquals($zoneId, DB::table('areas')->where('id', $areaA)->value('zone_id'));
        $this->api('/area', ['name' => 'Q Area C', 'zone_id' => 99999999])->assertStatus(422);
        $boxB = $this->api('/isp/box', ['name' => 'Q-Box-B', 'area_id' => $areaB, 'capacity' => 8])->assertOk()->json('id');
        $this->assertNotNull($boxB);

        $this->api('/isp/package', ['name' => 'Q 10 Mbps', 'download_mbps' => 10, 'upload_mbps' => 5, 'price' => 500, 'billing_cycle' => 'monthly'])->assertOk();
        $packageId = DB::table('packages')->where('name', 'Q 10 Mbps')->value('id');
        $this->api('/customer', ['name' => 'Q Customer', 'phone' => '01799997001'])->assertOk();
        $customer = Customer::where('phone', '01799997001')->firstOrFail();
        $this->assertNull($customer->area_id);

        $base = ['customer_id' => $customer->id, 'package_id' => $packageId, 'connection_type' => 'pppoe'];
        // no area anywhere -> refused
        $this->api('/isp/connection', $base + ['pppoe_username' => 'q_user_1'])->assertStatus(422);
        // box from another area -> refused
        $this->api('/isp/connection', $base + ['pppoe_username' => 'q_user_1', 'area_id' => $areaA, 'box_id' => $boxB])->assertStatus(422);
        // matching area + box -> ok, and the customer without a location takes it
        $this->api('/isp/connection', $base + ['pppoe_username' => 'q_user_1', 'area_id' => $areaB, 'box_id' => $boxB])->assertOk();
        $customer->refresh();
        $this->assertEquals($areaB, $customer->area_id);
        $this->assertEquals($zoneId, $customer->zone_id);
        $this->assertEquals($boxB, $customer->box_id);
        // later connections may default to the customer's area
        $this->api('/isp/connection', $base + ['pppoe_username' => 'q_user_2'])->assertOk();
        $this->assertEquals($boxB, DB::table('connections')->where('pppoe_username', 'q_user_2')->value('box_id'));
    }
}
