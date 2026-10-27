<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CompanyProfile;
use App\Models\Router;
use App\Models\User;
use App\Services\Network\RadiusClient;
use App\Services\Network\RadiusDriver;
use App\Services\Network\RadiusSetup;
use App\Support\SystemTasks;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Admin → System & Automation (task runs, Run now, scheduler pulse, stuck queue) and Network → RADIUS
// Setup (live login test against FreeRADIUS, installer, router commands).
class SystemAutomationTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'radius'];

    private User $admin;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::where('role', 'Superadmin')->firstOrFail();
        $this->branch = Branch::firstOrFail();
        Cache::forget('isp.scheduler_heartbeat');
        Cache::forget('isp.pending_migrations');
    }

    protected function tearDown(): void
    {
        RadiusClient::$transport = null;
        Cache::forget('isp.scheduler_heartbeat');
        parent::tearDown();
    }

    private function api(string $uri, array $data = [])
    {
        return $this->actingAs($this->admin)->withSession(['branch' => $this->branch])->postJson($uri, $data);
    }

    public function test_run_now_records_the_run_and_its_output(): void
    {
        $this->api('/isp/system-run', ['command' => 'isp:olt-poll'])->assertOk()->assertJsonPath('output', '0 OLT(s) polled');
        $run = DB::table('system_task_runs')->where('command', 'isp:olt-poll')->latest('id')->first();
        $this->assertSame(['dashboard', 0, '0 OLT(s) polled'], [$run->source, $run->exit_code, $run->output]);
        $this->assertNotNull($run->finished_at);

        $task = collect($this->api('/isp/get-system')->assertOk()->json('tasks'))->firstWhere('command', 'isp:olt-poll');
        $this->assertSame(0, $task['last']['exit_code']);
        $this->api('/isp/system-run', ['command' => 'migrate:fresh'])->assertStatus(422); // only listed tasks
        $this->assertSame(1, AuditLog::where('action', 'system.task_run')->count());
    }

    public function test_page_needs_the_system_permission(): void
    {
        \App\Models\Role::forceCreate(['name' => 'NoSystem', 'access' => json_encode(['customer']), 'status' => 'a', 'branch_id' => $this->branch->id]);
        $clerk = User::forceCreate(['name' => 'No system', 'username' => 'nosys_' . random_int(1000, 9999), 'email' => 'nosys' . random_int(1000, 9999) . '@example.test',
            'role' => 'NoSystem', 'password' => bcrypt('secret-pass'), 'status' => 'a', 'ipAddress' => '127.0.0.1', 'branch_id' => $this->branch->id]);
        $as = $this->actingAs($clerk)->withSession(['branch' => $this->branch]);
        $as->postJson('/isp/system-run', ['command' => 'isp:olt-poll'])->assertForbidden();
        $as->postJson('/isp/get-system')->assertForbidden();
        $as->postJson('/isp/radius-test')->assertForbidden();
    }

    public function test_a_silent_scheduler_raises_a_banner_until_it_ticks_again(): void
    {
        $this->assertSame([], SystemTasks::liveAlerts(), 'no pulse recorded yet: a fresh install shows the cron line on the page instead');
        Cache::forever('isp.scheduler_heartbeat', now()->subMinutes(30)->toDateTimeString());
        $this->assertSame('scheduler', SystemTasks::liveAlerts()[0]['key'] ?? null);
        $this->assertFalse($this->api('/isp/get-system')->json('scheduler.running'));
        SystemTasks::heartbeat();
        $this->assertSame([], SystemTasks::liveAlerts());
        $this->assertTrue($this->api('/isp/get-system')->json('scheduler.running'));
        $this->assertSame([], $this->api('/isp/get-system')->json('migrations.pending'));
    }

    public function test_jobs_waiting_with_no_worker_count_as_stuck(): void
    {
        config(['queue.default' => 'database']);
        $this->assertFalse(SystemTasks::queueStuck());
        DB::table('jobs')->insert(['queue' => 'sms', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => time() - 600, 'created_at' => time() - 600]);
        $this->assertTrue(SystemTasks::queueStuck());
    }

    // a FreeRADIUS stand-in that checks the request like the real one: Message-Authenticator, then the password
    private function fakeFreeRadius(callable $users): array
    {
        $seen = [];
        RadiusClient::$transport = function ($host, $port, $packet) use ($users, &$seen) {
            $secret = CompanyProfile::first()->radius_self_secret;
            $head = unpack('Ccode/Cid/nlength', substr($packet, 0, 4));
            $auth = substr($packet, 4, 16);
            $attrs = [];
            for ($o = 20; $o < strlen($packet);) {
                $t = ord($packet[$o]);
                $l = ord($packet[$o + 1]);
                $attrs[$t] = ['offset' => $o, 'value' => substr($packet, $o + 2, $l - 2)];
                $o += $l;
            }
            $zeroed = substr_replace($packet, str_repeat("\0", 16), $attrs[80]['offset'] + 2, 16);
            if (! hash_equals(hash_hmac('md5', $zeroed, $secret, true), $attrs[80]['value'])) {
                return null; // FreeRADIUS drops it
            }
            $password = '';
            $prev = $auth;
            foreach (str_split($attrs[2]['value'], 16) as $block) {
                $password .= $block ^ md5($secret . $prev, true);
                $prev = $block;
            }
            $user = $attrs[1]['value'];
            $seen[] = [$host, $port, $user];
            $code = $users($user, rtrim($password, "\0")) ? 2 : 3;
            $body = '';
            $resp = pack('CCn', $code, $head['id'], 20 + strlen($body));
            return $resp . md5($resp . $auth . $body . $secret, true) . $body;
        };
        return $seen;
    }

    public function test_radius_login_test_goes_through_freeradius_and_cleans_up(): void
    {
        $this->api('/isp/radius-test')->assertStatus(422)->assertJsonPath('message', 'Enter the FreeRADIUS server address first.');
        $this->api('/isp/radius-server', ['radius_server' => '10.20.0.5', 'radius_auth_port' => 1812, 'radius_self_ip' => '10.20.0.2'])->assertOk();

        // FreeRADIUS reading the same table: accepts exactly the user/password in radcheck
        $this->fakeFreeRadius(fn ($u, $p) => RadiusDriver::db()->table('radcheck')->where('username', $u)->where('attribute', 'Cleartext-Password')->where('value', $p)->exists());
        $this->api('/isp/radius-test')->assertOk()->assertJsonPath('message', fn ($m) => str_starts_with($m, 'FreeRADIUS accepted a test login'));
        $this->assertSame(0, RadiusDriver::db()->table('radcheck')->where('username', 'like', 'isp-selftest-%')->count(), 'the test user is removed');
        $client = RadiusDriver::db()->table('nas')->where('shortname', RadiusSetup::SELF_TEST_NAS)->sole();
        $this->assertSame('10.20.0.2', $client->nasname);
        $this->assertNotSame('', CompanyProfile::first()->radius_self_secret);

        // a FreeRADIUS on another database rejects; one that is down doesn't answer
        $this->fakeFreeRadius(fn () => false);
        $this->api('/isp/radius-test')->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'rejected the test user'));
        RadiusClient::$transport = fn () => null;
        $this->api('/isp/radius-test')->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'No answer from the RADIUS server 10.20.0.5:1812'));
    }

    public function test_status_installer_and_router_commands(): void
    {
        $router = Router::create(['branch_id' => $this->branch->id, 'name' => 'BRAS-1', 'driver' => 'radius', 'nas_type' => 'mikrotik', 'host' => '10.20.0.1', 'coa_port' => 3799, 'is_active' => true]);
        $router->radius_secret = 's3cr3t-nas';
        $router->save();

        $status = $this->api('/isp/get-radius-setup')->assertOk()->json();
        $this->assertSame([], $status['missing_tables']);
        $row = collect($status['routers'])->firstWhere('id', $router->id);
        $this->assertFalse($row['registered']);
        $this->assertStringNotContainsString('s3cr3t-nas', json_encode($status));

        $this->api('/isp/radius-register-all')->assertOk();
        $this->assertTrue(collect($this->api('/isp/get-radius-setup')->json('routers'))->firstWhere('id', $router->id)['registered']);

        $lines = $this->api('/isp/radius-reveal', ['what' => 'router', 'id' => $router->id])->assertOk()->json('lines');
        $this->assertStringContainsString('secret="s3cr3t-nas"', $lines[0]);
        $this->assertSame('/radius incoming set accept=yes port=3799', $lines[1]);
        $this->assertSame(1, AuditLog::where('action', 'radius.secret_viewed')->count());

        $plain = $this->actingAs($this->admin)->withSession(['branch' => $this->branch])->get('/isp/radius-installer')->assertOk()->getContent();
        $this->assertStringContainsString('DB_PASS="PUT-THE-DATABASE-PASSWORD-HERE"', $plain);
        $this->assertStringContainsString('read_clients = yes', $plain);
        $this->assertSame(0, AuditLog::where('action', 'radius.installer_viewed')->count());
        $this->actingAs($this->admin)->withSession(['branch' => $this->branch])->get('/isp/radius-installer?password=1')->assertOk();
        $this->assertSame(1, AuditLog::where('action', 'radius.installer_viewed')->count());
    }
}
