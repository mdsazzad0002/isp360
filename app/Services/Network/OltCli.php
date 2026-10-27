<?php

namespace App\Services\Network;

use App\Models\Olt;
use App\Models\Onu;
use App\Services\Network\Cli\CliConnector;
use App\Services\Network\Cli\CliTransport;
use RuntimeException;

// ONU changes on the OLT's command line, from the vendor's command templates in config/olt_vendors.php.
// Every value put into a command is checked against a strict pattern first, so nothing typed on the
// page can add a second command line.
class OltCli
{
    public const ACTIONS = ['authorize', 'reboot', 'rename', 'delete'];

    private CliTransport $link;
    private array $cli;
    private string $transcript = '';

    private function __construct(private Olt $olt)
    {
        $this->cli = $olt->vendorConfig()['cli'] ?? [];
    }

    // The command lines an action sends, values filled in and checked.
    public static function commands(Olt $olt, string $action, array $values): array
    {
        $cli = $olt->vendorConfig()['cli'] ?? [];
        $template = $cli['commands'][$action] ?? null;
        if (! $template || ! in_array($action, self::ACTIONS, true)) {
            throw new RuntimeException("No '{$action}' command for this OLT brand in config/olt_vendors.php");
        }
        $vars = self::placeholders($cli, $values);
        return array_map(function ($line) use ($vars, $action) {
            $line = strtr($line, $vars);
            if (preg_match('/\{(\w+)\}/', $line, $m)) {
                throw new RuntimeException(self::missing($m[1], $action));
            }
            return $line;
        }, (array) $template);
    }

    // Sends the lines after logging in; returns the session transcript. Throws on a CLI error with the
    // transcript so far (the lines before the failing one were applied).
    public static function run(Olt $olt, array $lines): string
    {
        if (! $olt->canConfigure()) {
            throw new RuntimeException('Set the OLT\'s CLI login (SSH or Telnet) first.');
        }
        $session = new self($olt);
        $session->link = app(CliConnector::class)->connect($olt);
        try {
            $session->login();
            foreach (array_merge($session->cli['setup'] ?? [], $lines) as $line) {
                $session->send($line);
            }
        } catch (\Throwable $th) {
            throw new RuntimeException($th->getMessage() . "\n\n" . $session->transcript, 0, $th);
        } finally {
            $session->link->close();
        }
        return $session->transcript;
    }

    // ONUs the OLT has found on its PON ports but nobody has authorised:
    // list of ['pon_port', 'serial', 'mac', 'known' => already on our ONU list]
    public static function unregistered(Olt $olt): array
    {
        $cli = $olt->vendorConfig()['cli'] ?? [];
        if (empty($cli['commands']['unregistered'])) {
            throw new RuntimeException('This OLT brand has no command for unregistered ONUs in config/olt_vendors.php');
        }
        $transcript = self::run($olt, [$cli['commands']['unregistered']]);
        preg_match_all($cli['unregistered_pattern'], $transcript, $matches, PREG_SET_ORDER);
        $rows = [];
        foreach ($matches as $m) {
            $serial = isset($m['serial']) && $m['serial'] !== '' ? self::serialFromCli($m['serial']) : null;
            $mac = isset($m['mac']) && $m['mac'] !== '' ? SnmpOltDriver::mac($m['mac']) : null;
            $key = $serial ?? $mac;
            if (! $key || isset($rows[$key])) {
                continue;
            }
            $rows[$key] = [
                'pon_port' => str_replace('{pon}', $m['pon'], $cli['pon_label'] ?? '{pon}'),
                'serial' => $serial,
                'mac' => $mac,
                'known' => Onu::where('olt_id', $olt->id)->where(fn ($q) => $serial ? $q->where('serial', $serial) : $q->where('mac', $mac))->exists(),
            ];
        }
        return array_values($rows);
    }

    // The lowest ONU id not in use on the port (from the polled ONU list).
    public static function nextOnuId(Olt $olt, string $ponPort): int
    {
        // "0/1/3" typed and "GPON 0/1/3" polled are the same port
        $pattern = $olt->vendorConfig()['cli']['port'] ?? null;
        $numbers = fn ($p) => $pattern && preg_match($pattern, $p, $m) ? $m[0] : $p;
        $used = Onu::where('olt_id', $olt->id)->whereNotNull('onu_id')->get(['pon_port', 'onu_id'])
            ->filter(fn ($o) => $numbers($o->pon_port) === $numbers($ponPort))->pluck('onu_id')->all();
        $max = (int) ($olt->vendorConfig()['cli']['max_onu_id'] ?? 128);
        for ($id = 1; $id <= $max; $id++) {
            if (! in_array($id, $used)) {
                return $id;
            }
        }
        throw new RuntimeException("PON port {$ponPort} is full ({$max} ONUs).");
    }

