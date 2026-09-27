<?php

namespace App\Services\Network;

use App\Models\Connection;
use App\Models\Router;
use App\Services\Isp\AuditLogger;
use RuntimeException;

// The customer page's diagnostic terminal. It is NOT a shell: each line is parsed into one
// of the commands below, and each runs through the router's REST API. Nothing runs on the
// server itself. The terminal is opened for one connection; commands that take a username
// act on that connection by default, or on another connection of the branch whose
// username was pasted in.
class NetworkTerminalService
{
    public const COMMANDS = [
        'help' => 'List commands',
        'info [user]' => 'Connection details from the billing system',
        'session [user]' => 'Live session on the router: IP, MAC, uptime',
        'ping [ip|host] [count]' => 'Ping from the router (default: the session IP, 4 packets, max 10)',
        'secret [user]' => 'Router account: profile, disabled, last logout, last MAC',
        'traffic [user]' => 'Current download/upload speed of the session',
        'log [user|all] [lines]' => 'Router log lines about the user (default 20, max 100)',
        'verify [user]' => 'Compare the router with billing (account, enabled, profile) and update the sync status',
        'diagnose [user]' => 'Full check in one go: info, verify, session, ping, recent log',
        'router' => 'Router identity, RouterOS version, uptime, CPU and memory',
        'btest [address] [user=] [password=] [direction=] [duration=] [protocol=]' => 'Bandwidth test with a graph (default: session IP + the connection\'s own login; also /tool bandwidth-test ...)',
        'kick [user]' => 'Disconnect the live session (it reconnects by itself)',
        'sync' => 'Push this connection\'s account/profile to the router again',
        'ros <path> [field=value ...]' => 'Read-only RouterOS menu, e.g. ros /ppp/active or ros /interface name=ether1',
        'clear' => 'Clear the screen',
    ];

    /** @return array{lines: array<int, array{text: string, tone?: string}>} */
    public static function run(string $line, Connection $context, array $can): array
    {
        $args = preg_split('/\s+/', trim($line)) ?: [];
        $cmd = strtolower(array_shift($args) ?? '');
        // RouterOS spelling pasted from WinBox/terminal: /tool bandwidth-test address=... user=...
        if (in_array($cmd, ['/tool', 'tool'], true) && strtolower($args[0] ?? '') === 'bandwidth-test') {
            array_shift($args);
            $cmd = 'btest';
        } elseif (in_array($cmd, ['bandwidth-test', '/tool/bandwidth-test'], true)) {
            $cmd = 'btest';
        }
        $out = new TerminalOutput();

        try {
            match ($cmd) {
                'help', '?' => self::help($out),
                'info' => self::info($out, self::target($context, $args[0] ?? null)),
                'session', 'status' => self::session($out, self::target($context, $args[0] ?? null)),
                'ping' => self::ping($out, $context, $args),
                'secret' => self::secret($out, self::target($context, $args[0] ?? null), ! empty($can['secret'])),
                'traffic' => self::traffic($out, self::target($context, $args[0] ?? null)),
                'log' => self::log($out, $context, $args),
                'verify' => self::verify($out, self::target($context, $args[0] ?? null)),
                'diagnose', 'diag' => self::diagnose($out, self::target($context, $args[0] ?? null), $can),
                'router' => self::router($out, $context),
                'btest' => self::btest($out, $context, $args, ! empty($can['action']) || ! empty($can['router'])),
                'kick' => self::kick($out, self::target($context, $args[0] ?? null), ! empty($can['action'])),
                'sync' => self::sync($out, $context, ! empty($can['action'])),
                'ros' => self::ros($out, $context, $args, ! empty($can['router']), ! empty($can['secret'])),
                '' => null,
                default => $out->error("Unknown command '{$cmd}'. Type help."),
            };
        } catch (RuntimeException $e) {
            $out->error($e->getMessage());
        }
        return ['lines' => $out->lines];
    }

    private static function help(TerminalOutput $out): void
    {
        $out->line('Commands ([user] = PPPoE/hotspot username; default is this connection):', 'muted');
        foreach (self::COMMANDS as $syntax => $text) {
            $out->line(str_pad($syntax, 32) . $text);
        }
    }

