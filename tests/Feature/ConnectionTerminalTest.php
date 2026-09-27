<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Router;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// Customer-page terminal: fixed commands through the router REST API (faked here).
class ConnectionTerminalTest extends TestCase
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
    }

    private function api(string $uri, array $data = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->withSession(['branch' => $this->branch])->postJson($uri, $data);
    }

    private function texts($response): string
    {
        return implode("\n", array_column($response->json('lines'), 'text'));
    }

    public function test_terminal_commands(): void
    {
        $router = Router::create(['name' => 'T Router', 'host' => '10.255.255.1', 'port' => 80, 'username' => 'u', 'password' => 'p',
            'branch_id' => $this->branch->id, 'is_active' => true, 'is_default' => false]);
        $areaId = $this->api('/area', ['name' => 'Term Area'])->json('id');
        $this->api('/isp/package', ['name' => 'Term 10', 'download_mbps' => 10, 'upload_mbps' => 5, 'price' => 500, 'billing_cycle' => 'monthly'])->assertOk();
        $this->api('/customer', ['name' => 'Term Customer', 'phone' => '01799996001'])->assertOk();
        $customerId = DB::table('customers')->where('phone', '01799996001')->value('id');
        $connId = $this->api('/isp/connection', ['customer_id' => $customerId, 'package_id' => DB::table('packages')->where('name', 'Term 10')->value('id'),
            'connection_type' => 'pppoe', 'pppoe_username' => 'term_user', 'area_id' => $areaId, 'router_id' => $router->id])->assertOk()->json('id');

        Http::fake([
            '10.255.255.1/rest/ppp/active*' => Http::response([['.id' => '*5', 'name' => 'term_user', 'address' => '10.10.0.7', 'uptime' => '1h2m', 'caller-id' => 'AA:BB:CC:DD:EE:FF']]),
            '10.255.255.1/rest/ping' => Http::response([
                ['seq' => '0', 'host' => '10.10.0.7', 'size' => '56', 'ttl' => '64', 'time' => '2ms', 'sent' => '1', 'received' => '1', 'packet-loss' => '0'],
                ['seq' => '1', 'host' => '10.10.0.7', 'size' => '56', 'ttl' => '64', 'time' => '3ms', 'sent' => '2', 'received' => '2', 'packet-loss' => '0', 'min-rtt' => '2ms', 'avg-rtt' => '2ms', 'max-rtt' => '3ms'],
            ]),
            '10.255.255.1/rest/ppp/secret*' => Http::response([['.id' => '*1', 'name' => 'term_user', 'password' => 'topsecret', 'profile' => 'default']]),
            '10.255.255.1/rest/tool/bandwidth-test' => Http::response([
                ['.section' => '0', 'status' => 'running', 'duration' => '1s', 'tx-current' => '9000000', 'rx-current' => '4000000'],
                ['.section' => '1', 'status' => 'done testing', 'duration' => '2s', 'tx-current' => '9500000', 'rx-current' => '4100000', 'tx-total-average' => '9250000', 'rx-total-average' => '4050000', 'local-cpu-load' => '12'],
            ]),
            '10.255.255.1/*' => Http::response([]),
        ]);

        // RouterOS syntax pasted as-is -> bandwidth test from the router, capped duration
        $bt = $this->texts($this->api('/isp/connection-terminal', ['id' => $connId, 'command' => '/tool bandwidth-test address=10.10.0.7 user=admin password=s3cret direction=both duration=60s']));
        $this->assertStringContainsString('done testing', $bt);
        $this->assertStringContainsString('9.25 Mbps', $bt);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/rest/tool/bandwidth-test') && $r['address'] === '10.10.0.7' && $r['duration'] === '15s' && $r['password'] === 's3cret');
        $this->assertStringNotContainsString('s3cret', (string) DB::table('audit_logs')->where('action', 'terminal.btest')->latest('id')->value('new_values'));
        $this->assertStringContainsString('must be an IP', $this->texts($this->api('/isp/connection-terminal', ['id' => $connId, 'command' => 'btest example.com'])));
        // no address / login: session IP + the connection's own username/password; graph data returned
        DB::table('connections')->where('id', $connId)->update(['pppoe_password' => \Illuminate\Support\Facades\Crypt::encryptString('conn-pass')]);
        $res = $this->api('/isp/connection-terminal', ['id' => $connId, 'command' => 'btest <ip> user=<user> password=<password> duration=3']);
        $chart = collect($res->json('lines'))->firstWhere('chart')['chart'] ?? null;
        $this->assertEquals('btest', $chart['type'] ?? null);
        $this->assertCount(2, $chart['points']);
        $this->assertEquals(10, $chart['package_mbps']['down']);
        $this->assertStringContainsString("connection's own username/password", $this->texts($res));
        $this->assertStringNotContainsString('conn-pass', $this->texts($res));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/rest/tool/bandwidth-test') && $r['address'] === '10.10.0.7' && $r['user'] === 'term_user' && $r['password'] === 'conn-pass');

        $this->assertStringContainsString('ping [ip|host]', $this->texts($this->api('/isp/connection-terminal', ['id' => $connId, 'command' => 'help'])->assertOk()));
        $this->assertStringContainsString('ONLINE', $this->texts($this->api('/isp/connection-terminal', ['id' => $connId, 'command' => 'session'])));
        // ping with no target uses the session IP
        $ping = $this->texts($this->api('/isp/connection-terminal', ['id' => $connId, 'command' => 'ping']));
        $this->assertStringContainsString('PING 10.10.0.7', $ping);
        $this->assertStringContainsString('0% loss', $ping);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/rest/ping') && $r['address'] === '10.10.0.7' && $r['count'] === '4');
        // bad targets and unknown commands are refused, nothing reaches a shell
        $this->assertStringContainsString('IP address or host name', $this->texts($this->api('/isp/connection-terminal', ['id' => $connId, 'command' => 'ping 1.1.1.1;reboot'])));
        $this->assertStringContainsString('Unknown command', $this->texts($this->api('/isp/connection-terminal', ['id' => $connId, 'command' => 'rm -rf /'])));
        $this->assertStringContainsString('No connection with username', $this->texts($this->api('/isp/connection-terminal', ['id' => $connId, 'command' => 'session nobody_here'])));

        // superadmin sees the router password; staff without connectionSecret/router/action don't get it
        $this->assertStringContainsString('topsecret', $this->texts($this->api('/isp/connection-terminal', ['id' => $connId, 'command' => 'secret'])));
        $role = \App\Models\Role::create(['name' => 'T Terminal Staff', 'access' => json_encode(['connection'])]);
        $staff = User::create(['name' => 'T Term Staff', 'username' => 't_term_' . uniqid(), 'role' => $role->name, 'branch_id' => $this->branch->id, 'ipAddress' => '127.0.0.1']);
        $this->assertStringNotContainsString('topsecret', $this->texts($this->api('/isp/connection-terminal', ['id' => $connId, 'command' => 'secret'], $staff)));
        $this->assertStringContainsString('permission', $this->texts($this->api('/isp/connection-terminal', ['id' => $connId, 'command' => 'kick'], $staff)));
        $this->assertStringContainsString('permission', $this->texts($this->api('/isp/connection-terminal', ['id' => $connId, 'command' => 'ros /ppp/secret'], $staff)));
        $this->assertStringContainsString('permission', $this->texts($this->api('/isp/connection-terminal', ['id' => $connId, 'command' => 'btest 10.10.0.7'], $staff)));
        Http::assertNotSent(fn ($r) => $r->method() === 'DELETE');

        // kick with permission removes the live session and is audited
        $this->api('/isp/connection-terminal', ['id' => $connId, 'command' => 'kick'])->assertOk();
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), '/rest/ppp/active/*5'));
        $this->assertTrue(DB::table('audit_logs')->where('action', 'terminal.kick')->exists());

        $this->actingAs($this->admin)->withSession(['branch' => $this->branch])->get("/isp/customer/{$customerId}?terminal={$connId}")->assertOk();
    }

    // Billing status and router sync status are separate: "active" is not "on the router".
    public function test_router_sync_status(): void
    {
        $router = Router::create(['name' => 'S Router', 'host' => '10.255.255.2', 'port' => 80, 'username' => 'u', 'password' => 'p',
            'branch_id' => $this->branch->id, 'is_active' => true, 'is_default' => false]);
        $areaId = $this->api('/area', ['name' => 'Sync Area'])->json('id');
        $this->api('/isp/package', ['name' => 'Sync 10', 'download_mbps' => 10, 'upload_mbps' => 5, 'price' => 500, 'billing_cycle' => 'monthly'])->assertOk();
        $packageId = DB::table('packages')->where('name', 'Sync 10')->value('id');
        $this->api('/customer', ['name' => 'Sync Customer', 'phone' => '01799996002'])->assertOk();
        $customerId = DB::table('customers')->where('phone', '01799996002')->value('id');
        $make = fn ($user, $extra = []) => $this->api('/isp/connection', array_merge(['customer_id' => $customerId, 'package_id' => $packageId, 'area_id' => $areaId,
            'connection_type' => 'pppoe', 'pppoe_username' => $user, 'router_id' => $router->id, 'activate_now' => true], $extra))->assertOk()->json('id');
        $status = fn ($id) => DB::table('connections')->where('id', $id)->value('network_sync_status');

        // no driver (manual mode) -> not managed, never "synced"
        $manual = $make('sync_manual');
        $this->assertEquals('active', DB::table('connections')->where('id', $manual)->value('status'));
        $this->assertEquals('not_managed', $status($manual));
        // static IP is not pushed either
        config(['isp.network_driver' => \App\Services\Network\MikroTikDriver::class]);
        $static = $make('', ['connection_type' => 'static', 'static_ip' => '10.20.0.9', 'pppoe_username' => null]);
        $this->assertEquals('not_managed', $status($static));

        // one fake whose answer depends on $routerState (Http::fake stubs stack, first match wins)
        $routerState = 'refuse';
        $profile = 'isp-pkg-' . $packageId;
        $failedId = null;
        Http::fake(function ($request) use (&$routerState, &$failedId, $profile) {
            if ($routerState === 'refuse') {
                return Http::response(['detail' => 'not allowed'], 500);
            }
            if (str_contains($request->url(), '/rest/ppp/secret')) {
                return Http::response([['.id' => '*9', 'name' => 'sync_failed', 'disabled' => $routerState === 'disabled' ? 'true' : 'false', 'profile' => $profile, 'comment' => "isp-conn:{$failedId}"]]);
            }
            return Http::response([]);
        });

        // router refuses -> failed, while billing still says active
        $failed = $make('sync_failed');
        $failedId = $failed;
        $this->assertEquals('active', DB::table('connections')->where('id', $failed)->value('status'));
        $this->assertEquals('failed', $status($failed));
        $this->assertStringContainsString('not allowed', DB::table('connections')->where('id', $failed)->value('network_sync_error'));

        // router has the account but disabled -> verify says mismatch
        $routerState = 'disabled';
        $res = $this->api('/isp/connection-verify', ['id' => $failed])->assertOk();
        $this->assertEquals('mismatch', $res->json("results.{$failed}.status"));
        $this->assertEquals('mismatch', $status($failed));
        $this->assertStringContainsString('DISABLED', DB::table('connections')->where('id', $failed)->value('network_sync_note'));

        // router matches -> synced
        $routerState = 'enabled';
        $this->api('/isp/connection-verify', ['ids' => [$failed, $static]])->assertOk();
        $this->assertEquals('synced', $status($failed));
        $this->assertNotNull(DB::table('connections')->where('id', $failed)->value('network_checked_at'));
        $this->assertEquals('not_managed', $status($static));

        // list: filter by sync status and counts of active connections per sync status
        $list = $this->api('/isp/get-connections', ['syncStatus' => 'not_managed', 'customerId' => $customerId])->assertOk();
        $this->assertEqualsCanonicalizing([$manual, $static], array_column($list->json('data'), 'id'));
        $this->assertArrayHasKey('synced', $list->json('syncCounts'));
    }
}
