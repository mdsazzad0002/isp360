<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CompanyProfile;
use App\Models\Customer;
use App\Models\Region;
use App\Models\Role;
use App\Models\User;
use App\Services\Isp\CompanyReport;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Company -> Region -> Branch, the owner dashboard and regional managers (roadmap 3.1, 3.2).
class CompanyDashboardTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private Branch $north;
    private Branch $south;
    private Branch $east;

    protected function setUp(): void
    {
        parent::setUp();
        config(['isp.network_driver' => \App\Services\Network\NullNetworkDriver::class]);
        $this->admin = User::where('role', 'Superadmin')->firstOrFail();
        $make = fn ($name) => Branch::create(['name' => $name, 'title' => $name, 'status' => 'a', 'ipAddress' => '127.0.0.1']);
        [$this->north, $this->south, $this->east] = [$make('T North'), $make('T South'), $make('T East')];
        CompanyProfile::query()->update(['multi_branch_status' => 'active']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function api(string $uri, array $data = [], ?Branch $branch = null, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->admin)->withSession(['branch' => $branch ?? $this->north])->postJson($uri, $data);
    }

    // a customer with an active, paid line in the given branch
    private function subscriber(Branch $branch, string $phone, float $price): Customer
    {
        $areaId = $this->api('/area', ['name' => "Area {$phone}"], $branch)->json('id');
        $this->api('/isp/package', ['name' => "Pkg {$phone}", 'download_mbps' => 5, 'upload_mbps' => 2, 'price' => $price, 'billing_cycle' => 'monthly'], $branch)->assertOk();
        $this->api('/customer', ['name' => "Sub {$phone}", 'phone' => $phone, 'area_id' => $areaId], $branch)->assertOk();
        $customer = Customer::where('phone', $phone)->firstOrFail();
        $this->api('/isp/connection', ['customer_id' => $customer->id, 'package_id' => DB::table('packages')->where('name', "Pkg {$phone}")->value('id'),
            'connection_type' => 'pppoe', 'pppoe_username' => "u{$phone}", 'activate_now' => true], $branch)->assertOk();
        $this->api('/isp/payment', ['customer_id' => $customer->id, 'amount' => $price, 'method' => 'cash', 'payment_date' => now()->toDateString()], $branch)->assertOk();
        return $customer;
    }

    public function test_owner_dashboard_adds_up_branches_and_regions(): void
    {
        Carbon::setTestNow('2026-09-10 10:00:00');
        $this->api('/isp/region', ['name' => 'T Upcountry', 'code' => 'UP', 'branch_ids' => [$this->north->id, $this->south->id]])->assertOk();
        $region = Region::where('name', 'T Upcountry')->firstOrFail();
        $this->assertSame([$this->north->id, $this->south->id], Branch::where('region_id', $region->id)->orderBy('id')->pluck('id')->all());

        $this->subscriber($this->north, '01712345901', 600);
        $this->subscriber($this->north, '01712345902', 600);
        $gone = $this->subscriber($this->south, '01712345903', 400);
        $this->subscriber($this->south, '01712345904', 400);
        $this->api('/isp/connection-action', ['id' => DB::table('connections')->where('customer_id', $gone->id)->value('id'), 'action' => 'terminate', 'reason' => 'moved'], $this->south)->assertOk();
        DB::table('transactions')->insert(['invoice' => 'T-EXP-1', 'type' => 'expense', 'amount' => 150, 'date' => '2026-09-05', 'status' => 'a', 'ipAddress' => '127.0.0.1', 'branch_id' => $this->north->id]);
        $this->api('/isp/bandwidth', ['provider' => 'T IIG', 'type' => 'iig', 'bandwidth_mbps' => 100, 'monthly_cost' => 365, 'start_date' => '2026-01-01'], $this->south)->assertOk(); // 12 a day

        $data = $this->api('/isp/get-company-dashboard', ['from' => '2026-09-01', 'to' => '2026-09-10', 'region_id' => $region->id])->assertOk()->json();
        $rows = collect($data['branches'])->keyBy('branch');
        $this->assertSame(['T North', 'T South'], $rows->keys()->sort()->values()->all());
        $north = $rows['T North'];
        $south = $rows['T South'];
        $this->assertSame([2, 2, 0], [$north['subscribers'], $north['new'], $north['churned']]);
        $this->assertSame([1, 2, 1, 50.0], [$south['subscribers'], $south['new'], $south['churned'], (float) $south['churn_pct']]);
        $this->assertEquals(1200, $north['collection']);
        $this->assertEquals(800, $south['collection']);
        $this->assertEquals(150, $north['expense']);
        $this->assertEquals(120, $south['bandwidth_cost']); // 10 days x 12
        $this->assertEquals($north['revenue'] - 150, $north['profit']);
        $this->assertEquals($north['billed'] - $north['tax'], $north['revenue']);
        $this->assertEquals(round($north['revenue'] / 2, 2), $north['arpu']);

        $this->assertCount(1, $data['regions']);
        $this->assertSame('T Upcountry', $data['regions'][0]['region']);
        $this->assertSame(3, $data['total']['subscribers']);
        $this->assertEquals(2000, $data['total']['collection']);
        $this->assertEquals($north['profit'] + $south['profit'], $data['total']['profit']);

        // without a region filter the admin sees every branch, East (no region) in its own group
        $all = CompanyReport::build(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-10'));
        $this->assertContains('T East', array_column($all['branches'], 'branch'));
        $this->assertContains(null, array_column($all['regions'], 'region_id'));

        $csv = $this->actingAs($this->admin)->withSession(['branch' => $this->north])
            ->get("/isp/company-dashboard-export?from=2026-09-01&to=2026-09-10&region_id={$region->id}")->assertOk()->streamedContent();
        $this->assertStringContainsString('"T Upcountry","T North",2,', $csv);
        $this->assertStringContainsString(',TOTAL,3,', $csv);
    }

    public function test_regional_manager_is_limited_to_their_region(): void
    {
        $region = Region::create(['name' => 'T Coast']);
        Branch::whereIn('id', [$this->north->id, $this->south->id])->update(['region_id' => $region->id]);
        $role = Role::create(['name' => 'T Regional', 'access' => json_encode(['companyDashboard', 'region', 'user'])]);
        $manager = User::create(['name' => 'T Regional', 'username' => 't_regional_' . uniqid(), 'role' => $role->name, 'branch_id' => $this->north->id, 'region_id' => $region->id, 'ipAddress' => '127.0.0.1']);

        $this->assertSame([$this->north->id, $this->south->id], collect($manager->allowedBranchIds())->sort()->values()->all());
        $manager->update(['switchable_branches' => (string) $this->south->id]); // narrowed further
        $this->assertSame([$this->south->id], $manager->fresh()->allowedBranchIds());
        $manager->update(['switchable_branches' => null]);

        $this->api('/switch-branch', ['branch_id' => $this->south->id], null, $manager)->assertJsonPath('status', true);
        $this->api('/switch-branch', ['branch_id' => $this->east->id], null, $manager)->assertJsonPath('status', false);

        $data = $this->api('/isp/get-company-dashboard', [], null, $manager)->assertOk()->json();
        $this->assertSame(['T North', 'T South'], collect($data['branches'])->pluck('branch')->sort()->values()->all());
        $this->assertSame('T Coast', $data['scope']);

        // cannot redraw regions or hand one out
        $this->api('/isp/region', ['id' => $region->id, 'name' => 'T Coast', 'branch_ids' => [$this->north->id, $this->south->id, $this->east->id]], null, $manager)->assertForbidden();
        $other = Region::create(['name' => 'T Hills']);
        $this->api('/update-user', ['id' => $manager->id, 'name' => 'T Regional', 'username' => $manager->username, 'phone' => '1', 'role' => $role->name, 'email' => 'r@x.test', 'region_id' => $other->id], null, $manager)->assertForbidden();
        $this->assertSame($region->id, $manager->fresh()->region_id);

        // head office can, and deleting a region frees its branches and managers
        $this->api('/isp/delete-region', ['id' => $region->id])->assertOk();
        $this->assertNull($manager->fresh()->region_id);
        $this->assertSame(0, Branch::where('region_id', $region->id)->count());
    }
}
