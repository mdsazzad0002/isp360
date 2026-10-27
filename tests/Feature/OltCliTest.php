<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Olt;
use App\Models\Onu;
use App\Models\User;
use App\Services\Network\Cli\CliConnector;
use App\Services\Network\Cli\CliTransport;
use App\Services\Network\OltCli;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

// ONU changes over the OLT's CLI: commands built from the vendor templates (and nothing typed can add a
// command), a preview before anything is sent, login / paging / confirmations, and the refusals.
class OltCliTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        config(['isp.network_driver' => \App\Services\Network\NullNetworkDriver::class, 'isp.olt.cli_timeout' => 2]);
        $this->admin = User::where('role', 'Superadmin')->firstOrFail();
        $this->branch = Branch::firstOrFail();
        FakeOltShell::reset();
        $this->app->bind(CliConnector::class, fn () => new class extends CliConnector {
            public function connect(Olt $olt): CliTransport
            {
                return new FakeOltShell();
            }
        });
    }

    private function api(string $uri, array $data = [])
    {
        return $this->actingAs($this->admin)->withSession(['branch' => $this->branch])->postJson($uri, $data);
    }

    private function olt(array $extra = []): Olt
    {
        $id = $this->api('/isp/olt', $extra + ['name' => 'CLI OLT', 'vendor' => 'huawei', 'host' => '10.9.9.3', 'cli_protocol' => 'telnet', 'cli_username' => 'noc',
            'cli_password' => 'right', 'provision' => ['line_profile' => '10', 'service_profile' => '10', 'vlan' => 100, 'gemport' => 1]])->assertOk()->json('id');
        return Olt::findOrFail($id);
    }

    public function test_authorise_previews_then_sends_and_lists_the_onu(): void
    {
        $olt = $this->olt();
        $this->assertTrue($olt->canConfigure());
        $this->assertArrayNotHasKey('cli_password', $this->api('/isp/get-olts')->json('0'));
        Onu::create(['branch_id' => $this->branch->id, 'olt_id' => $olt->id, 'pon_port' => 'GPON 0/1/0', 'onu_id' => 1]); // id 1 is taken

        $request = ['olt_id' => $olt->id, 'pon_port' => '0/1/0', 'serial' => 'HWTC1A2B3C4D', 'name' => 'C-1001 Rahim'];
        $preview = $this->api('/isp/onu-authorize', $request)->assertOk();
        $expected = [
            'interface gpon 0/1',
            'ont add 0 2 sn-auth 485754431A2B3C4D omci ont-lineprofile-id 10 ont-srvprofile-id 10 desc "C-1001 Rahim"',
            'quit',
            'service-port vlan 100 gpon 0/1/0 ont 2 gemport 1 multi-service user-vlan 100 tag-transform translate',
        ];
        $this->assertSame($expected, $preview->json('commands'));
        $this->assertSame([], FakeOltShell::$received, 'a preview sends nothing');

        $this->api('/isp/onu-authorize', $request + ['run' => true])->assertOk()->assertJsonPath('message', 'ONU authorised as 0/1/0:2');
        $this->assertSame(array_merge(['enable', 'scroll 512', 'config'], $expected), FakeOltShell::$received);
        $onu = Onu::where('olt_id', $olt->id)->where('serial', 'HWTC1A2B3C4D')->firstOrFail();
        $this->assertSame([2, 'C-1001 Rahim'], [$onu->onu_id, $onu->name]);
        $this->assertSame(1, AuditLog::where('action', 'onu.authorized')->count());
    }

    public function test_typed_values_cannot_add_commands(): void
    {
        $olt = $this->olt();
        $base = ['olt_id' => $olt->id, 'pon_port' => '0/1/0', 'serial' => 'HWTC1A2B3C4D', 'name' => 'x'];
        // a newline or quote in the name is dropped, so the line stays one command
        $lines = OltCli::commands($olt, 'rename', ['pon_port' => '0/1/0', 'onu_id' => 3, 'name' => "a\"\nquit\r\nreboot"]);
        $this->assertSame('ont modify 0 3 desc "aquitreboot"', $lines[1]);
        $this->api('/isp/onu-authorize', $base + ['line_profile' => "10\nreboot"])->assertStatus(422);
        $this->api('/isp/onu-authorize', $base + ['vlan' => 5000])->assertStatus(422);
        $this->api('/isp/onu-authorize', ['serial' => 'NOT-A-SERIAL!'] + $base)->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'not a GPON serial'));
        $this->api('/isp/onu-authorize', ['pon_port' => 'uplink'] + $base)->assertStatus(422);
    }

    public function test_reboot_answers_the_confirmation_and_unregistered_list_pages(): void
    {
        $olt = $this->olt();
        $onu = Onu::create(['branch_id' => $this->branch->id, 'olt_id' => $olt->id, 'pon_port' => 'GPON 0/1/0', 'onu_id' => 5, 'serial' => 'HWTC00000005']);
        $this->api('/isp/onu-action', ['id' => $onu->id, 'action' => 'reboot', 'run' => true])->assertOk();
        $this->assertContains('ont reset 0 5', FakeOltShell::$received);
        $this->assertContains('answer:y', FakeOltShell::$received);

        FakeOltShell::reset();
        $list = $this->api('/isp/get-olt-unregistered', ['id' => $olt->id])->assertOk()->json('onus');
        $this->assertSame([
            ['pon_port' => 'GPON 0/1/0', 'serial' => 'HWTC1A2B3C4D', 'mac' => null, 'known' => false],
            ['pon_port' => 'GPON 0/2/1', 'serial' => 'ZTEGC0FFEE01', 'mac' => null, 'known' => false],
        ], $list);
        $this->assertSame(1, FakeOltShell::$spaces, 'the pager was answered');
    }

    public function test_rename_and_delete_keep_the_list_in_step(): void
    {
        $olt = $this->olt();
        $onu = Onu::create(['branch_id' => $this->branch->id, 'olt_id' => $olt->id, 'pon_port' => 'GPON 0/1/0', 'onu_id' => 6]);
        $this->api('/isp/onu-action', ['id' => $onu->id, 'action' => 'rename', 'name' => 'New name', 'run' => true])->assertOk();
        $this->assertSame('New name', $onu->fresh()->name);

        $customer = Customer::forceCreate(['code' => 'C-ONU', 'name' => 'Linked', 'phone' => '01711119999', 'branch_id' => $this->branch->id, 'status' => 'a']);
        $connection = \App\Models\Connection::forceCreate(['code' => 'CN-ONU', 'customer_id' => $customer->id, 'package_id' => 0, 'branch_id' => $this->branch->id, 'connection_type' => 'pppoe', 'status' => 'active']);
        $onu->update(['connection_id' => $connection->id]);
        $this->api('/isp/onu-action', ['id' => $onu->id, 'action' => 'delete', 'run' => true])->assertStatus(422);
        $onu->update(['connection_id' => null]);
        $this->api('/isp/onu-action', ['id' => $onu->id, 'action' => 'delete', 'run' => true])->assertOk();
        $this->assertNull(Onu::find($onu->id));
        $this->assertSame(['undo service-port port 0/1/0 ont 6', 'interface gpon 0/1', 'ont delete 0 6', 'quit'], array_slice(FakeOltShell::$received, -4));
    }

    public function test_bad_login_and_refused_commands_are_reported(): void
    {
        $olt = $this->olt(['cli_password' => 'wrong']);
        $onu = Onu::create(['branch_id' => $this->branch->id, 'olt_id' => $olt->id, 'pon_port' => 'GPON 0/1/0', 'onu_id' => 7]);
        $this->api('/isp/onu-action', ['id' => $onu->id, 'action' => 'reboot', 'run' => true])->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'refused the login'));

        $olt->update(['cli_password' => 'right']);
        FakeOltShell::$refuse = 'ont reset';
        $this->api('/isp/onu-action', ['id' => $onu->id, 'action' => 'reboot', 'run' => true])->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'The OLT refused "ont reset 0 7"'));
        $this->assertSame(2, AuditLog::where('action', 'onu.reboot_failed')->count()); // the bad login and the refusal
    }

    public function test_changes_need_the_olt_configure_permission(): void
    {
        $olt = $this->olt();
        $onu = Onu::create(['branch_id' => $this->branch->id, 'olt_id' => $olt->id, 'pon_port' => 'GPON 0/1/0', 'onu_id' => 8]);
        \App\Models\Role::forceCreate(['name' => 'OltViewer', 'access' => json_encode(['olt']), 'status' => 'a', 'branch_id' => $this->branch->id]);
        $viewer = User::forceCreate(['name' => 'OLT viewer', 'username' => 'olt_viewer_' . random_int(1000, 9999), 'email' => 'oltv' . random_int(1000, 9999) . '@example.test',
            'role' => 'OltViewer', 'password' => bcrypt('secret-pass'), 'status' => 'a', 'ipAddress' => '127.0.0.1', 'branch_id' => $this->branch->id]);
        $as = $this->actingAs($viewer)->withSession(['branch' => $this->branch]);
        $as->postJson('/isp/onu-action', ['id' => $onu->id, 'action' => 'reboot', 'run' => true])->assertForbidden();
        $as->postJson('/isp/onu-authorize', ['olt_id' => $olt->id, 'pon_port' => '0/1/0', 'serial' => 'HWTC1A2B3C4D', 'name' => 'x', 'run' => true])->assertForbidden();
        $as->postJson('/isp/get-olt-unregistered', ['id' => $olt->id])->assertForbidden();
        $this->assertSame([], FakeOltShell::$received);
    }
}

