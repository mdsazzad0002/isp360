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

        // connection, activated mid-month, with installation invoice
        $this->api('/isp/connection', [
            'customer_id' => $customer->id, 'package_id' => $packageId, 'box_id' => $boxId, 'connection_type' => 'pppoe',
            'pppoe_username' => 't_user_1', 'pppoe_password' => 'pw', 'activate_now' => true, 'activation_date' => '2026-09-15', 'charge_installation' => true,
        ])->assertOk()->assertJsonPath('status', true);

        // box is now full
        $this->api('/isp/connection', ['customer_id' => $customer->id, 'package_id' => $packageId, 'box_id' => $boxId, 'connection_type' => 'pppoe', 'pppoe_username' => 't_user_2'])
            ->assertStatus(422);
        // duplicate PPPoE username is rejected
        $this->api('/isp/connection', ['customer_id' => $customer->id, 'package_id' => $packageId, 'connection_type' => 'pppoe', 'pppoe_username' => 't_user_1'])
            ->assertStatus(422);

        // (the run bills the whole branch, so count only this customer's period invoices)
        $this->api('/isp/invoice-generate', ['date' => '2026-09-15'])->assertOk();
        $this->assertEquals(1, Invoice::where('customer_id', $customer->id)->whereNotNull('period_start')->count());
        $this->api('/isp/invoice-generate', ['date' => '2026-09-15'])->assertOk()->assertJsonPath('stats.created', 0);

        // 300 opening + 500 installation + 426.67 prorated September
        $this->assertEquals(1226.67, (float) $customer->fresh()->ledger_balance);

        // pay 1000 cash, auto-allocated oldest first
        $pay = $this->api('/isp/payment', ['customer_id' => $customer->id, 'amount' => 1000, 'method' => 'cash', 'payment_date' => '2026-09-15'])->assertOk();
        $paymentId = $pay->json('id');
        $this->assertEquals(226.67, (float) $customer->fresh()->ledger_balance);

        // non-cash needs an account
        $this->api('/isp/payment', ['customer_id' => $customer->id, 'amount' => 10, 'method' => 'bkash', 'payment_date' => '2026-09-15'])->assertStatus(422);

        // cash book got the mirror row, and the legacy screen cannot edit it
        $receiveId = DB::table('receives')->where('customer_payment_id', $paymentId)->value('id');
        $this->assertNotNull($receiveId);
        $this->api('/delete-receive', ['id' => $receiveId])->assertStatus(422);

        // wrong payment -> reverse
        $this->api('/isp/payment-reverse', ['id' => $paymentId, 'reason' => 'Wrong amount'])->assertOk();
        $this->assertEquals(1226.67, (float) $customer->fresh()->ledger_balance);
        $this->assertEquals('d', DB::table('receives')->where('id', $receiveId)->value('status'));

        // overdue + auto-suspend after grace
        Carbon::setTestNow('2026-10-05 01:00:00');
        $this->artisan('isp:process-overdue', ['--branch' => $this->branch->id])->assertSuccessful();
        $this->assertEquals('suspended', DB::table('connections')->where('pppoe_username', 't_user_1')->value('status'));

        // full payment -> auto-reactivate, overpayment kept as advance
        $this->api('/isp/payment', ['customer_id' => $customer->id, 'amount' => 1300, 'method' => 'cash', 'payment_date' => '2026-10-05'])->assertOk();
        $this->assertEquals('active', DB::table('connections')->where('pppoe_username', 't_user_1')->value('status'));
        $this->assertEquals(-73.33, (float) $customer->fresh()->ledger_balance);
        $this->assertEquals(73.33, CollectionService::advanceCredit($customer->id));

        // price change does not touch issued invoices
        $septemberTotal = (float) Invoice::where('customer_id', $customer->id)->whereNotNull('period_start')->value('total');
        $this->api('/isp/package', ['id' => $packageId, 'name' => 'T 20 Mbps', 'download_mbps' => 20, 'upload_mbps' => 10, 'price' => 900, 'billing_cycle' => 'monthly', 'price_change_reason' => 'Test'])->assertOk();
        $this->assertEquals($septemberTotal, (float) Invoice::where('customer_id', $customer->id)->whereNotNull('period_start')->value('total'));

        // credit note on an open invoice
        $this->api('/isp/invoice-generate', ['date' => '2026-10-05'])->assertOk();
        $october = Invoice::where('customer_id', $customer->id)->where('period_start', '2026-10-01')->firstOrFail();
        $this->assertEquals(900.0, (float) $october->total);
        $this->assertEquals(73.33, (float) $october->paid); // advance applied automatically
        $this->api('/isp/invoice-note', ['id' => $october->id, 'type' => 'credit', 'amount' => 5000, 'reason' => 'Too much'])->assertStatus(422);
        $this->api('/isp/invoice-note', ['id' => $october->id, 'type' => 'credit', 'amount' => 100, 'reason' => 'Outage'])->assertOk();
        $this->assertEquals(726.67, (float) $october->fresh()->due);

        // reports & pages respond
        $this->api('/isp/get-customer-profile', ['id' => $customer->id])->assertOk()->assertJsonPath('summary.balance', 726.67);
        $this->api('/isp/get-customer-statement', ['id' => $customer->id])->assertOk()->assertJsonPath('closing', 726.67);
        $this->api('/isp/get-due-report', ['mode' => 'due'])->assertOk();
        $this->api('/isp/get-due-summary', ['group' => 'box'])->assertOk();
        $this->api('/isp/get-collection-report', ['dateFrom' => '2026-09-01', 'dateTo' => '2026-10-31'])->assertOk()->assertJsonPath('reversed', 1000);
        $this->api('/isp/get-dashboard')->assertOk()->assertJsonStructure(['outstanding', 'months', 'package_wise']);
        $this->api('/isp/get-audit-log')->assertOk();
        foreach (['/isp/zones', '/isp/areas', '/isp/boxes', '/isp/packages', '/isp/connections', '/isp/invoices', '/isp/payments', '/isp/due-report', '/isp/collection-report', '/isp/settings', '/isp/audit-log', "/isp/customer/{$customer->id}"] as $uri) {
            $this->actingAs($this->admin)->withSession(['branch' => $this->branch])->get($uri)->assertOk();
        }

        $this->artisan('isp:ledger-check')->assertSuccessful();

        // customer portal sees only its own data
        $customer->forceFill(['username' => 't_portal_user'])->save();
        $this->actingAs($customer->fresh(), 'customer')->get('/customer-portal/dashboard')->assertOk();
        $this->actingAs($customer->fresh(), 'customer')->postJson('/customer-portal/invoice', ['id' => $october->id])->assertOk();
        $other = Invoice::where('customer_id', '!=', $customer->id)->value('id');
        if ($other) {
            $this->actingAs($customer->fresh(), 'customer')->postJson('/customer-portal/invoice', ['id' => $other])->assertNotFound();
        }
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
