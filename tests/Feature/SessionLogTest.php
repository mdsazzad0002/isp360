<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CompanyProfile;
use App\Models\Customer;
use App\Models\Router;
use App\Models\SessionLog;
use App\Models\User;
use App\Services\Isp\IspSettings;
use App\Services\Isp\SessionLogService;
use App\Services\Network\RadiusDriver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// Lawful session log (roadmap 2.6): recorded from RADIUS and MikroTik, searchable by IP + time,
// exported with an audited reason, pruned to the retention period.
class SessionLogTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'radius'];

    private User $admin;
    private Branch $branch;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        config(['isp.network_driver' => \App\Services\Network\NullNetworkDriver::class]);
        $this->admin = User::where('role', 'Superadmin')->firstOrFail();
        $this->branch = Branch::firstOrFail();
        Carbon::setTestNow('2026-10-01 12:00:00');
        Cache::forget(SessionLogService::RADIUS_CURSOR);
        CompanyProfile::query()->update(['country_code' => 'BD', 'log_retention_days' => 365]);
        clearCompanyCache();

        $areaId = $this->api('/area', ['name' => 'SL Area'])->json('id');
        $this->api('/isp/package', ['name' => 'SL 10', 'download_mbps' => 10, 'upload_mbps' => 5, 'price' => 500, 'billing_cycle' => 'monthly'])->assertOk();
        $package = \App\Models\Package::where('name', 'SL 10')->firstOrFail();
        $this->api('/customer', ['name' => 'SL Customer', 'phone' => '01712345699', 'area_id' => $areaId])->assertOk();
        $this->customer = Customer::where('phone', '01712345699')->firstOrFail();
        $this->api('/isp/connection', ['customer_id' => $this->customer->id, 'package_id' => $package->id, 'connection_type' => 'pppoe', 'pppoe_username' => 'sl_user', 'activate_now' => true])->assertOk();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::forget(SessionLogService::RADIUS_CURSOR);
        IspSettings::flush();
        clearCompanyCache();
        parent::tearDown();
    }

    private function api(string $uri, array $data = [])
    {
        return $this->actingAs($this->admin)->withSession(['branch' => $this->branch])->postJson($uri, $data);
    }

    private function radacct(string $uid, string $user, string $ip, string $start, ?string $stop = null): void
    {
        RadiusDriver::db()->table('radacct')->insert([
            'acctsessionid' => 'S' . $uid, 'acctuniqueid' => $uid, 'username' => $user, 'nasipaddress' => '10.9.9.1',
            'acctstarttime' => $start, 'acctupdatetime' => $start, 'acctstoptime' => $stop, 'framedipaddress' => $ip,
            'callingstationid' => 'AA:BB:CC:00:11:22', 'acctinputoctets' => 1000, 'acctoutputoctets' => 9000,
        ]);
    }

    public function test_radius_sessions_are_recorded_and_updated(): void
    {
        Router::create(['branch_id' => $this->branch->id, 'name' => 'BRAS', 'driver' => 'radius', 'host' => '10.9.9.1', 'nas_type' => 'mikrotik', 'radius_secret' => 's', 'is_active' => true]);
        $this->radacct('u1', 'sl_user', '100.64.0.5', '2026-10-01 11:00:00');
        $this->radacct('u2', 'stranger', '100.64.0.6', '2026-10-01 11:30:00');

        $this->artisan('isp:session-logs')->assertSuccessful();
        $log = SessionLog::where('source_key', 'u1')->firstOrFail();
        $this->assertSame([$this->customer->id, '100.64.0.5', null, $this->branch->id], [$log->customer_id, $log->framed_ip, $log->stopped_at, $log->branch_id]);
        $this->assertNull(SessionLog::where('source_key', 'u2')->value('customer_id')); // not a billing user, still logged

        // the session ends: the next run updates the same row
        Carbon::setTestNow('2026-10-01 12:05:00');
        RadiusDriver::db()->table('radacct')->where('acctuniqueid', 'u1')->update(['acctstoptime' => '2026-10-01 12:04:00', 'acctupdatetime' => '2026-10-01 12:04:00', 'acctterminatecause' => 'User-Request']);
        $this->artisan('isp:session-logs')->assertSuccessful();
        $this->assertSame(2, SessionLog::count());
        $log->refresh();
        $this->assertSame(['2026-10-01 12:04:00', 'User-Request'], [$log->stopped_at->toDateTimeString(), $log->terminate_cause]);
    }

    public function test_mikrotik_sessions_are_polled_opened_and_closed(): void
    {
        $router = Router::create(['branch_id' => $this->branch->id, 'name' => 'MT', 'driver' => 'mikrotik', 'host' => '192.168.88.1', 'username' => 'api', 'password' => 'x', 'is_active' => true]);
        IspSettings::save($this->branch->id, ['session_log_mikrotik' => true]);
        $active = [['.id' => '*1', 'name' => 'sl_user', 'address' => '10.10.0.7', 'caller-id' => '11:22:33:44:55:66', 'uptime' => '1h30m']];
        Http::fake(function ($request) use (&$active) {
            return Http::response(str_contains($request->url(), '/ppp/active') ? $active : []);
        });

        $this->artisan('isp:session-logs')->assertSuccessful();
        Carbon::setTestNow('2026-10-01 12:05:00');
        $active[0]['uptime'] = '1h35m';
        $this->artisan('isp:session-logs')->assertSuccessful(); // same session, not a second row
        $log = SessionLog::where('source', 'mikrotik')->sole();
        $this->assertSame(['2026-10-01 10:30:00', '10.10.0.7', $this->customer->id], [$log->started_at->toDateTimeString(), $log->framed_ip, $log->customer_id]);

        $active = []; // logged off
        Carbon::setTestNow('2026-10-01 12:10:00');
        $this->artisan('isp:session-logs')->assertSuccessful();
        $this->assertSame('2026-10-01 12:10:00', $log->fresh()->stopped_at->toDateTimeString());
        $this->assertSame(5400 + 300, SessionLogService::seconds('1h35m'));
    }

    public function test_lawful_search_and_audited_export(): void
    {
        SessionLog::create(['source' => 'radius', 'source_key' => 'a', 'branch_id' => $this->branch->id, 'customer_id' => $this->customer->id, 'username' => 'sl_user', 'framed_ip' => '100.64.0.5',
            'nat_ip' => '203.0.113.9', 'nat_port_start' => 2000, 'nat_port_end' => 2999, 'started_at' => '2026-09-30 08:00:00', 'stopped_at' => '2026-09-30 20:00:00']);
        SessionLog::create(['source' => 'radius', 'source_key' => 'b', 'branch_id' => $this->branch->id, 'username' => 'other', 'framed_ip' => '100.64.0.5',
            'nat_ip' => '203.0.113.9', 'nat_port_start' => 3000, 'nat_port_end' => 3999, 'started_at' => '2026-09-30 21:00:00']);

        // who had public 203.0.113.9 port 2500 at 12:00 on 30 Sep
        $rows = $this->api('/isp/get-session-log', ['ip' => '203.0.113.9', 'port' => 2500, 'from' => '2026-09-30 12:00', 'to' => '2026-09-30 12:00'])->assertOk()->json('data');
        $this->assertSame(['sl_user'], array_column($rows, 'username'));
        // the private IP later belonged to someone else (still online)
        $rows = $this->api('/isp/get-session-log', ['ip' => '100.64.0.5', 'from' => '2026-10-01 00:00', 'to' => '2026-10-01 01:00'])->json('data');
        $this->assertSame(['other'], array_column($rows, 'username'));

        $this->api('/isp/session-log-export', ['ip' => '100.64.0.5'])->assertStatus(422); // reason required
        $this->api('/isp/session-log-export', ['reason' => 'x'])->assertStatus(422); // no filter = no bulk dump
        $csv = $this->api('/isp/session-log-export', ['ip' => '203.0.113.9', 'port' => 2500, 'reason' => 'Police request #123'])->assertOk()->streamedContent();
        $this->assertStringContainsString('sl_user,' . $this->customer->code . ',"SL Customer"', $csv);
        $this->assertDatabaseHas('audit_logs', ['action' => 'session_log.exported', 'reason' => 'Police request #123']);
    }

    public function test_retention_prunes_and_respects_the_legal_minimum(): void
    {
        SessionLog::create(['source' => 'radius', 'source_key' => 'old', 'username' => 'x', 'started_at' => '2025-01-01', 'stopped_at' => '2025-01-02']);
        SessionLog::create(['source' => 'radius', 'source_key' => 'new', 'username' => 'x', 'started_at' => '2026-09-01', 'stopped_at' => '2026-09-02']);
        $this->artisan('isp:session-logs --prune')->assertSuccessful();
        $this->assertSame(['new'], SessionLog::pluck('source_key')->all());

        // India requires 2 years: shorter is refused, the pack applies 730
        CompanyProfile::query()->update(['country_code' => 'IN']);
        clearCompanyCache();
        $settings = $this->api('/isp/get-settings')->json();
        $this->assertSame(730, $settings['log_retention_minimum']);
        $this->api('/isp/settings', ['log_retention_days' => 400] + $settings)->assertStatus(422);
        $this->api('/isp/settings', ['log_retention_days' => 0] + $settings)->assertOk(); // forever is fine
        $this->assertSame(0, (int) CompanyProfile::first()->log_retention_days);
    }
}