    // The connection a username refers to (same branch), or the terminal's own connection.
    private static function target(Connection $context, ?string $username): Connection
    {
        if ($username === null || $username === $context->pppoe_username) {
            return $context;
        }
        $found = Connection::where('branch_id', $context->branch_id)->where('pppoe_username', $username)->first();
        if (! $found) {
            throw new RuntimeException("No connection with username '{$username}' in this branch.");
        }
        return $found;
    }

    private static function api(Connection $connection): array
    {
        $router = Router::forConnection($connection);
        if (! $router) {
            throw new RuntimeException("Connection {$connection->code} has no router (set one on the connection or a default router).");
        }
        return [new MikroTikClient($router), $router];
    }

    private static function menu(Connection $connection): array
    {
        $menu = MikroTikDriver::SERVICES[$connection->connection_type] ?? null;
        if (! $menu || ! $connection->pppoe_username) {
            throw new RuntimeException("Connection {$connection->code} is {$connection->connection_type}: no router account to look up.");
        }
        return $menu;
    }

    private static function info(TerminalOutput $out, Connection $c): void
    {
        $c->loadMissing(['customer:id,code,name,phone', 'package:id,name,download_mbps,upload_mbps,network_profile', 'box:id,name', 'router:id,name,host']);
        $router = Router::forConnection($c);
        $out->pairs([
            'connection' => "{$c->code} ({$c->status})",
            'customer' => "{$c->customer?->name} · {$c->customer?->code} · {$c->customer?->phone}",
            'type' => $c->connection_type,
            'username' => $c->pppoe_username ?: '—',
            'static ip / mac' => ($c->static_ip ?: '—') . ' / ' . ($c->mac_address ?: '—'),
            'package' => $c->package ? "{$c->package->name} ({$c->package->download_mbps}/{$c->package->upload_mbps} Mbps, profile " . ($c->package->network_profile ?: 'auto') . ')' : '—',
            'box' => $c->box?->name ?: '—',
            'router' => $router ? "{$router->name} ({$router->host})" : 'none',
            'router sync' => (NetworkStatus::LABELS[$c->network_sync_status] ?? $c->network_sync_status)
                . ($c->network_sync_status === 'failed' ? ': ' . $c->network_sync_error : ($c->network_sync_note ? ': ' . $c->network_sync_note : '')),
            'last pushed' => $c->network_synced_at ? (string) $c->network_synced_at : 'never',
            'last verified' => $c->network_checked_at ? (string) $c->network_checked_at : 'never',
        ]);
    }

    private static function session(TerminalOutput $out, Connection $c): void
    {
        [$api, $router] = self::api($c);
        $session = MikroTikDriver::activeSession($api, $c);
        if (! $session) {
            $out->line("{$c->pppoe_username} is OFFLINE on {$router->name}.", 'warn');
            return;
        }
        $out->line("{$c->pppoe_username} is ONLINE on {$router->name}", 'ok');
        $out->pairs(array_intersect_key($session, array_flip(['address', 'caller-id', 'mac-address', 'uptime', 'service', 'encoding', 'limit-bytes-in', 'limit-bytes-out', 'bytes-in', 'bytes-out', 'login-by'])));
    }

    private static function ping(TerminalOutput $out, Connection $context, array $args): void
    {
        $target = $args[0] ?? null;
        $count = (int) ($args[1] ?? 4);
        [$api, $router] = self::api($context);
        if ($target === null) {
            $session = MikroTikDriver::activeSession($api, $context);
            $target = $session['address'] ?? $context->static_ip;
            if (! $target) {
                throw new RuntimeException('No IP to ping: the connection is offline and has no static IP. Use ping <ip>.');
            }
        }
        if (! preg_match('/^[A-Za-z0-9.\-:]{1,253}$/', $target)) {
            throw new RuntimeException('Give an IP address or host name to ping.');
        }
        $count = max(1, min(10, $count));

        $out->line("PING {$target} from {$router->name}, {$count} packets", 'muted');
        $rows = $api->run('/ping', ['address' => $target, 'count' => (string) $count], 8 + $count * 2);
        $last = [];
        foreach ($rows as $r) {
            $last = $r;
            if (isset($r['status'])) {
                $out->line("seq={$r['seq']} {$r['status']}", 'warn');
            } elseif (isset($r['time'])) {
                $out->line("seq={$r['seq']} from {$r['host']} size={$r['size']} ttl={$r['ttl']} time={$r['time']}");
            }
        }
        if ($last) {
            $loss = (int) ($last['packet-loss'] ?? 100);
            $out->line("--- {$last['sent']} sent, {$last['received']} received, {$loss}% loss"
                . (isset($last['avg-rtt']) ? ", rtt min/avg/max {$last['min-rtt']}/{$last['avg-rtt']}/{$last['max-rtt']}" : ''), $loss === 0 ? 'ok' : ($loss < 100 ? 'warn' : 'error'));
        }
    }

