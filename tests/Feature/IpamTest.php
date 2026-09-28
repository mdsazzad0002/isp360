<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Connection;
use App\Models\Customer;
use App\Models\IpPool;
use App\Models\Router;
use App\Models\SessionLog;
use App\Models\User;
use App\Services\Isp\IpamService;
use App\Services\Isp\SessionLogService;
use App\Services\Network\RadiusDriver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

// IP address management (roadmap 4.6): static pools, IPv6 prefix delegation, deterministic CGNAT.
class IpamTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'radius'];

    private User $admin;
    private Branch $branch;
    private int $areaId;
    private int $packageId;
    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['isp.network_driver' => \App\Services\Network\NullNetworkDriver::class]);
        $this->admin = User::where('role', 'Superadmin')->firstOrFail();
        $this->branch = Branch::firstOrFail();
        IpamService::flush();
        $this->areaId = $this->api('/area', ['name' => 'IP Area'])->json('id');
        $this->api('/isp/package', ['name' => 'IP 5', 'download_mbps' => 5, 'upload_mbps' => 2, 'price' => 500, 'billing_cycle' => 'monthly'])->assertOk();
        $this->packageId = \App\Models\Package::where('name', 'IP 5')->value('id');
    }

    protected function tearDown(): void
    {
        IpamService::flush();
        Cache::forget(SessionLogService::RADIUS_CURSOR);
        parent::tearDown();
    }

    private function api(string $uri, array $data = [])
    {
        return $this->actingAs($this->admin)->withSession(['branch' => $this->branch])->postJson($uri, $data);
    }

    private function connection(array $extra = [])
    {
        $phone = '0171234' . str_pad((string) (5000 + ++$this->n), 4, '0', STR_PAD_LEFT);
        $this->api('/customer', ['name' => "IP {$this->n}", 'phone' => $phone, 'area_id' => $this->areaId])->assertOk();
        $customer = Customer::where('phone', $phone)->firstOrFail();
        return $this->api('/isp/connection', $extra + ['customer_id' => $customer->id, 'package_id' => $this->packageId, 'connection_type' => 'pppoe',
            'pppoe_username' => "ip_user_{$this->n}", 'activate_now' => true]);
    }

    private function pool(array $data): IpPool
    {
        $id = $this->api('/isp/ip-pool', $data)->assertOk()->json('id');
        return IpPool::findOrFail($id);
    }

    public function test_static_pool_hands_out_free_addresses_once(): void
    {
        $pool = $this->pool(['name' => 'Static', 'type' => 'static', 'network' => '10.20.0.0/29', 'gateway' => '10.20.0.1']);
        $this->assertSame(5, IpamService::size($pool)); // .1-.6 without the gateway
        $this->assertSame('10.20.0.2', $this->api('/isp/ip-pool-next', ['id' => $pool->id])->json('value'));

        $this->connection(['static_ip' => '10.20.0.2'])->assertOk();
        $this->assertSame('10.20.0.3', $this->api('/isp/ip-pool-next', ['id' => $pool->id])->json('value'));
        $this->connection(['static_ip' => '10.20.0.2'])->assertStatus(422)->assertJsonPath('errors.static_ip.0', fn ($m) => str_contains($m, 'already used'));

        // a terminated line frees its address
        $conn = Connection::where('static_ip', '10.20.0.2')->firstOrFail();
        $this->api('/isp/connection-action', ['id' => $conn->id, 'action' => 'terminate', 'reason' => 'x'])->assertOk();
        $this->assertSame('10.20.0.2', $this->api('/isp/ip-pool-next', ['id' => $pool->id])->json('value'));
        $this->assertSame(0, $this->api('/isp/get-ip-pools')->json('0.used'));
    }

    public function test_ipv6_prefix_delegation_reaches_radius(): void
    {
        $pool = $this->pool(['name' => 'PD', 'type' => 'ipv6_pd', 'network' => '2001:db8:100::/40', 'delegated_length' => 56]);
        $this->assertSame(65536, IpamService::size($pool));
        $this->assertSame('2001:db8:1ff:ff00::/56', IpamService::delegated($pool, 65535));
        $this->api('/isp/ip-pool', ['name' => 'Bad', 'type' => 'ipv6_pd', 'network' => '2001:db8::/56', 'delegated_length' => 48])->assertStatus(422);

        Router::where('branch_id', $this->branch->id)->update(['is_default' => false]); // a router already in the database must not win
        Router::create(['branch_id' => $this->branch->id, 'name' => 'BRAS', 'driver' => 'radius', 'host' => '10.9.9.1', 'nas_type' => 'mikrotik', 'radius_secret' => 's', 'is_active' => true, 'is_default' => true]);
        config(['isp.network_driver' => \App\Services\Network\MikroTikDriver::class]);
        $prefix = $this->api('/isp/ip-pool-next', ['id' => $pool->id])->json('value');
        $this->assertSame('2001:db8:100::/56', $prefix);
        $this->connection(['ipv6_prefix' => $prefix, 'pppoe_password' => 'pw'])->assertOk();
        $this->assertSame($prefix, RadiusDriver::db()->table('radreply')->where('username', "ip_user_{$this->n}")->where('attribute', 'Delegated-IPv6-Prefix')->value('value'));
        $this->assertSame('2001:db8:100:100::/56', $this->api('/isp/ip-pool-next', ['id' => $pool->id])->json('value'));
    }

    public function test_deterministic_cgnat_maps_both_ways_and_fills_the_session_log(): void
    {
        $pool = $this->pool(['name' => 'CGN', 'type' => 'cgnat', 'network' => '100.64.0.0/22', 'public_network' => '203.0.113.0/28', 'ports_per_user' => 2016]);
        $this->assertSame(32, IpamService::usersPerPublicIp($pool));
        $this->assertSame(['nat_ip' => '203.0.113.1', 'nat_port_start' => 3040, 'nat_port_end' => 5055], IpamService::natFor($pool, '100.64.0.33'));
        $this->assertSame('100.64.0.33', $this->api('/isp/cgnat-lookup', ['ip' => '203.0.113.1', 'port' => 4000])->assertOk()->json('private_ip'));
        $this->api('/isp/cgnat-lookup', ['ip' => '198.51.100.1', 'port' => 4000])->assertStatus(404);

        $script = $this->actingAs($this->admin)->withSession(['branch' => $this->branch])->get("/isp/cgnat-script/{$pool->id}")->assertOk()->getContent();
        $this->assertStringContainsString('add chain=srcnat src-address=100.64.0.33 protocol=tcp action=src-nat to-addresses=203.0.113.1 to-ports=3040-5055', $script);

        // a RADIUS session on a CGNAT address gets its public side in the session log
        Router::create(['branch_id' => $this->branch->id, 'name' => 'BRAS', 'driver' => 'radius', 'host' => '10.9.9.1', 'nas_type' => 'mikrotik', 'radius_secret' => 's', 'is_active' => true]);
        RadiusDriver::db()->table('radacct')->insert(['acctsessionid' => 'X', 'acctuniqueid' => 'cg1', 'username' => 'someone', 'nasipaddress' => '10.9.9.1',
            'acctstarttime' => now()->subHour(), 'acctupdatetime' => now(), 'framedipaddress' => '100.64.0.33']);
        Cache::forget(SessionLogService::RADIUS_CURSOR);
        SessionLogService::syncRadius();
        $log = SessionLog::where('source_key', 'cg1')->firstOrFail();
        $this->assertSame(['203.0.113.1', 3040, 5055], [$log->nat_ip, $log->nat_port_start, $log->nat_port_end]);
        $rows = $this->api('/isp/get-session-log', ['ip' => '203.0.113.1', 'port' => 4000])->json('data');
        $this->assertSame(['someone'], array_column($rows, 'username'));
    }
}
