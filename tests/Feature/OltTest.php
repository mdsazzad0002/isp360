<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Connection;
use App\Models\Customer;
use App\Models\Olt;
use App\Models\Onu;
use App\Models\User;
use App\Services\Network\OltService;
use App\Services\Network\SnmpClient;
use App\Services\Network\SnmpOltDriver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

// OLT / ONU (roadmap 4.6): SNMP client against a fake agent that answers real BER packets, the vendor
// tables turned into ONU rows, polling into the onus table, and the OLT page's endpoints.
class OltTest extends TestCase
{
    use DatabaseTransactions;

    private const HW = '1.3.6.1.4.1.2011.6.128.1.1.2';
    private const PON_IF = 4194312192; // Huawei ifIndex of GPON 0/1/0

    private User $admin;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        config(['isp.network_driver' => \App\Services\Network\NullNetworkDriver::class]);
        $this->admin = User::where('role', 'Superadmin')->firstOrFail();
        $this->branch = Branch::firstOrFail();
        FakeSnmpAgent::$mib = [];
        Cache::forget('isp.system_alerts'); // OLT down / PON down banners live in the cache, not the database
        $this->app->bind(SnmpClient::class, fn ($app, $p) => new FakeSnmpAgent($p['host'], $p['port'], $p['community']));
    }

    protected function tearDown(): void
    {
        Cache::forget('isp.system_alerts');
        parent::tearDown();
    }

    private function api(string $uri, array $data = [])
    {
        return $this->actingAs($this->admin)->withSession(['branch' => $this->branch])->postJson($uri, $data);
    }

    private function olt(array $extra = []): Olt
    {
        $id = $this->api('/isp/olt', $extra + ['name' => 'POP OLT', 'vendor' => 'huawei', 'host' => '10.9.9.2', 'snmp_community' => 'ro-secret'])->assertOk()->json('id');
        return Olt::findOrFail($id);
    }

    // two ONUs on GPON 0/1/0: #1 online at -21.35 dBm, #2 in LOS (no power reading)
    private function huaweiMib(): void
    {
        $p = self::PON_IF;
        FakeSnmpAgent::$mib = [
            '1.3.6.1.2.1.1.1.0' => 'Huawei Integrated Access Software MA5683T',
            '1.3.6.1.2.1.1.3.0' => ['t' => 0x43, 'v' => 8640000],
            '1.3.6.1.2.1.1.5.0' => 'POP-OLT',
            "1.3.6.1.2.1.2.2.1.2.{$p}" => 'GPON 0/1/0',
            self::HW . ".43.1.3.{$p}.1" => 'HWTC' . hex2bin('1A2B3C4D'),
            self::HW . ".43.1.3.{$p}.2" => 'HWTC' . hex2bin('00000002'),
            self::HW . ".43.1.9.{$p}.1" => 'Rahim house',
            self::HW . ".46.1.15.{$p}.1" => 1,
            self::HW . ".46.1.15.{$p}.2" => 2,
            self::HW . ".46.1.20.{$p}.1" => 1830,
            self::HW . ".51.1.4.{$p}.1" => -2135,
            self::HW . ".51.1.4.{$p}.2" => 2147483647,
            '1.3.6.1.9.9.9.0' => 'after every table', // walks must stop at the end of their table
        ];
    }

    public function test_ber_encoding_round_trips(): void
    {
        foreach (['1.3.6.1.2.1.1.1.0', '1.3.6.1.4.1.2011.6.128.1.1.2.43.1.3.4194312192.17', '2.999.3'] as $oid) {
            $pos = 0;
            [, $raw] = SnmpClient::readTlv(SnmpClient::encodeOid($oid), $pos);
            $this->assertSame($oid, SnmpClient::decodeOid($raw));
        }
        foreach ([0, 1, 127, 128, 255, 256, -1, -128, -129, -2135, 2147483647, -2147483648] as $n) {
            $pos = 0;
            [$type, $raw] = SnmpClient::readTlv(SnmpClient::encodeInt($n), $pos);
            $this->assertSame($n, SnmpClient::decodeValue($type, $raw));
        }
        $this->assertSame(-1, SnmpClient::compare('1.3.6.1.2', '1.3.6.1.10'));
        $this->assertSame(1, SnmpClient::compare('1.3.6.1.2.1', '1.3.6.1.2'));
    }

    public function test_walk_reads_a_whole_table_across_bulk_pages_and_stops_at_its_end(): void
    {
        for ($i = 1; $i <= 60; $i++) {
            FakeSnmpAgent::$mib["1.3.6.1.2.1.2.2.1.2.{$i}"] = "if{$i}";
        }
        FakeSnmpAgent::$mib['1.3.6.1.2.1.2.2.1.3.1'] = 6;
        $walk = (new FakeSnmpAgent('x', 161, 'c'))->walk('1.3.6.1.2.1.2.2.1.2', 25);
        $this->assertCount(60, $walk);
        $this->assertSame('if60', $walk['1.3.6.1.2.1.2.2.1.2.60']);
    }

    public function test_vendor_values_are_converted(): void
    {
        $zte = config('olt_vendors.zte.power');
        $this->assertSame(-21.0, SnmpOltDriver::power(4500, $zte)); // 4500 * 0.002 - 30
        $this->assertNull(SnmpOltDriver::power(65535, $zte));
        $this->assertSame(-21.35, SnmpOltDriver::power(-2135, config('olt_vendors.huawei.power')));
        $this->assertSame('HWTC1A2B3C4D', SnmpOltDriver::serial('HWTC' . hex2bin('1A2B3C4D')));
        $this->assertSame('ZTEGC0FFEE01', SnmpOltDriver::serial('ZTEG-C0FFEE01'));
        $this->assertSame('00:1a:2b:3c:4d:5e', SnmpOltDriver::mac(hex2bin('001a2b3c4d5e')));
    }

    public function test_huawei_poll_fills_the_onu_list_and_keeps_the_connection_link(): void
    {
        $this->huaweiMib();
        $olt = $this->olt();
        $this->assertNotSame('ro-secret', \DB::table('olts')->where('id', $olt->id)->value('snmp_community')); // encrypted
        $this->assertArrayNotHasKey('snmp_community', $this->api('/isp/get-olts')->json('0'));

        $this->api('/isp/olt-test', ['id' => $olt->id])->assertOk()->assertJsonFragment(['message' => 'OK · POP-OLT · Huawei Integrated Access Software MA5683T · uptime 1d 00h 00m']);
        $this->api('/isp/olt-poll', ['id' => $olt->id])->assertOk()->assertJsonPath('summary.found', 2)->assertJsonPath('summary.online', 1);

        $one = Onu::where('olt_id', $olt->id)->where('serial', 'HWTC1A2B3C4D')->firstOrFail();
        $this->assertSame(['GPON 0/1/0', 1, 'online', -21.35, 1830, 'Rahim house'], [$one->pon_port, $one->onu_id, $one->status, $one->rx_power, $one->distance_m, $one->name]);
        $two = Onu::where('olt_id', $olt->id)->where('serial', 'HWTC00000002')->firstOrFail();
        $this->assertSame(['offline', null], [$two->status, $two->rx_power]);

        // link #1 to a connection, then the ONU moves to ONU id 7 and gets weak: same record, link kept
        $connection = $this->connection();
        $this->api('/isp/onu-link', ['id' => $one->id, 'connection_id' => $connection->id])->assertOk();
        $p = self::PON_IF;
        foreach (['43.1.3', '43.1.9', '46.1.15', '46.1.20', '51.1.4'] as $col) {
            FakeSnmpAgent::$mib[self::HW . ".{$col}.{$p}.7"] = FakeSnmpAgent::$mib[self::HW . ".{$col}.{$p}.1"];
            unset(FakeSnmpAgent::$mib[self::HW . ".{$col}.{$p}.1"]);
        }
        FakeSnmpAgent::$mib[self::HW . ".51.1.4.{$p}.7"] = -2810;
        unset(FakeSnmpAgent::$mib[self::HW . ".43.1.3.{$p}.2"], FakeSnmpAgent::$mib[self::HW . ".46.1.15.{$p}.2"], FakeSnmpAgent::$mib[self::HW . ".51.1.4.{$p}.2"]);

        $summary = OltService::poll($olt->fresh());
        $this->assertSame(['found' => 1, 'online' => 1, 'weak' => 1, 'new' => 0, 'missing' => 1], $summary);
        $one->refresh();
        $this->assertSame([7, -28.1, $connection->id], [$one->onu_id, $one->rx_power, $one->connection_id]);
        $this->assertSame('not_found', $two->fresh()->status);

        $this->assertSame(1, $this->api('/isp/get-onus', ['weak' => true])->json('total'));
        $this->assertSame($one->id, $this->api('/isp/get-onus', ['search' => $connection->code])->json('data.0.id'));
        $this->assertSame($one->id, $this->api('/isp/get-connection', ['id' => $connection->id])->json('onu.id'));
        $this->assertSame(1, $this->api('/isp/get-olts')->json('0.onus_weak'));
    }

    public function test_unreachable_olt_reports_failure(): void
    {
        $olt = $this->olt();
        FakeSnmpAgent::$down = true;
        try {
            $this->api('/isp/olt-poll', ['id' => $olt->id])->assertStatus(422);
            $this->assertStringStartsWith('POLL FAILED', $olt->fresh()->last_status);
            $this->assertSame(0, OltService::pollAll()); // logged, not thrown
        } finally {
            FakeSnmpAgent::$down = false;
        }
    }

    public function test_manual_onus_and_link_rules(): void
    {
        $olt = $this->olt(['vendor' => 'other', 'snmp_community' => '']);
        $this->assertFalse($olt->canPoll());
        $this->api('/isp/olt-poll', ['id' => $olt->id])->assertStatus(422);

        $connection = $this->connection();
        $id = $this->api('/isp/onu', ['olt_id' => $olt->id, 'pon_port' => 'EPON0/1', 'onu_id' => 3, 'mac' => 'AA-BB-CC-00-11-22', 'connection_id' => $connection->id])->assertOk()->json('id');
        $this->assertSame('aa:bb:cc:00:11:22', Onu::find($id)->mac);

        // one ONU per connection
        $this->api('/isp/onu', ['olt_id' => $olt->id, 'pon_port' => 'EPON0/1', 'onu_id' => 4, 'connection_id' => $connection->id])->assertStatus(422);
        // an OLT with linked ONUs can't be deleted
        $this->api('/isp/delete-olt', ['id' => $olt->id])->assertStatus(422);
        $this->api('/isp/onu-link', ['id' => $id, 'connection_id' => null])->assertOk();
        $this->api('/isp/delete-olt', ['id' => $olt->id])->assertOk();
        $this->assertSame(0, Onu::where('olt_id', $olt->id)->count());
    }

    public function test_other_branches_olts_are_out_of_reach(): void
    {
        $other = Branch::where('id', '!=', $this->branch->id)->first() ?? Branch::forceCreate(['name' => 'Other OLT branch', 'status' => 'a']);
        $olt = Olt::create(['branch_id' => $other->id, 'name' => 'Foreign', 'vendor' => 'huawei', 'host' => '10.0.0.9']);
        $onu = Onu::create(['branch_id' => $other->id, 'olt_id' => $olt->id, 'pon_port' => 'GPON 0/1/0', 'onu_id' => 1]);

        $this->assertNotContains($olt->id, array_column($this->api('/isp/get-olts')->json(), 'id'));
        $this->assertNotContains($onu->id, array_column($this->api('/isp/get-onus')->json('data'), 'id'));
        $this->api('/isp/olt-poll', ['id' => $olt->id])->assertNotFound();
        $this->api('/isp/onu-link', ['id' => $onu->id, 'connection_id' => null])->assertNotFound();
        $this->api('/isp/onu', ['olt_id' => $olt->id, 'pon_port' => 'x'])->assertStatus(422);
    }

    public function test_olt_needs_its_permission(): void
    {
        \App\Models\Role::forceCreate(['name' => 'OltClerk', 'access' => json_encode(['customer']), 'status' => 'a', 'branch_id' => $this->branch->id]);
        $clerk = User::forceCreate(['name' => 'OLT clerk', 'username' => 'olt_clerk_' . random_int(1000, 9999), 'email' => 'olt' . random_int(1000, 9999) . '@example.test',
            'role' => 'OltClerk', 'password' => bcrypt('secret-pass'), 'status' => 'a', 'ipAddress' => '127.0.0.1', 'branch_id' => $this->branch->id]);
        $this->actingAs($clerk)->withSession(['branch' => $this->branch])->postJson('/isp/olt', ['name' => 'X', 'vendor' => 'huawei', 'host' => '10.0.0.1'])->assertForbidden();
    }

    public function test_alerts_open_once_and_recover(): void
    {
        config(['mail.default' => 'array', 'isp.alert_email' => 'noc@example.test']);
        \Illuminate\Support\Facades\Bus::fake([\App\Jobs\SendSms::class]);
        $this->huaweiMib();
        $p = self::PON_IF;
        FakeSnmpAgent::$mib[self::HW . ".46.1.15.{$p}.2"] = 1; // both ONUs online
        FakeSnmpAgent::$mib[self::HW . ".51.1.4.{$p}.2"] = -2050;
        $olt = $this->olt();
        OltService::poll($olt);

        $connection = $this->connection();
        $one = Onu::where('olt_id', $olt->id)->where('onu_id', 1)->firstOrFail();
        $this->api('/isp/onu-link', ['id' => $one->id, 'connection_id' => $connection->id])->assertOk();
        $connection->update(['status' => 'active']);

        // the fibre to GPON 0/1/0 is cut: every ONU on it drops at once
        FakeSnmpAgent::$mib[self::HW . ".46.1.15.{$p}.1"] = 2;
        FakeSnmpAgent::$mib[self::HW . ".46.1.15.{$p}.2"] = 2;
        FakeSnmpAgent::$mib[self::HW . ".51.1.4.{$p}.1"] = 2147483647;
        FakeSnmpAgent::$mib[self::HW . ".51.1.4.{$p}.2"] = 2147483647;
        OltService::poll($olt->fresh());
        OltService::poll($olt->fresh());
        $open = \App\Models\NetworkAlert::where('olt_id', $olt->id)->whereNull('resolved_at')->get();
        $this->assertSame(['pon_down'], $open->pluck('kind')->all(), 'one alert however many polls see it');
        $this->assertStringContainsString('PON port GPON 0/1/0', \App\Support\SystemAlerts::all()[0]['message']);
        $this->assertSame(1, $this->api('/isp/get-network-alerts', ['open' => true])->json('data.0.customers'));

        $this->api('/isp/network-alert-notify', ['id' => $open[0]->id])->assertOk()->assertJsonPath('message', 'Outage notice sent to 1 customer(s)');
        \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\SendSms::class, 1);
        $this->api('/isp/network-alert-notify', ['id' => $open[0]->id])->assertStatus(422); // once per outage

        // back up, but ONU 1 now weak
        FakeSnmpAgent::$mib[self::HW . ".46.1.15.{$p}.1"] = 1;
        FakeSnmpAgent::$mib[self::HW . ".46.1.15.{$p}.2"] = 1;
        FakeSnmpAgent::$mib[self::HW . ".51.1.4.{$p}.1"] = -2850;
        FakeSnmpAgent::$mib[self::HW . ".51.1.4.{$p}.2"] = -2050;
        OltService::poll($olt->fresh());
        $this->assertNotNull($open[0]->fresh()->resolved_at);
        $this->assertSame([], \App\Support\SystemAlerts::all());
        $weak = \App\Models\NetworkAlert::where('olt_id', $olt->id)->whereNull('resolved_at')->sole();
        $this->assertSame(['onu_weak', $one->id], [$weak->kind, $weak->onu_id]);

        // the OLT stops answering, then comes back
        FakeSnmpAgent::$down = true;
        try {
            $this->assertSame(0, OltService::pollAll());
        } finally {
            FakeSnmpAgent::$down = false;
        }
        $this->assertSame(1, \App\Models\NetworkAlert::where('olt_id', $olt->id)->where('kind', 'olt_down')->whereNull('resolved_at')->count());
        FakeSnmpAgent::$mib[self::HW . ".51.1.4.{$p}.1"] = -2200;
        OltService::poll($olt->fresh());
        $this->assertSame(0, \App\Models\NetworkAlert::where('olt_id', $olt->id)->whereNull('resolved_at')->count());
        $this->assertSame([], \App\Support\SystemAlerts::all());
    }

    public function test_tickets_during_an_outage_are_linked_and_told_when_it_recovers(): void
    {
        config(['mail.default' => 'array']);
        \Illuminate\Support\Facades\Bus::fake([\App\Jobs\SendSms::class]);
        $this->huaweiMib();
        $p = self::PON_IF;
        FakeSnmpAgent::$mib[self::HW . ".46.1.15.{$p}.2"] = 1;
        $olt = $this->olt();
        OltService::poll($olt);
        $connection = $this->connection();
        $connection->update(['status' => 'active']);
        Onu::where('olt_id', $olt->id)->where('onu_id', 1)->update(['connection_id' => $connection->id]);

        FakeSnmpAgent::$mib[self::HW . ".46.1.15.{$p}.1"] = 2;
        FakeSnmpAgent::$mib[self::HW . ".46.1.15.{$p}.2"] = 2;
        OltService::poll($olt->fresh());
        $alert = \App\Models\NetworkAlert::where('olt_id', $olt->id)->where('kind', 'pon_down')->sole();
        $this->api('/isp/network-alert-notify', ['id' => $alert->id])->assertOk();

        $ticketId = $this->api('/isp/ticket', ['customer_id' => $connection->customer_id, 'subject' => 'No internet', 'message' => 'Red light on the ONU'])->assertOk()->json('id');
        $ticket = $this->api('/isp/get-ticket', ['id' => $ticketId])->json('ticket');
        $this->assertSame($alert->id, $ticket['network_alert_id']);
        $this->assertNull($ticket['network_alert']['resolved_at']);

        FakeSnmpAgent::$mib[self::HW . ".46.1.15.{$p}.1"] = 1;
        OltService::poll($olt->fresh());
        $notes = \App\Models\TicketReply::where('ticket_id', $ticketId)->where('author_type', 'system')->pluck('message')->all();
        $this->assertCount(2, $notes);
        $this->assertStringContainsString('recovered', $notes[1]);
        \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\SendSms::class, fn ($job) => str_contains($job->message, 'is fixed'));
        $this->assertNotNull($alert->fresh()->restored_notified_at);

        // a ticket from a customer not behind any outage stays unlinked
        Onu::where('olt_id', $olt->id)->update(['connection_id' => null]);
        $other = $this->api('/isp/ticket', ['customer_id' => $connection->customer_id, 'subject' => 'Bill', 'message' => 'Question'])->json('id');
        $this->assertNull(\App\Models\Ticket::find($other)->network_alert_id);
    }

    public function test_topology_groups_onus_by_port_and_box(): void
    {
        $this->huaweiMib();
        $olt = $this->olt();
        OltService::poll($olt);
        $connection = $this->connection();
        Onu::where('olt_id', $olt->id)->where('onu_id', 1)->update(['connection_id' => $connection->id]);

        $summary = collect($this->api('/isp/get-topology')->assertOk()->json())->firstWhere('id', $olt->id);
        $this->assertSame([2, 1, 1], [$summary['total'], $summary['online'], $summary['ports']]);
        $tree = $this->api('/isp/get-topology', ['olt_id' => $olt->id])->assertOk()->json();
        $port = $tree['ports'][0];
        $this->assertSame(['GPON 0/1/0', 2, 1, false], [$port['name'], $port['total'], $port['online'], $port['down']]);
        $linked = collect($port['boxes'])->flatMap(fn ($b) => $b['onus'])->firstWhere('onu_id', 1);
        $this->assertSame($connection->customer_id, $linked['customer']['id']);
    }

    private function connection(): Connection
    {
        $area = $this->api('/area', ['name' => 'OLT Area ' . random_int(1000, 9999)])->json('id');
        $this->api('/isp/package', ['name' => 'OLT 10', 'download_mbps' => 10, 'upload_mbps' => 5, 'price' => 600, 'billing_cycle' => 'monthly'])->assertOk();
        $phone = '01712' . random_int(100000, 999999);
        $this->api('/customer', ['name' => 'ONU customer', 'phone' => $phone, 'area_id' => $area])->assertOk();
        $customer = Customer::where('phone', $phone)->firstOrFail();
        $this->api('/isp/connection', ['customer_id' => $customer->id, 'package_id' => \App\Models\Package::where('name', 'OLT 10')->latest('id')->value('id'),
            'connection_type' => 'pppoe', 'pppoe_username' => 'onu_user_' . random_int(1000, 9999)])->assertOk();
        return Connection::where('customer_id', $customer->id)->firstOrFail();
    }
}

