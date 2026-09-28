<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Role;
use App\Models\Router;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// Router live monitor (roadmap 4.6, monitoring step 1): readings through the router REST API (faked).
class RouterMonitorTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private Branch $branch;
    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();
        config(['isp.network_driver' => \App\Services\Network\NullNetworkDriver::class]);
        $this->admin = User::where('role', 'Superadmin')->firstOrFail();
        $this->branch = Branch::firstOrFail();
        $this->router = Router::create(['name' => 'M Router', 'host' => '10.255.255.9', 'port' => 80, 'username' => 'u', 'password' => 'p',
            'branch_id' => $this->branch->id, 'is_active' => true, 'is_default' => false]);
    }

    private function api(string $uri, array $data = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->withSession(['branch' => $this->branch])->postJson($uri, $data);
    }

    private function fakeRouter(): void
    {
        Http::fake([
            // first match wins: the command before the /interface menu
            '10.255.255.9/rest/interface/monitor-traffic' => fn ($r) => $r['interface'] === 'ether1'
                ? Http::response([['name' => 'ether1', 'rx-bits-per-second' => '95000000', 'tx-bits-per-second' => '12000000']])
                : Http::response(['detail' => 'no such item'], 400),
            '10.255.255.9/rest/interface*' => Http::response([
                ['name' => 'ether1', 'type' => 'ether', 'running' => 'true', 'disabled' => 'false', 'rx-byte' => '1000', 'tx-byte' => '2000', 'link-downs' => '3', 'comment' => 'WAN'],
                ['name' => 'ether5', 'type' => 'ether', 'running' => 'false', 'disabled' => 'true', 'rx-byte' => '0', 'tx-byte' => '0', 'link-downs' => '0'],
                ['name' => '<pppoe-someone>', 'type' => 'pppoe-in', 'running' => 'true'],
            ]),
            '10.255.255.9/rest/system/resource' => Http::response(['cpu-load' => '37', 'cpu-count' => '4', 'total-memory' => '1073741824', 'free-memory' => '268435456',
                'total-hdd-space' => '134217728', 'free-hdd-space' => '67108864', 'uptime' => '3d4h', 'version' => '7.15.3', 'board-name' => 'CCR2004', 'architecture-name' => 'arm64']),
            '10.255.255.9/rest/system/health' => Http::response(['detail' => 'no such command'], 400), // CHR / x86: no sensors
            '10.255.255.9/rest/ppp/active*' => Http::response([['.id' => '*1'], ['.id' => '*2'], ['.id' => '*3']]),
            '10.255.255.9/rest/ip/hotspot/active*' => Http::response([['.id' => '*9']]),
        ]);
    }

    public function test_overview_reads_resources_interfaces_and_online_users(): void
    {
        $this->fakeRouter();
        $res = $this->api('/isp/router-monitor', ['id' => $this->router->id])->assertOk();

        $this->assertEquals([37, 4, 1073741824, 268435456, '3d4h', 'CCR2004'], [
            $res->json('resource.cpu'), $res->json('resource.cpu_count'), $res->json('resource.memory_total'), $res->json('resource.memory_free'), $res->json('resource.uptime'), $res->json('resource.board'),
        ]);
        $this->assertSame([], $res->json('resource.health')); // a board without sensors is not an error
        $this->assertSame(4, $res->json('online'));
        // session interfaces are left out, the router is asked only for configured ones
        $this->assertSame(['ether1', 'ether5'], array_column($res->json('interfaces'), 'name'));
        $this->assertEquals([true, false, 1000, 3, 'WAN'], [$res->json('interfaces.0.running'), $res->json('interfaces.0.disabled'), $res->json('interfaces.0.rx_bytes'), $res->json('interfaces.0.link_downs'), $res->json('interfaces.0.comment')]);
        $this->assertTrue($res->json('interfaces.1.disabled'));
        Http::assertSent(fn ($r) => str_contains($r->url(), '/rest/interface?') && str_contains($r->url(), 'dynamic=false'));
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET'); // read-only
    }

    public function test_interface_traffic_in_the_chart_shape(): void
    {
        $this->fakeRouter();
        $res = $this->api('/isp/router-interface-traffic', ['id' => $this->router->id, 'interface' => 'ether1'])->assertOk();
        $this->assertEquals([true, true, 95000000, 12000000], [$res->json('managed'), $res->json('online'), $res->json('sample.down_bps'), $res->json('sample.up_bps')]);

        // an interface the router does not have is the router's error, not a crash
        $this->api('/isp/router-interface-traffic', ['id' => $this->router->id, 'interface' => 'gone'])->assertStatus(422);
        // a comma would make monitor-traffic read several interfaces
        $this->api('/isp/router-interface-traffic', ['id' => $this->router->id, 'interface' => 'ether1,ether2'])->assertStatus(422);
    }

    public function test_pins_are_saved_limited_and_audited(): void
    {
        $this->api('/isp/router-monitor-pins', ['id' => $this->router->id, 'interfaces' => ['ether1', 'sfp-sfpplus1', 'ether1']])->assertOk()->assertJsonPath('interfaces', ['ether1', 'sfp-sfpplus1']);
        $this->assertSame(['ether1', 'sfp-sfpplus1'], $this->router->fresh()->monitor_interfaces);
        $this->assertTrue(DB::table('audit_logs')->where('action', 'router.monitor_pins')->exists());

        $this->api('/isp/router-monitor-pins', ['id' => $this->router->id, 'interfaces' => ['a', 'b', 'c', 'd', 'e']])->assertStatus(422);
        $this->api('/isp/router-monitor-pins', ['id' => $this->router->id, 'interfaces' => []])->assertOk();
        $this->assertSame([], $this->router->fresh()->monitor_interfaces);

        $this->actingAs($this->admin)->withSession(['branch' => $this->branch])->get("/isp/routers/{$this->router->id}/monitor")->assertOk();
    }

    public function test_permission_branch_and_radius(): void
    {
        Http::fake();
        $role = Role::create(['name' => 'T Monitor Staff', 'access' => json_encode(['connection'])]);
        $staff = User::create(['name' => 'T Monitor Staff', 'username' => 't_mon_' . uniqid(), 'role' => $role->name, 'branch_id' => $this->branch->id, 'ipAddress' => '127.0.0.1']);
        $this->api('/isp/router-monitor', ['id' => $this->router->id], $staff)->assertStatus(403);
        $this->api('/isp/router-interface-traffic', ['id' => $this->router->id, 'interface' => 'ether1'], $staff)->assertStatus(403);
        $this->api('/isp/router-monitor-pins', ['id' => $this->router->id, 'interfaces' => ['ether1']], $staff)->assertStatus(403);

        $other = Branch::forceCreate(['code' => 'B-MON', 'name' => 'Monitor branch', 'title' => 'Mon', 'status' => 'a']);
        $foreign = Router::create(['name' => 'Foreign', 'host' => '10.255.255.10', 'username' => 'u', 'password' => 'p', 'branch_id' => $other->id, 'is_active' => true]);
        $this->api('/isp/router-monitor', ['id' => $foreign->id])->assertStatus(404);
        $this->api('/isp/router-monitor-pins', ['id' => $foreign->id, 'interfaces' => ['ether1']])->assertStatus(404);
        $this->actingAs($this->admin)->withSession(['branch' => $this->branch])->get("/isp/routers/{$foreign->id}/monitor")->assertStatus(404);

        $nas = Router::create(['name' => 'NAS', 'host' => '10.255.255.11', 'driver' => 'radius', 'nas_type' => 'mikrotik', 'branch_id' => $this->branch->id, 'is_active' => true]);
        $this->api('/isp/router-monitor', ['id' => $nas->id])->assertStatus(422);
        $this->api('/isp/router-interface-traffic', ['id' => $nas->id, 'interface' => 'ether1'])->assertOk()->assertJsonPath('managed', false);
        Http::assertNothingSent();
    }
}
