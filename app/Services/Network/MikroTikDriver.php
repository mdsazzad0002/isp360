<?php

namespace App\Services\Network;

use App\Models\Connection;
use App\Models\Package;
use App\Models\Router;
use Illuminate\Support\Collection;
use RuntimeException;

// Keeps the router in line with the billing system, for PPPoE and Hotspot:
//  package    -> PPP profile / hotspot user profile with a rate limit (created on demand)
//  connection -> PPP secret / hotspot user, tagged "isp-conn:<id>" so a username change renames it
//  not active -> user disabled and any live session kicked
//  blocks     -> address lists + a forward drop rule + DNS NXDOMAIN, tagged "isp-block:" (syncBlocks)
class MikroTikDriver implements NetworkDriver
{
    public const BLOCK_TAG = 'isp-block:';
    public const BLOCK_LIST_ALL = 'isp-block';
    // RouterOS menus per service. `activeUser` is the field naming the user in the active list.
    public const SERVICES = [
        'pppoe' => ['user' => '/ppp/secret', 'profile' => '/ppp/profile', 'active' => '/ppp/active', 'activeUser' => 'name'],
        'hotspot' => ['user' => '/ip/hotspot/user', 'profile' => '/ip/hotspot/user/profile', 'active' => '/ip/hotspot/active', 'activeUser' => 'user'],
    ];

    public function unmanagedReason(Connection $connection): ?string
    {
        if (! isset(self::SERVICES[$connection->connection_type])) {
            return 'Static IP / DHCP is not pushed to the router automatically.'; // address lists / queues not automated yet
        }
        if (empty($connection->pppoe_username)) {
            return 'No username: nothing to create on the router.';
        }
        if (! $connection->router_id && ! Router::forConnection($connection)) {
            return 'No router: set one on the connection or mark a default router for the branch.';
        }
        return null; // an assigned but inactive router is a failure, not "unmanaged"
    }

    public function sync(Connection $connection): void
    {
        if ($this->unmanagedReason($connection)) {
            return;
        }
        $menu = self::SERVICES[$connection->connection_type];
        $router = Router::forConnection($connection);
        if (! $router) {
            throw new RuntimeException('The router assigned to this connection is inactive.');
        }
        $api = new MikroTikClient($router);
        $connection->loadMissing('package', 'customer');

        $profile = $this->ensureProfile($api, $connection->package, $menu['profile']);
        $enabled = $connection->status === 'active';
        $tag = 'isp-conn:' . $connection->id;

        $desired = [
            'name' => $connection->pppoe_username,
            'profile' => $profile,
            'disabled' => $enabled ? 'false' : 'true',
            'comment' => $tag . ' ' . mb_substr((string) $connection->customer?->name, 0, 60),
        ];
        if ($connection->connection_type === 'pppoe') {
            $desired['service'] = 'pppoe';
        } elseif ($connection->mac_address) {
            $desired['mac-address'] = strtoupper($connection->mac_address); // lock the hotspot login to the device
        }
        if ($connection->pppoe_password) {
            $desired['password'] = $connection->pppoe_password;
        }
        if ($connection->connection_type === 'pppoe' && $connection->ipv6_prefix) {
            $desired['remote-ipv6-prefix'] = $connection->ipv6_prefix; // IPv6 prefix delegation from IPAM
        }

        // A type change (PPPoE <-> Hotspot) leaves the user in the other menu: disable it there.
        foreach (self::SERVICES as $type => $other) {
            if ($type !== $connection->connection_type && ($stale = $this->findUser($api, $other, $connection, $tag, true))) {
                $api->update($other['user'], $stale['.id'], ['disabled' => 'true']);
                $this->kick($api, $other, [$stale['name']]);
            }
        }

        $existing = $this->findUser($api, $menu, $connection, $tag);
        $renamedFrom = $existing && $existing['name'] !== $connection->pppoe_username ? $existing['name'] : null;
        $existing ? $api->update($menu['user'], $existing['.id'], $desired) : $api->create($menu['user'], $desired);

        // Live sessions keep their old profile/state until they reconnect, so kick them
        // when access is removed, the profile changed, or the username was renamed.
        $profileChanged = $existing && ($existing['profile'] ?? null) !== $profile;
        if (! $enabled || $profileChanged || $renamedFrom) {
            $this->kick($api, $menu, array_filter([$connection->pppoe_username, $renamedFrom]));
        }
    }