    private static function secret(TerminalOutput $out, Connection $c, bool $showPassword): void
    {
        $menu = self::menu($c);
        [$api, $router] = self::api($c);
        $user = $api->first($menu['user'], ['name' => $c->pppoe_username]);
        if (! $user) {
            $out->line("{$c->pppoe_username} does not exist on {$router->name}. Try: sync", 'warn');
            return;
        }
        if (! $showPassword) {
            unset($user['password']);
        }
        $out->pairs(array_intersect_key($user, array_flip(['name', 'password', 'profile', 'service', 'disabled', 'remote-address', 'local-address', 'last-logged-out', 'last-caller-id', 'last-disconnect-reason', 'limit-uptime', 'comment'])));
    }

    private static function traffic(TerminalOutput $out, Connection $c): void
    {
        $menu = self::menu($c);
        [$api] = self::api($c);
        $session = MikroTikDriver::activeSession($api, $c);
        if (! $session) {
            $out->line("{$c->pppoe_username} is offline: no traffic.", 'warn');
            return;
        }
        if ($c->connection_type === 'hotspot') {
            $out->pairs(['bytes in' => self::bytes($session['bytes-in'] ?? 0), 'bytes out' => self::bytes($session['bytes-out'] ?? 0), 'uptime' => $session['uptime'] ?? '']);
            return;
        }
        // PPPoE server creates a dynamic interface named <pppoe-username>
        $rows = $api->run('/interface/monitor-traffic', ['interface' => "<pppoe-{$c->pppoe_username}>", 'once' => ''], 10);
        $r = $rows[0] ?? [];
        // the router's tx is the customer's download
        $out->pairs([
            'download' => self::bps($r['tx-bits-per-second'] ?? 0),
            'upload' => self::bps($r['rx-bits-per-second'] ?? 0),
            'packets down/up' => ($r['tx-packets-per-second'] ?? 0) . ' / ' . ($r['rx-packets-per-second'] ?? 0) . ' pps',
        ]);
    }

    private static function log(TerminalOutput $out, Connection $context, array $args): void
    {
        $all = ($args[0] ?? '') === 'all';
        $c = $all ? $context : self::target($context, isset($args[0]) && ! ctype_digit($args[0]) ? $args[0] : null);
        $lines = (int) (ctype_digit($args[0] ?? '') ? $args[0] : ($args[1] ?? 20));
        $lines = max(1, min(100, $lines));
        [$api, $router] = self::api($c);

        $rows = $api->get('/log');
        if (! $all) {
            $needle = strtolower((string) $c->pppoe_username);
            $rows = array_values(array_filter($rows, fn ($r) => $needle !== '' && str_contains(strtolower($r['message'] ?? ''), $needle)));
        }
        $rows = array_slice($rows, -$lines);
        $out->line(($all ? 'Last' : "Last lines about {$c->pppoe_username} of") . " {$router->name} log (" . count($rows) . ')', 'muted');
        foreach ($rows as $r) {
            $topics = $r['topics'] ?? '';
            $out->line(($r['time'] ?? '') . '  ' . str_pad($topics, 22) . ' ' . ($r['message'] ?? ''), str_contains($topics, 'error') ? 'error' : (str_contains($topics, 'warning') ? 'warn' : null));
        }
    }

    private static function verify(TerminalOutput $out, Connection $c): void
    {
        $result = NetworkStatus::verify($c);
        $label = NetworkStatus::LABELS[$result['status']];
        $tone = ['synced' => 'ok', 'not_managed' => 'muted', 'mismatch' => 'warn', 'failed' => 'error'][$result['status']] ?? null;
        $out->line("{$c->code}: {$label}", $tone);
        foreach ($result['issues'] as $issue) {
            $out->line('  - ' . $issue, $tone);
        }
        if (in_array($result['status'], ['mismatch', 'failed'], true)) {
            $out->line('  Fix: run sync (needs the connection action permission), then verify again.', 'muted');
        }
    }