// Answers GET / GETBULK from $mib the way an SNMP agent does, through real BER packets.
class FakeSnmpAgent extends SnmpClient
{
    public static array $mib = [];
    public static bool $down = false;

    protected function exchange(string $packet, int $id): string
    {
        if (self::$down) {
            throw new \RuntimeException('SNMP: no answer from 10.9.9.2:161');
        }
        $request = self::decodeMessage($packet);
        $oids = array_keys(self::$mib);
        usort($oids, [SnmpClient::class, 'compare']);
        $out = [];
        foreach ($request['varbinds'] as [$oid]) {
            if ($request['type'] === 0xA0) {
                $out[] = [$oid, self::$mib[$oid] ?? ['t' => 0x81, 'v' => '']];
                continue;
            }
            $next = array_values(array_filter($oids, fn ($o) => SnmpClient::compare($o, $oid) > 0));
            foreach (array_slice($next, 0, $request['b']) as $o) {
                $out[] = [$o, self::$mib[$o]];
            }
            if (count($next) < $request['b']) {
                $out[] = [end($oids) ?: $oid, ['t' => 0x82, 'v' => '']];
            }
        }
        $varbinds = implode('', array_map(function ($vb) {
            [$oid, $v] = $vb;
            $value = is_int($v) ? self::encodeInt($v) : (is_array($v) ? ($v['t'] >= 0x80 ? self::tlv($v['t'], '') : self::encodeInt($v['v'], $v['t'])) : self::tlv(0x04, $v));
            return self::tlv(0x30, self::encodeOid($oid) . $value);
        }, $out));
        return self::tlv(0x30, self::encodeInt(1) . self::tlv(0x04, $this->community)
            . self::tlv(0xA2, self::encodeInt($id) . self::encodeInt(0) . self::encodeInt(0) . self::tlv(0x30, $varbinds)));
    }
}