    public function ensureProfile(MikroTikClient $api, ?Package $package, string $profileMenu = '/ppp/profile'): string
    {
        if (! $package) {
            return 'default';
        }
        $name = $package->network_profile ?: 'isp-pkg-' . $package->id;
        $rate = $package->upload_mbps && $package->download_mbps ? "{$package->upload_mbps}M/{$package->download_mbps}M" : null;

        $existing = $api->first($profileMenu, ['name' => $name]);
        $list = self::profileList($name);
        if (! $existing) {
            // customers on this profile land in an address list, so blocks can target one package
            $data = ['name' => $name, 'rate-limit' => $rate, 'address-list' => $list];
            if ($profileMenu === '/ppp/profile') {
                // new package profiles take the gateway/pool of the router's "default" profile (set once in the setup guide),
                // so a new package works without touching the router
                $default = $api->first($profileMenu, ['name' => 'default']) ?? [];
                $data['local-address'] = $default['local-address'] ?? null;
                $data['remote-address'] = $default['remote-address'] ?? null;
            }
            if ($profileMenu === '/ppp/profile') {
                $data['comment'] = 'ISP package ' . $package->name; // hotspot user profiles have no comment field
            }
            $api->create($profileMenu, array_filter($data));
        } else {
            $changes = [];
            if (! $package->network_profile && $rate && ($existing['rate-limit'] ?? '') !== $rate) {
                $changes['rate-limit'] = $rate; // only manage rate limits of profiles this system created
            }
            if (($existing['address-list'] ?? '') === '') {
                $changes['address-list'] = $list; // never replace an address list set by hand
            }
            if ($changes) {
                $api->update($profileMenu, $existing['.id'], $changes);
            }
        }
        return $name;
    }

    // Address list a profile's online customers are put in.
    public static function profileList(string $profile): string
    {
        return 'isp-profile:' . $profile;
    }

    public static function profileName(Package $package): string
    {
        return $package->network_profile ?: 'isp-pkg-' . $package->id;
    }