    // The usual first-line check for "customer has a problem", in one command.
    private static function diagnose(TerminalOutput $out, Connection $c, array $can): void
    {
        $step = function (string $title, callable $fn) use ($out) {
            $out->line("== {$title}", 'muted');
            try {
                $fn();
            } catch (RuntimeException $e) {
                $out->error($e->getMessage());
            }
        };
        $step('connection', fn () => self::info($out, $c));
        $step('router vs billing', fn () => self::verify($out, $c));
        if (isset(MikroTikDriver::SERVICES[$c->connection_type]) && $c->pppoe_username) {
            $step('live session', fn () => self::session($out, $c));
        }
        $step('ping', fn () => self::ping($out, $c, []));
        if ($c->pppoe_username) {
            $step('recent log', fn () => self::log($out, $c, [$c->pppoe_username, '8']));
        }
        $c->refresh();
        $out->line('== summary', 'muted');
        if ($c->status === 'active' && $c->network_sync_status !== 'synced' && $c->network_sync_status !== 'not_managed') {
            $out->line("Billing says ACTIVE but the router is not confirmed ({$c->network_sync_status}). Run sync.", 'warn');
        } elseif ($c->status !== 'active') {
            $out->line("Connection is {$c->status} in billing: no internet is expected until it is active.", 'warn');
        } else {
            $out->line('Billing and router agree. If the customer is offline, check the ONU/cable/router at the customer side.', 'ok');
        }
    }

    private static function router(TerminalOutput $out, Connection $context): void
    {
        [$api, $router] = self::api($context);
        $identity = $api->get('/system/identity');
        $res = $api->get('/system/resource');
        $out->pairs([
            'router' => "{$router->name} ({$router->host})",
            'identity' => $identity['name'] ?? '',
            'board' => $res['board-name'] ?? '',
            'routeros' => $res['version'] ?? '',
            'uptime' => $res['uptime'] ?? '',
            'cpu load' => isset($res['cpu-load']) ? $res['cpu-load'] . '% of ' . ($res['cpu-count'] ?? '?') . ' cpu' : '',
            'memory free' => isset($res['free-memory']) ? self::bytes($res['free-memory']) . ' of ' . self::bytes($res['total-memory'] ?? 0) : '',
            'ppp online' => (string) count($api->get('/ppp/active')),
        ]);
    }