    // HWTC1A2B3C4D from the CLI's hex form 485754431A2B3C4D; 12-character serials as they are
    public static function serialFromCli(string $raw): ?string
    {
        $raw = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw));
        if (strlen($raw) === 16 && ctype_xdigit($raw) && ctype_alpha($vendor = (string) hex2bin(substr($raw, 0, 8)))) {
            return strtoupper($vendor) . substr($raw, 8);
        }
        return preg_match('/^[A-Z0-9]{12}$/', $raw) ? $raw : null;
    }

    // {name} => value for every placeholder the values allow
    private static function placeholders(array $cli, array $v): array
    {
        $out = [];
        if (isset($v['pon_port'])) {
            if (! preg_match($cli['port'] ?? '/$^/', (string) $v['pon_port'], $m)) {
                throw new RuntimeException("Can't read frame/slot/port from PON port \"{$v['pon_port']}\".");
            }
            foreach (['frame', 'slot', 'port'] as $k) {
                if (isset($m[$k]) && $m[$k] !== '') {
                    $out["{{$k}}"] = (string) (int) $m[$k];
                }
            }
        }
        if (isset($v['onu_id']) && $v['onu_id'] !== '' && $v['onu_id'] !== null) {
            $out['{onu_id}'] = (string) (int) $v['onu_id'];
        }
        if (! empty($v['serial'])) {
            $serial = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $v['serial']));
            if (! preg_match('/^[A-Z]{4}[0-9A-F]{8}$/', $serial)) {
                throw new RuntimeException("\"{$v['serial']}\" is not a GPON serial (4 letters + 8 hex digits, e.g. HWTC1A2B3C4D).");
            }
            $out['{serial}'] = $serial;
            $out['{serial_hex}'] = strtoupper(bin2hex(substr($serial, 0, 4))) . substr($serial, 4);
        }
        if (! empty($v['mac'])) {
            $mac = SnmpOltDriver::mac($v['mac']) ?? throw new RuntimeException("\"{$v['mac']}\" is not a MAC address.");
            $hex = str_replace(':', '', $mac);
            $out['{mac}'] = $mac;
            $out['{mac_dotted}'] = implode('.', str_split($hex, 4));
        }
        if (isset($v['name']) && $v['name'] !== '') {
            $name = trim(mb_substr(preg_replace('/[^A-Za-z0-9 _.\-]/', '', (string) $v['name']), 0, 32));
            $out['{name}'] = $name;
            $out['{name_token}'] = preg_replace('/\s+/', '_', $name);
        }
        foreach (['line_profile', 'service_profile', 'onu_type'] as $k) {
            if (isset($v[$k]) && $v[$k] !== '') {
                if (! preg_match('/^[A-Za-z0-9_.\-]{1,40}$/', (string) $v[$k])) {
                    throw new RuntimeException(ucfirst(str_replace('_', ' ', $k)) . ' may only have letters, digits, - _ and .');
                }
                $out["{{$k}}"] = (string) $v[$k];
            }
        }
        foreach (['vlan' => [1, 4094], 'gemport' => [0, 4095]] as $k => [$min, $max]) {
            if (isset($v[$k]) && $v[$k] !== '') {
                if (filter_var($v[$k], FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]) === false) {
                    throw new RuntimeException("{$k} must be a number from {$min} to {$max}.");
                }
                $out["{{$k}}"] = (string) (int) $v[$k];
            }
        }
        return $out;
    }

    private static function missing(string $placeholder, string $action): string
    {
        return match ($placeholder) {
            'serial', 'serial_hex' => 'This needs the ONU serial.',
            'mac', 'mac_dotted' => 'This needs the ONU MAC address.',
            'onu_id' => 'This needs the ONU id on the port.',
            'name', 'name_token' => 'Give a name.',
            'line_profile', 'service_profile', 'vlan', 'gemport', 'onu_type' => 'Set the ' . str_replace('_', ' ', $placeholder) . ' (OLT edit → provisioning defaults, or on the form).',
            default => "No value for {{$placeholder}} in the {$action} command.",
        };
    }

    // ---- the session ----

    private function login(): void
    {
        $answers = [];
        foreach ($this->cli['login'] ?? [] as $regex => $value) {
            $answers[$regex] = strtr($value, ['{username}' => (string) $this->olt->cli_username, '{password}' => (string) $this->olt->cli_password]);
        }
        $this->wait($answers, true);
    }

    private function send(string $line): string
    {
        $this->transcript .= $line . "\n";
        $this->link->write($line . $this->link->newline());
        $answers = $this->cli['confirm'] ?? [];
        if (filled($this->olt->cli_enable_password)) {
            $answers['/Password:\s*$/i'] = (string) $this->olt->cli_enable_password;
        }
        $output = $this->wait($answers);
        if (! empty($this->cli['error']) && preg_match($this->cli['error'], $output)) {
            throw new RuntimeException("The OLT refused \"{$line}\"");
        }
        return $output;
    }

    // Reads until the prompt, paging and answering questions on the way; returns the output.
    private function wait(array $answers, bool $login = false): string
    {
        $timeout = (float) config('isp.olt.cli_timeout', 15);
        $deadline = microtime(true) + $timeout;
        $output = '';
        $mark = 0; // output before this was already answered
        $answered = [];
        while (true) {
            $chunk = $this->link->read(min(1.0, max(0.05, $deadline - microtime(true))));
            if ($chunk !== '') {
                $chunk = preg_replace(['/\e\[[0-9;?]*[A-Za-z]/', '/[\x08\r\x00]/'], '', $chunk);
                $output .= $chunk;
                $this->transcript .= $chunk;
                $deadline = microtime(true) + $timeout;
            }
            $tail = substr($output, max($mark, strlen($output) - 400));
            if (! empty($this->cli['more']) && preg_match($this->cli['more'], $tail)) {
                $output = preg_replace($this->cli['more'], '', $output);
                $mark = strlen($output);
                $this->link->write(' ');
                continue;
            }
            foreach ($answers as $regex => $value) {
                if (preg_match($regex, $tail)) {
                    if (($answered[$regex] = ($answered[$regex] ?? 0) + 1) > 2) {
                        throw new RuntimeException($login ? 'The OLT refused the login (username / password).' : 'The OLT keeps asking the same question.');
                    }
                    $this->link->write($value . $this->link->newline());
                    $mark = strlen($output);
                    continue 2;
                }
            }
            if (preg_match($this->cli['prompt'], $tail)) {
                return $output;
            }
            if (microtime(true) > $deadline) {
                throw new RuntimeException('No prompt from the OLT within ' . $timeout . ' s' . ($login ? ' after connecting' : ''));
            }
        }
    }
}
