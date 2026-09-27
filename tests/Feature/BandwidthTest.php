<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;
use App\Services\Isp\BandwidthService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Bandwidth bought vs sold, and revenue minus the (prorated) bandwidth bill per month.
class BandwidthTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
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

    public function test_purchases_usage_and_profit(): void
    {
        $before = BandwidthService::usage($this->branch->id);
        $profitBefore = collect(BandwidthService::profit($this->branch->id, Carbon::parse('2026-08-01'), Carbon::parse('2026-09-01'))['months'])->keyBy('month');

        $this->api('/isp/bandwidth', ['provider' => 'T IIG', 'type' => 'iig', 'bandwidth_mbps' => 100, 'monthly_cost' => 3000, 'start_date' => '2026-08-17'])->assertOk();
        $this->api('/isp/bandwidth', ['provider' => 'T Old', 'type' => 'nttn', 'bandwidth_mbps' => 50, 'monthly_cost' => 1000, 'start_date' => '2026-01-01', 'end_date' => '2026-08-31'])->assertOk();
        $this->api('/isp/bandwidth', ['provider' => 'T Bad', 'type' => 'iig', 'bandwidth_mbps' => 10, 'monthly_cost' => 1, 'start_date' => '2026-09-10', 'end_date' => '2026-09-01'])->assertStatus(422);

        // an active 20 Mbps connection, paid
        $areaId = $this->api('/area', ['name' => 'BW Area'])->json('id');
        $this->api('/isp/package', ['name' => 'BW 20', 'download_mbps' => 20, 'upload_mbps' => 10, 'price' => 1000, 'billing_cycle' => 'monthly'])->assertOk();
        $packageId = DB::table('packages')->where('name', 'BW 20')->value('id');
        $this->api('/customer', ['name' => 'BW Customer', 'phone' => '01799999401', 'area_id' => $areaId])->assertOk();
        $customer = Customer::where('phone', '01799999401')->firstOrFail();
        $this->api('/isp/payment', ['customer_id' => $customer->id, 'amount' => 1000, 'method' => 'cash', 'payment_date' => '2026-09-15'])->assertOk();
        $this->api('/isp/connection', ['customer_id' => $customer->id, 'package_id' => $packageId, 'connection_type' => 'pppoe', 'pppoe_username' => 'bw_user', 'activate_now' => true])->assertOk();

        $usage = $this->api('/isp/get-bandwidth-usage')->assertOk()->json();
        $this->assertEquals($before['purchased_mbps'] + 100, $usage['purchased_mbps']); // the ended one is not counted
        $this->assertEquals($before['sold_mbps'] + 20, $usage['sold_mbps']);
        $this->assertEquals($before['monthly_cost'] + 3000, $usage['monthly_cost']);

        $profit = collect($this->api('/isp/get-bandwidth-profit', ['from' => '2026-08', 'to' => '2026-09'])->assertOk()->json('months'))->keyBy('month');
        // August: old 1000 full month + new 3000 x 15/31 days
        $this->assertEqualsWithDelta($profitBefore['2026-08']['cost'] + 1000 + 1451.61, $profit['2026-08']['cost'], 0.02);
        // September: new 3000 full; revenue includes the 1000 bill
        $this->assertEqualsWithDelta($profitBefore['2026-09']['cost'] + 3000, $profit['2026-09']['cost'], 0.02);
        $this->assertEqualsWithDelta($profitBefore['2026-09']['revenue'] + 1000, $profit['2026-09']['revenue'], 0.02);
        $this->assertEquals(round($profit['2026-09']['revenue'] - $profit['2026-09']['cost'], 2), $profit['2026-09']['profit']);

        foreach (['/isp/bandwidth', '/isp/bandwidth-usage', '/isp/bandwidth-profit'] as $uri) {
            $this->actingAs($this->admin)->withSession(['branch' => $this->branch])->get($uri)->assertOk();
        }
        $id = DB::table('bandwidth_purchases')->where('provider', 'T Old')->value('id');
        $this->api('/isp/delete-bandwidth', ['id' => $id])->assertOk();
    }
}