// A Huawei-like CLI over a fake link: login, prompts per mode, a y/n question, a pager, refusals.
class FakeOltShell implements CliTransport
{
    public static array $received = [];
    public static int $spaces = 0;
    public static ?string $refuse = null;

    private string $out = "\r\nUser name:";
    private string $in = '';
    private string $state = 'user';
    private string $mode = '>';
    private ?string $rest = null;

    public static function reset(): void
    {
        self::$received = [];
        self::$spaces = 0;
        self::$refuse = null;
    }

    public function write(string $data): void
    {
        if ($data === ' ' && $this->rest !== null) {
            self::$spaces++;
            $this->out .= $this->rest . $this->prompt();
            $this->rest = null;
            return;
        }
        $this->in .= $data;
        while (($pos = strpos($this->in, "\n")) !== false) {
            $line = rtrim(substr($this->in, 0, $pos), "\r");
            $this->in = substr($this->in, $pos + 1);
            $this->line($line);
        }
    }

    private function line(string $line): void
    {
        switch ($this->state) {
            case 'user':
                $this->state = 'pass';
                $this->out .= "{$line}\r\nUser password:";
                return;
            case 'pass':
                $this->state = $line === 'right' ? 'cli' : 'user';
                $this->out .= $line === 'right' ? "\r\n  Huawei Integrated Access Software\r\n\r\nMA5683T>" : "\r\n  Username or password invalid.\r\nUser name:";
                return;
            case 'confirm':
                self::$received[] = "answer:{$line}";
                $this->state = 'cli';
                $this->out .= "{$line}\r\n" . $this->prompt();
                return;
        }
        self::$received[] = $line;
        $this->out .= $line . "\r\n";
        if (self::$refuse && str_starts_with($line, self::$refuse)) {
            $this->out .= "                    ^\r\n  % Unknown command, the error locates at '^'\r\n" . $this->prompt();
            return;
        }
        if (str_starts_with($line, 'ont reset')) {
            $this->state = 'confirm';
            $this->out .= '  Are you sure to reset the ONT(s)? (y/n)[n]:';
            return;
        }
        if ($line === 'display ont autofind all') {
            $this->out .= "   ----------------------------------------------------------------\r\n   Number              : 1\r\n   F/S/P               : 0/1/0\r\n"
                . "   Ont SN              : 485754431A2B3C4D (HWTC-1A2B3C4D)\r\n   Password            : 0x00\r\n"
                . "  ---- More ( Press 'Q' to break ) ----";
            $this->rest = "\e[37D                                     \e[37D   ----------------------------------------------------------------\r\n"
                . "   Number              : 2\r\n   F/S/P               : 0/2/1\r\n   Ont SN              : 5A544547C0FFEE01 (ZTEG-C0FFEE01)\r\n";
            return;
        }
        $this->mode = match (true) {
            $line === 'enable' => '#',
            $line === 'config' => '(config)#',
            str_starts_with($line, 'interface gpon') => '(config-if-gpon-' . substr($line, 15) . ')#',
            $line === 'quit' => '(config)#',
            default => $this->mode,
        };
        $this->out .= $this->prompt();
    }

    private function prompt(): string
    {
        return "\r\nMA5683T{$this->mode}";
    }

    public function read(float $timeout): string
    {
        $out = $this->out;
        $this->out = '';
        return $out;
    }

    public function newline(): string
    {
        return "\r\n";
    }

    public function close(): void
    {
    }
}