    /**
     * Site / IP blocking. Only entries tagged "isp-block:" are touched, so hand-made firewall
     * rules stay. All-customer blocks: address list "isp-block" (a domain in an address list is
     * resolved by the router and kept up to date) + DNS NXDOMAIN for the domain and its
     * subdomains. Package blocks: list "isp-block:pkg:<id>", dropped only for traffic from the
     * package profile's address list. The drop rules sit at the top of the forward chain, before
     * any fasttrack rule.
     */
    public function syncBlocks(Router $router, Collection $blocks): array
    {
        $api = new MikroTikClient($router);
        $warnings = [];
        $lists = [];   // "list|address" => comment
        $dns = [];     // domain => comment
        $rules = [];   // comment => rule
        foreach ($blocks as $block) {
            $tag = self::BLOCK_TAG . $block->id;
            if ($block->scope === 'package') {
                if (! $block->package) {
                    continue;
                }
                $list = self::BLOCK_TAG . 'pkg:' . $block->package_id;
                $srcList = self::profileList(self::profileName($block->package));
                $rules[self::BLOCK_TAG . 'rule:pkg:' . $block->package_id] = ['chain' => 'forward', 'action' => 'drop', 'src-address-list' => $srcList, 'dst-address-list' => $list];
                $warnings = array_merge($warnings, $this->ensureProfileList($api, self::profileName($block->package), $srcList, $block->package->name));
            } else {
                $list = self::BLOCK_LIST_ALL;
                $rules[self::BLOCK_TAG . 'rule:all'] = ['chain' => 'forward', 'action' => 'drop', 'dst-address-list' => $list];
            }
            foreach (self::addresses($block) as $address) {
                $lists[$list . '|' . $address] = $tag;
            }
            if ($block->type === 'domain' && $block->scope === 'all') {
                $dns[$block->value] = $tag;
            }
        }

        // address lists (dynamic rows are the router's own lookups of a domain entry)
        foreach ($api->get('/ip/firewall/address-list') as $row) {
            if (($row['dynamic'] ?? 'false') === 'true' || ! str_starts_with((string) ($row['comment'] ?? ''), self::BLOCK_TAG)) {
                continue;
            }
            $key = $row['list'] . '|' . $row['address'];
            isset($lists[$key]) && $lists[$key] === $row['comment'] ? $lists[$key] = null : $api->delete('/ip/firewall/address-list', $row['.id']);
        }
        foreach (array_filter($lists) as $key => $comment) {
            [$list, $address] = explode('|', $key, 2);
            try {
                $api->create('/ip/firewall/address-list', ['list' => $list, 'address' => $address, 'comment' => $comment]);
            } catch (\Throwable $e) {
                $warnings[] = "{$address}: " . $e->getMessage();
            }
        }

        foreach ($api->get('/ip/dns/static') as $row) {
            if (! str_starts_with((string) ($row['comment'] ?? ''), self::BLOCK_TAG)) {
                continue;
            }
            $name = $row['name'] ?? '';
            isset($dns[$name]) && $dns[$name] === $row['comment'] && ($row['type'] ?? '') === 'NXDOMAIN' ? $dns[$name] = null : $api->delete('/ip/dns/static', $row['.id']);
        }
        foreach (array_filter($dns) as $name => $comment) {
            $api->create('/ip/dns/static', ['name' => $name, 'type' => 'NXDOMAIN', 'match-subdomain' => 'yes', 'comment' => $comment]);
        }

        $filters = $api->get('/ip/firewall/filter');
        foreach ($filters as $row) {
            $comment = (string) ($row['comment'] ?? '');
            if (! str_starts_with($comment, self::BLOCK_TAG)) {
                continue;
            }
            $want = $rules[$comment] ?? null;
            $same = $want && collect($want)->every(fn ($v, $k) => ($row[$k] ?? null) === $v) && ($row['disabled'] ?? 'false') !== 'true';
            $same ? $rules[$comment] = null : $api->delete('/ip/firewall/filter', $row['.id']);
        }
        $top = collect($filters)->first(fn ($r) => ($r['dynamic'] ?? 'false') !== 'true' && ! str_starts_with((string) ($r['comment'] ?? ''), self::BLOCK_TAG));
        foreach (array_filter($rules) as $comment => $rule) {
            $api->create('/ip/firewall/filter', $rule + ['comment' => $comment] + ($top ? ['place-before' => $top['.id']] : []));
        }
        return array_values(array_unique($warnings));
    }

    // A package block matches customers by their profile's address list: set it where it is empty
    // (profiles made before blocking existed); an address list set by hand is left alone.
    private function ensureProfileList(MikroTikClient $api, string $name, string $list, string $packageName): array
    {
        $warnings = [];
        foreach (['/ppp/profile', '/ip/hotspot/user/profile'] as $menu) {
            $profile = $api->first($menu, ['name' => $name]);
            if (! $profile || ($profile['address-list'] ?? '') === $list) {
                continue;
            }
            if (($profile['address-list'] ?? '') === '') {
                $api->update($menu, $profile['.id'], ['address-list' => $list]);
            } else {
                $warnings[] = "Profile {$name} (package {$packageName}) puts customers in address list \"{$profile['address-list']}\", so its package block can't match them.";
            }
        }
        return $warnings;
    }

    // A domain is listed with and without "www."; an IP / subnet as it is.
    private static function addresses($block): array
    {
        if ($block->type !== 'domain' || str_starts_with($block->value, 'www.')) {
            return [$block->value];
        }
        return [$block->value, 'www.' . $block->value];
    }