    // Bandwidth test from the connection's router to a MikroTik running the btest server
    // (default target: the customer's session IP). Heavy on the line, so short and permissioned.
    private static function btest(TerminalOutput $out, Connection $context, array $args, bool $allowed): void
    {
        if (! $allowed) {
            throw new RuntimeException('Bandwidth test loads the line: it needs the "Connection Activate / Suspend" or "Routers (MikroTik)" permission.');
        }
        $opts = [];
        foreach ($args as $arg) {
            if (preg_match('/^<[a-z]+>$/i', $arg)) {
                continue; // an unfilled docs placeholder (<ip>) means "use the default"
            }
            if (preg_match('/^([a-z\-]+)=(.*)$/i', $arg, $m)) {
                $value = trim($m[2], '"\'');
                if (preg_match('/^<[a-z]+>$/i', $value) || $value === '') {
                    continue; // user=<user> / password= -> default
                }
                $opts[strtolower($m[1])] = $value;
            } elseif (! isset($opts['address'])) {
                $opts['address'] = $arg;
            } else {
                throw new RuntimeException("Unexpected '{$arg}'. Usage: btest [address] user=<u> password=<p> direction=both duration=5s");
            }
        }
        foreach (array_keys($opts) as $key) {
            if (! in_array($key, ['address', 'user', 'password', 'direction', 'duration', 'protocol', 'connection-count', 'local-tx-speed', 'remote-tx-speed'], true)) {
                throw new RuntimeException("Unknown option '{$key}'.");
            }
        }

        [$api, $router] = self::api($context);
        if (empty($opts['address'])) {
            $session = MikroTikDriver::activeSession($api, $context);
            $opts['address'] = $session['address'] ?? $context->static_ip;
            if (! $opts['address']) {
                throw new RuntimeException('No address: the connection is offline and has no static IP. Use btest <ip> ...');
            }
        }
        if (! filter_var($opts['address'], FILTER_VALIDATE_IP)) {
            throw new RuntimeException('address must be an IP address: btest <ip> user=<user> password=<password>');
        }
        $direction = strtolower($opts['direction'] ?? 'both');
        if (! in_array($direction, ['both', 'receive', 'transmit'], true)) {
            throw new RuntimeException('direction must be both, receive or transmit.');
        }
        $protocol = strtolower($opts['protocol'] ?? 'tcp');
        if (! in_array($protocol, ['tcp', 'udp'], true)) {
            throw new RuntimeException('protocol must be tcp or udp.');
        }
        $seconds = (int) preg_replace('/\D/', '', $opts['duration'] ?? '5') ?: 5;
        $seconds = max(1, min(15, $seconds));

        // No login given: use the connection's own username/password (the customer's
        // MikroTik CPE usually has the same). Never printed or logged.
        $ownLogin = ! isset($opts['user']) && ! isset($opts['password']);
        if (! isset($opts['user']) && $context->pppoe_username) {
            $opts['user'] = $context->pppoe_username;
        }
        if (! isset($opts['password']) && $context->pppoe_password) {
            $opts['password'] = $context->pppoe_password;
        }

        $payload = array_filter([
            'address' => $opts['address'],
            'user' => $opts['user'] ?? null,
            'password' => $opts['password'] ?? null,
            'direction' => $direction,
            'protocol' => $protocol,
            'duration' => $seconds . 's',
            'connection-count' => $opts['connection-count'] ?? null,
            'local-tx-speed' => $opts['local-tx-speed'] ?? null,
            'remote-tx-speed' => $opts['remote-tx-speed'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        $out->line("BANDWIDTH TEST {$router->name} -> {$opts['address']} ({$protocol}, {$direction}, {$seconds}s)"
            . (isset($opts['user']) ? ", login {$opts['user']}" . ($ownLogin ? " (connection's own username/password)" : '') : ', no login'), 'muted');
        AuditLogger::log('terminal.btest', $context, null, ['address' => $opts['address'], 'direction' => $direction, 'protocol' => $protocol, 'seconds' => $seconds]);
        @set_time_limit($seconds + 40);
        // one bandwidth test per router at a time: two would share (and falsify) the line
        $lock = \Illuminate\Support\Facades\Cache::lock("btest:router:{$router->id}", $seconds + 15);
        if (! $lock->get()) {
            throw new RuntimeException("Another bandwidth test is running on {$router->name}. Try again in a few seconds.");
        }
        try {
            $rows = $api->run('/tool/bandwidth-test', $payload, $seconds + 10);
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'timed out')) {
                // RouterOS keeps retrying instead of answering on a bad login or a dead target
                throw new RuntimeException("No result within " . ($seconds + 10) . "s: wrong user/password, bandwidth-server disabled on {$opts['address']}, or the target is unreachable/blocked (TCP 2000).");
            }
            throw $e;
        } finally {
            $lock->release();
        }

        $last = [];
        $points = [];
        foreach ($rows as $r) {
            $last = $r;
            if (($r['status'] ?? '') === 'running' || ($r['status'] ?? '') === 'done testing') {
                $out->line(str_pad($r['duration'] ?? '', 5) . ' tx ' . str_pad(self::bps($r['tx-current'] ?? 0), 12) . ' rx ' . self::bps($r['rx-current'] ?? 0));
                $points[] = ['t' => (int) preg_replace('/\D/', '', $r['duration'] ?? '0'), 'tx' => (float) ($r['tx-current'] ?? 0), 'rx' => (float) ($r['rx-current'] ?? 0)];
            }
        }
        $status = $last['status'] ?? 'no result';
        if ($status !== 'done testing') {
            // e.g. "authentication failed", "can not connect"
            $out->error("Test ended: {$status}. The target must be a MikroTik with /tool bandwidth-server enabled, reachable, and the user/password must match.");
            return;
        }
        // the router's tx is what goes towards the target (the customer's download when the target is the CPE)
        $out->pairs(array_filter([
            'status' => $status,
            'tx average' => self::bps($last['tx-total-average'] ?? 0) . ($direction === 'receive' ? ' (not tested)' : ''),
            'rx average' => self::bps($last['rx-total-average'] ?? 0) . ($direction === 'transmit' ? ' (not tested)' : ''),
            'lost packets' => $last['lost-packets'] ?? ($protocol === 'udp' ? '0' : 'n/a (tcp)'),
            'router cpu' => isset($last['local-cpu-load']) ? $last['local-cpu-load'] . '%' : '',
            'target cpu' => isset($last['remote-cpu-load']) ? $last['remote-cpu-load'] . '%' : '',
        ], fn ($v) => $v !== ''));
        if ($points) {
            $out->chart('btest', [
                'title' => "{$opts['address']} · {$protocol} · {$direction}",
                'direction' => $direction,
                'points' => $points,
                'tx_avg' => (float) ($last['tx-total-average'] ?? 0),
                'rx_avg' => (float) ($last['rx-total-average'] ?? 0),
                'package_mbps' => $context->package ? ['down' => (int) $context->package->download_mbps, 'up' => (int) $context->package->upload_mbps] : null,
            ]);
        }
        $out->line('tx = router -> target, rx = target -> router. Router CPU near 100% means the router, not the line, is the limit.', 'muted');
    }

    private static function kick(TerminalOutput $out, Connection $c, bool $allowed): void
    {
        if (! $allowed) {
            throw new RuntimeException('You need the "Connection Activate / Suspend" permission to kick a session.');
        }
        $menu = self::menu($c);
        [$api, $router] = self::api($c);
        $sessions = $api->get($menu['active'], [$menu['activeUser'] => $c->pppoe_username]);
        foreach ($sessions as $s) {
            $api->delete($menu['active'], $s['.id']);
        }
        AuditLogger::log('terminal.kick', $c, null, ['username' => $c->pppoe_username, 'sessions' => count($sessions)]);
        $out->line(count($sessions) ? "Disconnected {$c->pppoe_username} on {$router->name}." : "{$c->pppoe_username} had no live session.", count($sessions) ? 'ok' : 'warn');
    }

    private static function sync(TerminalOutput $out, Connection $c, bool $allowed): void
    {
        if (! $allowed) {
            throw new RuntimeException('You need the "Connection Activate / Suspend" permission to sync.');
        }
        \App\Jobs\SyncConnectionToNetwork::dispatchSync($c->id);
        $c->refresh();
        AuditLogger::log('terminal.sync', $c, null, ['error' => $c->network_sync_error]);
        $c->network_sync_error ? $out->error('Sync failed: ' . $c->network_sync_error) : $out->line("{$c->code} synced to the router.", 'ok');
    }

    private static function ros(TerminalOutput $out, Connection $context, array $args, bool $allowed, bool $showSecrets): void
    {
        if (! $allowed) {
            throw new RuntimeException('You need the "Routers (MikroTik)" permission for ros.');
        }
        $path = $args[0] ?? '';
        if (! preg_match('#^/[a-z0-9\-/]+$#', $path) || str_contains($path, '..')) {
            throw new RuntimeException('Usage: ros <path> [field=value ...], e.g. ros /ppp/active');
        }
        $query = [];
        foreach (array_slice($args, 1) as $pair) {
            if (! preg_match('/^([a-z0-9\-.]+)=(.+)$/i', $pair, $m)) {
                throw new RuntimeException("Filter '{$pair}' must look like field=value.");
            }
            $query[$m[1]] = $m[2];
        }
        [$api, $router] = self::api($context);
        $rows = $api->get($path, $query);
        if (array_is_list($rows) === false) {
            $rows = [$rows];
        }
        $total = count($rows);
        $out->line("{$router->name} {$path}: {$total} row(s)" . ($total > 50 ? ', showing 50' : ''), 'muted');
        foreach (array_slice($rows, 0, 50) as $i => $row) {
            if (! $showSecrets) {
                foreach ($row as $k => $v) {
                    if (preg_match('/password|secret|passphrase|key$/i', $k)) {
                        $row[$k] = '••••';
                    }
                }
            }
            $out->line('#' . $i . '  ' . implode('  ', array_map(fn ($k, $v) => "{$k}=" . (is_scalar($v) ? $v : json_encode($v)), array_keys($row), $row)));
        }
        AuditLogger::log('terminal.ros', $context, null, ['path' => $path, 'query' => $query]);
    }

    private static function bps($bits): string
    {
        $bits = (float) $bits;
        return $bits >= 1e6 ? round($bits / 1e6, 2) . ' Mbps' : ($bits >= 1e3 ? round($bits / 1e3, 1) . ' kbps' : ((int) $bits) . ' bps');
    }

    private static function bytes($b): string
    {
        $b = (float) $b;
        return $b >= 1073741824 ? round($b / 1073741824, 2) . ' GB' : ($b >= 1048576 ? round($b / 1048576, 1) . ' MB' : round($b / 1024, 1) . ' KB');
    }
}
