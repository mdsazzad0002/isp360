<?php

namespace Tests\Feature;

use App\Jobs\SyncConnectionToNetwork;
use App\Models\Branch;
use App\Models\Connection;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Router;
use App\Models\User;
use App\Services\Isp\ConnectionService;
use App\Services\Network\MikroTikDriver;
use App\Services\Network\NetworkDriver;
use App\Services\Network\RadiusClient;
use App\Services\Network\NasVendor;
use App\Services\Network\RadiusDriver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// RADIUS NAS next to MikroTik routers: FreeRADIUS SQL rows, Disconnect / CoA (RFC 5176), radacct.
class RadiusTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'radius'];

    private const SECRET = 'testing123';
    private const NAS_IP = '10.9.9.1';

    private User $admin;
    private Branch $branch;
    private Router $nas;
    private int $areaId;
    private int $phone = 0;
    // what the fake NAS received: [code, attributes]
    private array $sent = [];
    // how the fake NAS answers: code => reply code (or null = no answer)
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['isp.network_driver' => MikroTikDriver::class]); // = per router
        $this->admin = User::where('role', 'Superadmin')->firstOrFail();
        $this->branch = Branch::firstOrFail();
        Router::where('branch_id', $this->branch->id)->update(['is_default' => false]);
        $this->areaId = $this->api('/area', ['name' => 'Radius Area'])->json('id');

        $this->answers = [RadiusClient::DISCONNECT_REQUEST => RadiusClient::DISCONNECT_ACK, RadiusClient::COA_REQUEST => RadiusClient::COA_ACK];
        RadiusClient::$transport = function (string $host, int $port, string $packet) {
            $request = RadiusClient::decodePacket($packet);
            $this->sent[] = ['code' => $request['code'], 'host' => $host, 'port' => $port, 'attributes' => $request['attributes'], 'raw' => $packet];
            $code = $this->answers[$request['code']] ?? null;
            return $code === null ? null : self::reply($packet, $code, self::SECRET);
        };

        $this->api('/isp/router', ['name' => 'BRAS 1', 'driver' => 'radius', 'host' => self::NAS_IP, 'nas_type' => 'mikrotik',
            'radius_secret' => self::SECRET, 'coa_port' => 3799, 'is_default' => true, 'is_active' => true])->assertOk();
        $this->nas = Router::where('branch_id', $this->branch->id)->where('host', self::NAS_IP)->firstOrFail();
    }

    protected function tearDown(): void
    {
        RadiusClient::$transport = null;
        parent::tearDown();
    }

    // A NAS answer signed like a real one: Response Authenticator = MD5(Code+ID+Length+RequestAuth+Attributes+Secret).
    private static function reply(string $request, int $code, string $secret, string $attributes = ''): string
    {
        $header = pack('CCn', $code, ord($request[1]), 20 + strlen($attributes));
        return $header . md5($header . substr($request, 4, 16) . $attributes . $secret, true) . $attributes;
    }

    private function api(string $uri, array $data = [])
    {
        return $this->actingAs($this->admin)->withSession(['branch' => $this->branch])->postJson($uri, $data);
    }

    private function package(string $name, int $down, int $up): Package
    {
        $this->api('/isp/package', ['name' => $name, 'download_mbps' => $down, 'upload_mbps' => $up, 'price' => 500, 'billing_cycle' => 'monthly'])->assertOk();
        return Package::where('name', $name)->whereNull('reseller_id')->firstOrFail();
    }

    private function connection(Package $package, string $username): Connection
    {
        $phone = '0175555' . str_pad((string) (3000 + ++$this->phone), 4, '0', STR_PAD_LEFT);
        $this->api('/customer', ['name' => "Radius Customer {$this->phone}", 'phone' => $phone, 'area_id' => $this->areaId])->assertOk();
        $customer = Customer::where('phone', $phone)->firstOrFail();
        $id = $this->api('/isp/connection', [
            'customer_id' => $customer->id, 'package_id' => $package->id, 'connection_type' => 'pppoe',
            'pppoe_username' => $username, 'pppoe_password' => 'pw-' . $username, 'activate_now' => true,
        ])->assertOk()->json('id');
        $this->api('/isp/payment', ['customer_id' => $customer->id, 'amount' => 500, 'method' => 'cash', 'payment_date' => now()->toDateString()])->assertOk();
        return Connection::findOrFail($id);
    }

    private function openSession(string $username, string $ip = '100.64.0.10', string $sessionId = 'S-1'): void
    {
        RadiusDriver::db()->table('radacct')->insert([
            'acctsessionid' => $sessionId, 'acctuniqueid' => md5($username . $sessionId), 'username' => $username, 'nasipaddress' => self::NAS_IP,
            'acctstarttime' => now()->subHour(), 'acctupdatetime' => now(), 'acctinputoctets' => 1000000, 'acctoutputoctets' => 5000000,
            'callingstationid' => 'AA:BB:CC:DD:EE:FF', 'framedipaddress' => $ip,
        ]);
    }

    private function radcheck(string $username): array
    {
        return RadiusDriver::db()->table('radcheck')->where('username', $username)->orderBy('attribute')->pluck('value', 'attribute')->all();
    }

    public function test_the_nas_is_registered_and_its_secret_never_leaves(): void
    {
        $this->assertTrue($this->nas->isRadius());
        $nasRow = RadiusDriver::db()->table('nas')->where('nasname', self::NAS_IP)->first();
        $this->assertSame([self::SECRET, 3799, 'mikrotik'], [$nasRow->secret, (int) $nasRow->ports, $nasRow->type]);
        $this->assertStringNotContainsString(self::SECRET, json_encode($this->api('/isp/get-routers')->json()));
        $this->assertNotSame(self::SECRET, DB::table('routers')->where('id', $this->nas->id)->value('radius_secret'));

        // a RADIUS NAS needs an IP and a secret; no API login
        $this->api('/isp/router', ['name' => 'Bad', 'driver' => 'radius', 'host' => 'bras.example', 'nas_type' => 'mikrotik', 'radius_secret' => 'x'])->assertStatus(422);
        $this->api('/isp/router', ['name' => 'Bad', 'driver' => 'radius', 'host' => '10.9.9.2', 'nas_type' => 'mikrotik'])->assertStatus(422);

        $this->api('/isp/router-test', ['id' => $this->nas->id])->assertOk()->assertJsonPath('message', fn ($m) => str_contains($m, 'NAS registered'));

        // deleting it removes the FreeRADIUS client
        $this->api('/isp/delete-router', ['id' => $this->nas->id])->assertOk();
        $this->assertFalse(RadiusDriver::db()->table('nas')->where('nasname', self::NAS_IP)->exists());
    }

    public function test_a_connection_becomes_a_radius_user_with_its_package_speed(): void
    {
        $package = $this->package('RAD 10M', 10, 5);
        $connection = $this->connection($package, 'rad_user1');

        $this->assertSame(['Cleartext-Password' => 'pw-rad_user1'], $this->radcheck('rad_user1'));
        $group = 'isp-pkg-' . $package->id;
        $this->assertSame($group, RadiusDriver::db()->table('radusergroup')->where('username', 'rad_user1')->value('groupname'));
        $this->assertSame('5M/10M', RadiusDriver::db()->table('radgroupreply')->where('groupname', $group)->where('attribute', 'Mikrotik-Rate-Limit')->value('value'));
        $connection->refresh();
        $this->assertSame(['synced', 'rad_user1'], [$connection->network_sync_status, $connection->radius_username]);
        $this->assertSame([], app(NetworkDriver::class)->verify($connection));

        // a static IP is handed out by RADIUS
        $connection->update(['static_ip' => '100.64.9.9']);
        SyncConnectionToNetwork::dispatchSync($connection->id);
        $this->assertSame('100.64.9.9', RadiusDriver::db()->table('radreply')->where('username', 'rad_user1')->where('attribute', 'Framed-IP-Address')->value('value'));
    }

    public function test_suspending_rejects_the_user_and_disconnects_the_live_session(): void
    {
        $connection = $this->connection($this->package('RAD 20M', 20, 10), 'rad_user2');
        $this->openSession('rad_user2');

        ConnectionService::suspend($connection, 'Expired', true);
        $this->assertSame('Reject', $this->radcheck('rad_user2')['Auth-Type']);
        $disconnect = collect($this->sent)->firstWhere('code', RadiusClient::DISCONNECT_REQUEST);
        $this->assertSame([self::NAS_IP, 3799], [$disconnect['host'], $disconnect['port']]);
        $this->assertEquals(['User-Name' => 'rad_user2', 'Framed-IP-Address' => '100.64.0.10', 'Acct-Session-Id' => 'S-1'], $disconnect['attributes']);
        // signed with the NAS secret (RFC 5176 Request Authenticator)
        $raw = $disconnect['raw'];
        $this->assertSame(substr($raw, 4, 16), md5(substr($raw, 0, 4) . str_repeat("\0", 16) . substr($raw, 20) . self::SECRET, true));
        $this->assertSame('synced', $connection->fresh()->network_sync_status);

        // reactivating accepts it again, no disconnect needed (the session would be closed by the NAS by now)
        RadiusDriver::db()->table('radacct')->update(['acctstoptime' => now()]);
        $this->sent = [];
        ConnectionService::reactivate($connection->fresh(), 'Paid', true);
        $this->assertArrayNotHasKey('Auth-Type', $this->radcheck('rad_user2'));
        $this->assertSame([], $this->sent);
    }

    public function test_a_nas_that_does_not_answer_fails_the_sync_so_it_is_retried(): void
    {
        $connection = $this->connection($this->package('RAD 30M', 30, 15), 'rad_user3');
        $this->openSession('rad_user3');
        $this->answers[RadiusClient::DISCONNECT_REQUEST] = null;

        ConnectionService::suspend($connection, 'Expired', true);
        $connection->refresh();
        $this->assertSame('Reject', $this->radcheck('rad_user3')['Auth-Type']); // RADIUS itself is right
        $this->assertSame('failed', $connection->network_sync_status);
        $this->assertStringContainsString('did not answer the Disconnect request', $connection->network_sync_error);
        $this->assertCount(3, collect($this->sent)->where('code', RadiusClient::DISCONNECT_REQUEST)); // 1 + 2 retries

        // a NAS answering with the wrong secret isn't believed
        $this->answers[RadiusClient::DISCONNECT_REQUEST] = RadiusClient::DISCONNECT_ACK;
        RadiusClient::$transport = fn ($h, $p, $packet) => self::reply($packet, RadiusClient::DISCONNECT_ACK, 'wrong-secret');
        SyncConnectionToNetwork::dispatchSync($connection->id);
        $this->assertStringContainsString('secret check', $connection->fresh()->network_sync_error);
    }

    public function test_package_change_uses_coa_or_falls_back_to_disconnect(): void
    {
        $slow = $this->package('RAD 5M', 5, 2);
        $fast = $this->package('RAD 50M', 50, 25);
        $connection = $this->connection($slow, 'rad_user4');
        $this->openSession('rad_user4');

        $connection->update(['package_id' => $fast->id]);
        SyncConnectionToNetwork::dispatchSync($connection->id);
        $coa = collect($this->sent)->firstWhere('code', RadiusClient::COA_REQUEST);
        $this->assertNotNull($coa);
        $this->assertStringContainsString('25M/50M', $coa['raw']); // Mikrotik-Rate-Limit VSA
        $this->assertNull(collect($this->sent)->firstWhere('code', RadiusClient::DISCONNECT_REQUEST));

        // a NAS that refuses the CoA gets a disconnect instead
        $this->sent = [];
        $this->answers[RadiusClient::COA_REQUEST] = RadiusClient::COA_NAK;
        $connection->update(['package_id' => $slow->id]);
        SyncConnectionToNetwork::dispatchSync($connection->id);
        $this->assertSame([RadiusClient::COA_REQUEST, RadiusClient::DISCONNECT_REQUEST], array_column($this->sent, 'code'));
    }

    public function test_renaming_moves_the_user_and_ends_the_old_session(): void
    {
        $connection = $this->connection($this->package('RAD 8M', 8, 4), 'rad_old');
        $this->openSession('rad_old');
        $connection->update(['pppoe_username' => 'rad_new']);
        SyncConnectionToNetwork::dispatchSync($connection->id);

        $this->assertSame([], $this->radcheck('rad_old'));
        $this->assertSame('pw-rad_old', $this->radcheck('rad_new')['Cleartext-Password']);
        $this->assertSame('rad_old', collect($this->sent)->firstWhere('code', RadiusClient::DISCONNECT_REQUEST)['attributes']['User-Name']);
        $this->assertSame('rad_new', $connection->fresh()->radius_username);
    }

    public function test_online_status_and_session_history_come_from_accounting(): void
    {
        $connection = $this->connection($this->package('RAD 12M', 12, 6), 'rad_user5');
        $this->api('/isp/connection-online', ['id' => $connection->id])->assertOk()->assertJsonPath('online', false)->assertJsonPath('radius', true);
        $this->openSession('rad_user5', '100.64.1.5');

        $this->api('/isp/connection-online', ['id' => $connection->id])->assertOk()->assertJsonPath('online', true)->assertJsonPath('session.address', '100.64.1.5');
        $sessions = $this->api('/isp/connection-sessions', ['id' => $connection->id])->assertOk()->json('sessions');
        $this->assertSame(['100.64.1.5', 'AA:BB:CC:DD:EE:FF', 5000000, 1000000], [$sessions[0]['address'], $sessions[0]['mac'], $sessions[0]['download_bytes'], $sessions[0]['upload_bytes']]);

        $online = $this->api('/isp/router-sessions', ['id' => $this->nas->id])->assertOk()->json();
        $this->assertSame('rad_user5', $online[0]['user']);
        $this->assertSame($connection->id, $online[0]['connection']['id']);
    }

    public function test_mikrotik_and_radius_routers_side_by_side(): void
    {
        $driver = app(NetworkDriver::class);
        $mikrotik = Router::create(['branch_id' => $this->branch->id, 'name' => 'MT', 'driver' => 'mikrotik', 'host' => '192.168.88.1', 'username' => 'api', 'password' => 'x', 'is_active' => true, 'is_default' => false]);
        $this->assertInstanceOf(RadiusDriver::class, $driver->for($this->nas));
        $this->assertInstanceOf(MikroTikDriver::class, $driver->for($mikrotik));
        $this->assertSame(['BRAS 1 is a RADIUS NAS: site / IP blocks need a MikroTik router with API access.'], $driver->syncBlocks($this->nas, collect([1])));

        // vendor speed attributes
        $package = $this->package('RAD V', 100, 20);
        $this->assertSame([['Huawei-Input-Average-Rate', ':=', '20000000'], ['Huawei-Output-Average-Rate', ':=', '100000000']], RadiusDriver::rateAttributes($package, ['huawei']));
        $this->assertSame('ip:sub-qos-policy-out=isp-down-100M', RadiusDriver::rateAttributes($package, ['cisco'])[1][2]);
    }
    public function test_every_listed_vendor_gets_its_own_speed_attributes(): void
    {
        $package = $this->package('RAD VENDOR', 100, 20);
        $this->assertSame([['ERX-Ingress-Policy-Name', ':=', 'isp-up-20M'], ['ERX-Egress-Policy-Name', ':=', 'isp-down-100M']], RadiusDriver::rateAttributes($package, ['juniper']));
        $this->assertSame([['Filter-Id', ':=', '100000/20000']], RadiusDriver::rateAttributes($package, ['accel-ppp'])); // kbit down/up
        $this->assertSame([['WISPr-Bandwidth-Max-Up', ':=', '20000000'], ['WISPr-Bandwidth-Max-Down', ':=', '100000000']], RadiusDriver::rateAttributes($package, ['pfsense']));
        $this->assertSame([], RadiusDriver::rateAttributes($package, ['other']));
        $this->assertSame([], RadiusDriver::rateAttributes($package, ['no-such-vendor']));

        // every vendor's live attributes can be encoded for CoA
        foreach (NasVendor::all() as $type => $vendor) {
            foreach (NasVendor::rateAttributes($type, $package) as [$name, , $value]) {
                $this->assertNotSame('', RadiusClient::encodeAttributes([$name => $value]), "{$type}: {$name}");
            }
        }
    }

    public function test_accel_ppp_users_get_their_own_group_so_filter_id_never_reaches_mikrotik(): void
    {
        $vyos = Router::create(['branch_id' => $this->branch->id, 'name' => 'VyOS', 'driver' => 'radius', 'host' => '10.9.9.7', 'nas_type' => 'accel-ppp', 'radius_secret' => self::SECRET, 'coa_port' => 3799, 'is_active' => true, 'is_default' => false]);
        $package = $this->package('RAD VYOS', 40, 10);
        $onMikrotik = $this->connection($package, 'rad_mt');
        $onVyos = $this->connection($package, 'rad_vy');
        $onVyos->update(['router_id' => $vyos->id]);
        SyncConnectionToNetwork::dispatchSync($onVyos->id);

        $db = RadiusDriver::db();
        $shared = 'isp-pkg-' . $package->id;
        $own = $shared . '-accel-ppp';
        $this->assertSame($shared, $db->table('radusergroup')->where('username', 'rad_mt')->value('groupname'));
        $this->assertSame($own, $db->table('radusergroup')->where('username', 'rad_vy')->value('groupname'));
        $this->assertSame(['Filter-Id' => '40000/10000'], $db->table('radgroupreply')->where('groupname', $own)->pluck('value', 'attribute')->all());
        $this->assertFalse($db->table('radgroupreply')->where('groupname', $shared)->where('attribute', 'Filter-Id')->exists());
        $this->assertSame('synced', $onVyos->fresh()->network_sync_status);
        $this->assertSame('synced', $onMikrotik->fresh()->network_sync_status);
    }

    public function test_a_nas_without_disconnect_support_is_not_sent_one(): void
    {
        $this->nas->update(['nas_type' => 'pfsense']);
        $connection = $this->connection($this->package('RAD PF', 10, 5), 'rad_pf');
        $this->openSession('rad_pf');
        $this->answers[RadiusClient::DISCONNECT_REQUEST] = null; // would fail the sync if one were sent

        ConnectionService::suspend($connection, 'Expired', true);
        $this->assertSame('Reject', $this->radcheck('rad_pf')['Auth-Type']);
        $this->assertSame([], $this->sent);
        $this->assertSame('synced', $connection->fresh()->network_sync_status);
    }
}