    // Compares the router with what billing expects, without changing anything.
    public function verify(Connection $connection): array
    {
        if ($reason = $this->unmanagedReason($connection)) {
            return [];
        }
        $router = Router::forConnection($connection);
        if (! $router) {
            return ['The router assigned to this connection is inactive.'];
        }
        $menu = self::SERVICES[$connection->connection_type];
        $api = new MikroTikClient($router);
        $connection->loadMissing('package');

        $user = $this->findUser($api, $menu, $connection, 'isp-conn:' . $connection->id);
        if (! $user) {
            return ["{$connection->pppoe_username} does not exist on {$router->name}."];
        }
        $issues = [];
        if ($user['name'] !== $connection->pppoe_username) {
            $issues[] = "Router username is {$user['name']}, billing has {$connection->pppoe_username}.";
        }
        $shouldBeEnabled = $connection->status === 'active';
        $isEnabled = ($user['disabled'] ?? 'false') !== 'true';
        if ($shouldBeEnabled !== $isEnabled) {
            $issues[] = 'Router account is ' . ($isEnabled ? 'ENABLED' : 'DISABLED') . " but the connection is {$connection->status}.";
        }
        $profile = $connection->package ? ($connection->package->network_profile ?: 'isp-pkg-' . $connection->package->id) : 'default';
        if (($user['profile'] ?? 'default') !== $profile) {
            $issues[] = "Router profile is " . ($user['profile'] ?? 'default') . ", package needs {$profile}.";
        }
        if (! $shouldBeEnabled && self::activeSession($api, $connection)) {
            $issues[] = "{$connection->pppoe_username} still has a live session although the connection is {$connection->status}.";
        }
        return $issues;
    }

    // Live session of a connection on its router (either service), or null.
    public static function activeSession(MikroTikClient $api, Connection $connection): ?array
    {
        $menu = self::SERVICES[$connection->connection_type] ?? null;
        return $menu ? $api->first($menu['active'], [$menu['activeUser'] => $connection->pppoe_username]) : null;
    }

    // One traffic reading of the live session, null when offline. Directions are the customer's:
    // download = router tx towards the customer. PPPoE gives a rate (monitor-traffic on the dynamic
    // <pppoe-user> interface); hotspot only has byte counters, so the caller derives the rate.
    /** @return array{down_bps?: float, up_bps?: float, down_bytes?: float, up_bytes?: float, address: ?string, uptime: ?string}|null */
    public static function trafficSample(MikroTikClient $api, Connection $connection): ?array
    {
        $session = self::activeSession($api, $connection);
        if (! $session) {
            return null;
        }
        $base = ['address' => $session['address'] ?? null, 'uptime' => $session['uptime'] ?? null];
        if ($connection->connection_type === 'hotspot') {
            return $base + ['down_bytes' => (float) ($session['bytes-out'] ?? 0), 'up_bytes' => (float) ($session['bytes-in'] ?? 0)];
        }
        $r = $api->run('/interface/monitor-traffic', ['interface' => "<pppoe-{$connection->pppoe_username}>", 'once' => ''], 10)[0] ?? [];
        return $base + ['down_bps' => (float) ($r['tx-bits-per-second'] ?? 0), 'up_bps' => (float) ($r['rx-bits-per-second'] ?? 0)];
    }

    // Router health for the live monitor: /system/resource, plus /system/health where the board has
    // sensors (CHR and x86 have none, and some boards answer that menu with an error).
    public static function resourceSample(MikroTikClient $api): array
    {
        $r = $api->get('/system/resource');
        $num = fn (string $key) => isset($r[$key]) && is_numeric($r[$key]) ? (float) $r[$key] : null;
        $health = [];
        try {
            foreach ($api->get('/system/health') as $h) {
                if (isset($h['name'], $h['value'])) {
                    $health[] = ['name' => $h['name'], 'value' => $h['value'], 'unit' => $h['type'] ?? ''];
                }
            }
        } catch (\Throwable $th) {
            // no sensors: the monitor shows no health row
        }
        return [
            'cpu' => $num('cpu-load'),
            'cpu_count' => $num('cpu-count'),
            'memory_total' => $num('total-memory'),
            'memory_free' => $num('free-memory'),
            'disk_total' => $num('total-hdd-space'),
            'disk_free' => $num('free-hdd-space'),
            'uptime' => $r['uptime'] ?? '',
            'version' => $r['version'] ?? '',
            'board' => $r['board-name'] ?? '',
            'architecture' => $r['architecture-name'] ?? '',
            'health' => $health,
        ];
    }

    // Configured interfaces with their byte counters; the monitor derives rates from two readings.
    // The dynamic ones (one <pppoe-user> per session) are left out: thousands on a busy router.
    public static function interfaceList(MikroTikClient $api): array
    {
        $rows = $api->get('/interface', ['dynamic' => 'false', '.proplist' => 'name,type,running,disabled,rx-byte,tx-byte,link-downs,last-link-down-time,comment']);
        $out = [];
        foreach ($rows as $i) {
            if (! isset($i['name']) || str_starts_with($i['name'], '<')) {
                continue;
            }
            $out[] = [
                'name' => $i['name'],
                'type' => $i['type'] ?? '',
                'running' => ($i['running'] ?? 'false') === 'true',
                'disabled' => ($i['disabled'] ?? 'false') === 'true',
                'rx_bytes' => (float) ($i['rx-byte'] ?? 0),
                'tx_bytes' => (float) ($i['tx-byte'] ?? 0),
                'link_downs' => (int) ($i['link-downs'] ?? 0),
                'last_link_down' => $i['last-link-down-time'] ?? null,
                'comment' => $i['comment'] ?? '',
            ];
        }
        return $out;
    }

    // PPPoE + Hotspot sessions right now (ids only, to keep the answer small on a busy router).
    public static function onlineCount(MikroTikClient $api): int
    {
        return count($api->get('/ppp/active', ['.proplist' => '.id'])) + count($api->get('/ip/hotspot/active', ['.proplist' => '.id']));
    }

    // One live rate reading of an interface, null when the router has no interface of that name.
    /** @return array{rx_bps: float, tx_bps: float}|null */
    public static function interfaceTraffic(MikroTikClient $api, string $name): ?array
    {
        $r = $api->run('/interface/monitor-traffic', ['interface' => $name, 'once' => ''], 10)[0] ?? null;
        if (! $r || ($r['name'] ?? $name) !== $name) {
            return null;
        }
        return ['rx_bps' => (float) ($r['rx-bits-per-second'] ?? 0), 'tx_bps' => (float) ($r['tx-bits-per-second'] ?? 0)];
    }

    // Why there is no live session, read from the router (read-only), so "offline" always comes with a cause.
    public static function offlineReason(MikroTikClient $api, Connection $connection): string
    {
        $menu = self::SERVICES[$connection->connection_type];
        $server = $connection->connection_type === 'pppoe' ? '/interface/pppoe-server/server' : '/ip/hotspot';
        $user = $api->first($menu['user'], ['name' => $connection->pppoe_username]);
        if (! $user) {
            return "Account {$connection->pppoe_username} does not exist on the router (run sync).";
        }
        if (($user['disabled'] ?? 'false') === 'true') {
            return "Account {$connection->pppoe_username} is disabled on the router.";
        }
        $servers = $api->get($server);
        if (! $servers) {
            return 'No ' . ($connection->connection_type === 'pppoe' ? 'PPPoE' : 'hotspot') . ' server on this router: nobody can connect to it.';
        }
        if (! collect($servers)->contains(fn ($s) => ($s['disabled'] ?? 'false') !== 'true')) {
            return 'The ' . ($connection->connection_type === 'pppoe' ? 'PPPoE' : 'hotspot') . ' server on this router is disabled.';
        }
        $out = $user['last-logged-out'] ?? null;
        if ($out === null || str_starts_with($out, '1970')) {
            return 'Never logged in: check the customer router (username/password, cable, ONU).';
        }
        $why = $user['last-disconnect-reason'] ?? null;
        return "Last logged out {$out}" . ($why ? " ({$why})" : '') . '.';
    }

    private function kick(MikroTikClient $api, array $menu, array $names): void
    {
        foreach ($names as $name) {
            foreach ($api->get($menu['active'], [$menu['activeUser'] => $name]) as $session) {
                $api->delete($menu['active'], $session['.id']);
            }
        }
    }

    // $tagOnly: only users this system created (never a hand-made user that shares the name)
    private function findUser(MikroTikClient $api, array $menu, Connection $connection, string $tag, bool $tagOnly = false): ?array
    {
        $byName = $tagOnly ? null : $api->first($menu['user'], ['name' => $connection->pppoe_username]);
        if ($byName) {
            return $byName;
        }
        foreach ($api->get($menu['user']) as $user) {
            $comment = (string) ($user['comment'] ?? '');
            if ($comment === $tag || str_starts_with($comment, $tag . ' ')) {
                return $user;
            }
        }
        return null;
    }
}
