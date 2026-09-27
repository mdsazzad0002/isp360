<?php

namespace App\Services\Network;

use App\Models\Connection;
use App\Models\Package;
use App\Models\Router;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

// Keeps FreeRADIUS (SQL) in line with the billing system, for PPPoE and Hotspot users on any NAS
// that authenticates with RADIUS (MikroTik, Huawei, Cisco, Juniper...):
//  package    -> radgroupreply group "isp-pkg-<id>" with the NAS vendors' speed attributes
//  connection -> radcheck Cleartext-Password (+ Calling-Station-Id for a hotspot MAC lock),
//                radreply Framed-IP-Address for a static IP, radusergroup -> its package group
//  not active -> radcheck Auth-Type := Reject, and the live session is ended with a
//                Disconnect-Request (RFC 5176) so it can't carry on until it reconnects
//  speed change on a live session -> CoA-Request with the new rate (MikroTik NAS), else disconnect
// Online status, sessions, IP/MAC history and usage come from radacct.
class RadiusDriver implements NetworkDriver
{
    public const TYPES = ['pppoe', 'hotspot'];
    // radcheck / radreply attributes this driver owns; anything else on a user is left alone
    public const CHECK_ATTRIBUTES = ['Cleartext-Password', 'Auth-Type', 'Calling-Station-Id'];
    public const REPLY_ATTRIBUTES = ['Framed-IP-Address'];
    public const GROUP_ATTRIBUTES = ['Mikrotik-Rate-Limit', 'Huawei-Input-Average-Rate', 'Huawei-Output-Average-Rate', 'Cisco-AVPair'];

    public static function db(): ConnectionInterface
    {
        return DB::connection('radius');
    }

    public function unmanagedReason(Connection $connection): ?string
    {
        if (! in_array($connection->connection_type, self::TYPES, true)) {
            return 'Static IP / DHCP users are not RADIUS users.';
        }
        if (empty($connection->pppoe_username)) {
            return 'No username: nothing to write to RADIUS.';
        }
        if (! $connection->router_id && ! Router::forConnection($connection)) {
            return 'No router: set one on the connection or mark a default router for the branch.';
        }
        return null;
    }

    public static function groupName(?Package $package): string
    {
        return $package ? ($package->network_profile ?: 'isp-pkg-' . $package->id) : 'isp-default';
    }

    // Speed attributes per NAS vendor for "up/down Mbps". Cisco needs policy-maps of these names on the BNG.
    public static function rateAttributes(Package $package, array $nasTypes): array
    {
        $up = (int) $package->upload_mbps;
        $down = (int) $package->download_mbps;
        if (! $up || ! $down) {
            return [];
        }
        $rows = [];
        foreach (array_unique($nasTypes) as $type) {
            $rows = array_merge($rows, match ($type) {
                'mikrotik' => [['Mikrotik-Rate-Limit', ':=', "{$up}M/{$down}M"]],
                'huawei' => [['Huawei-Input-Average-Rate', ':=', (string) ($up * 1000000)], ['Huawei-Output-Average-Rate', ':=', (string) ($down * 1000000)]],
                'cisco' => [['Cisco-AVPair', '+=', "ip:sub-qos-policy-in=isp-up-{$up}M"], ['Cisco-AVPair', '+=', "ip:sub-qos-policy-out=isp-down-{$down}M"]],
                default => [],
            });
        }
        return $rows;
    }

    // NAS types of the active RADIUS routers: a group's reply carries the speed attributes of each.
    public static function nasTypes(): array
    {
        $types = Router::where('driver', 'radius')->where('is_active', true)->pluck('nas_type')->unique()->values()->all();
        return $types ?: ['mikrotik'];
    }

    // Writes the package's group (only groups this system created: a network_profile set by hand is left alone).
    public static function ensureGroup(?Package $package): string
    {
        $group = self::groupName($package);
        if (! $package || $package->network_profile) {
            return $group;
        }
        $db = self::db();
        $db->table('radgroupreply')->where('groupname', $group)->whereIn('attribute', self::GROUP_ATTRIBUTES)->delete();
        $rows = array_map(fn ($r) => ['groupname' => $group, 'attribute' => $r[0], 'op' => $r[1], 'value' => $r[2]], self::rateAttributes($package, self::nasTypes()));
        if ($rows) {
            $db->table('radgroupreply')->insert($rows);
        }
        return $group;
    }

    public function sync(Connection $connection): void
    {
        if ($this->unmanagedReason($connection)) {
            return;
        }
        $router = Router::forConnection($connection);
        if (! $router) {
            throw new RuntimeException('The router assigned to this connection is inactive.');
        }
        $connection->loadMissing('package');
        $user = $connection->pppoe_username;
        $renamedFrom = $connection->radius_username && $connection->radius_username !== $user ? $connection->radius_username : null;
        $enabled = $connection->status === 'active';
        $db = self::db();

        $before = [
            'group' => $db->table('radusergroup')->where('username', $user)->value('groupname'),
            'ip' => $db->table('radreply')->where('username', $user)->where('attribute', 'Framed-IP-Address')->value('value'),
            'mac' => $db->table('radcheck')->where('username', $user)->where('attribute', 'Calling-Station-Id')->value('value'),
        ];

        $group = $db->transaction(function () use ($db, $connection, $user, $renamedFrom, $enabled) {
            if ($renamedFrom) {
                $this->removeUser($renamedFrom);
            }
            $group = self::ensureGroup($connection->package);

            $db->table('radcheck')->where('username', $user)->whereIn('attribute', self::CHECK_ATTRIBUTES)->delete();
            $check = [];
            if ($connection->pppoe_password) {
                $check[] = ['Cleartext-Password', ':=', $connection->pppoe_password];
            }
            if (! $enabled) {
                $check[] = ['Auth-Type', ':=', 'Reject'];
            }
            if ($connection->connection_type === 'hotspot' && $connection->mac_address) {
                $check[] = ['Calling-Station-Id', '==', strtoupper($connection->mac_address)]; // lock the hotspot login to the device
            }
            $db->table('radcheck')->insert(array_map(fn ($r) => ['username' => $user, 'attribute' => $r[0], 'op' => $r[1], 'value' => $r[2]], $check));

            $db->table('radreply')->where('username', $user)->whereIn('attribute', self::REPLY_ATTRIBUTES)->delete();
            if ($connection->static_ip) {
                $db->table('radreply')->insert(['username' => $user, 'attribute' => 'Framed-IP-Address', 'op' => ':=', 'value' => $connection->static_ip]);
            }

            $db->table('radusergroup')->where('username', $user)->delete();
            $db->table('radusergroup')->insert(['username' => $user, 'groupname' => $group, 'priority' => 1]);
            return $group;
        });
        Connection::whereKey($connection->id)->update(['radius_username' => $user]);

        // live sessions keep what they were given at login: end or change them now
        $sessions = $this->openSessions(array_filter([$user, $renamedFrom]));
        if ($sessions->isEmpty()) {
            return;
        }
        $failures = [];
        foreach ($sessions as $session) {
            $mustEnd = ! $enabled || $renamedFrom === $session->username
                || $before['ip'] !== ($connection->static_ip ?: null) || $before['mac'] !== ($connection->connection_type === 'hotspot' && $connection->mac_address ? strtoupper($connection->mac_address) : null);
            $speedChanged = $before['group'] !== $group; // another package
            try {
                if ($mustEnd) {
                    $this->disconnect($session);
                } elseif ($speedChanged && ! $this->changeSpeed($session, $connection->package)) {
                    $this->disconnect($session); // the NAS can't change it live: the user reconnects with the new speed
                }
            } catch (\Throwable $e) {
                $failures[] = $e->getMessage();
            }
        }
        if ($failures) {
            // RADIUS is already right; the job retries so the old session doesn't keep running
            throw new RuntimeException('Saved in RADIUS, but the live session was not updated: ' . implode(' ', $failures));
        }
    }

    public function removeUser(string $username): void
    {
        $db = self::db();
        $db->table('radcheck')->where('username', $username)->whereIn('attribute', self::CHECK_ATTRIBUTES)->delete();
        $db->table('radreply')->where('username', $username)->whereIn('attribute', self::REPLY_ATTRIBUTES)->delete();
        $db->table('radusergroup')->where('username', $username)->delete();
    }

    public function verify(Connection $connection): array
    {
        if ($this->unmanagedReason($connection)) {
            return [];
        }
        $connection->loadMissing('package');
        $db = self::db();
        $user = $connection->pppoe_username;
        $check = $db->table('radcheck')->where('username', $user)->get()->groupBy('attribute');
        $issues = [];
        if (! $check->has('Cleartext-Password')) {
            $issues[] = "{$user} has no password in RADIUS.";
        } elseif ($connection->pppoe_password && $check['Cleartext-Password']->first()->value !== $connection->pppoe_password) {
            $issues[] = "RADIUS password of {$user} differs from billing.";
        }
        $rejected = $check->has('Auth-Type') && $check['Auth-Type']->contains(fn ($r) => strcasecmp($r->value, 'Reject') === 0);
        $shouldBeEnabled = $connection->status === 'active';
        if ($shouldBeEnabled === $rejected) {
            $issues[] = 'RADIUS ' . ($rejected ? 'REJECTS' : 'ACCEPTS') . " {$user} but the connection is {$connection->status}.";
        }
        $group = $db->table('radusergroup')->where('username', $user)->value('groupname');
        if ($group !== self::groupName($connection->package)) {
            $issues[] = 'RADIUS group is ' . ($group ?? 'none') . ', package needs ' . self::groupName($connection->package) . '.';
        }
        if (! $shouldBeEnabled && $this->openSessions([$user])->isNotEmpty()) {
            $issues[] = "{$user} still has a live session although the connection is {$connection->status}.";
        }
        return $issues;
    }

    public function syncBlocks(Router $router, Collection $blocks): array
    {
        return $blocks->isEmpty() ? [] : ["{$router->name} is a RADIUS NAS: site / IP blocks need a MikroTik router with API access."];
    }

    // Open sessions (no stop time) of these usernames, newest first.
    public function openSessions(array $usernames): Collection
    {
        return self::db()->table('radacct')->whereIn('username', $usernames)->whereNull('acctstoptime')->orderByDesc('acctstarttime')->get();
    }

    public static function activeSession(Connection $connection): ?array
    {
        $s = self::db()->table('radacct')->where('username', $connection->pppoe_username)->whereNull('acctstoptime')->orderByDesc('acctstarttime')->first();
        return $s ? self::sessionRow($s) : null;
    }

    // Session history with IP, MAC and usage (lawful-intercept style log, newest first).
    public static function sessions(Connection $connection, int $limit = 50): array
    {
        return self::db()->table('radacct')->where('username', $connection->pppoe_username)
            ->orderByDesc('acctstarttime')->limit($limit)->get()->map(fn ($s) => self::sessionRow($s))->all();
    }

    private static function sessionRow(object $s): array
    {
        return [
            'session_id' => $s->acctsessionid,
            'nas' => $s->nasipaddress,
            'address' => $s->framedipaddress,
            'mac' => $s->callingstationid,
            'start' => $s->acctstarttime,
            'stop' => $s->acctstoptime,
            'seconds' => (int) $s->acctsessiontime,
            'download_bytes' => (int) $s->acctoutputoctets, // NAS output = to the customer
            'upload_bytes' => (int) $s->acctinputoctets,
            'terminate_cause' => $s->acctterminatecause,
        ];
    }

    // The NAS a session runs on: a RADIUS router with that address (any branch).
    private function nasFor(object $session): Router
    {
        $nas = Router::where('driver', 'radius')->where('host', $session->nasipaddress)->first();
        if (! $nas) {
            throw new RuntimeException("No RADIUS router with address {$session->nasipaddress} to send the request to.");
        }
        return $nas;
    }

    private function sessionAttributes(object $session): array
    {
        return array_filter([
            'User-Name' => $session->username,
            'Acct-Session-Id' => $session->acctsessionid ?: null,
            'Framed-IP-Address' => filter_var($session->framedipaddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ?: null,
        ]);
    }

    public function disconnect(object $session): void
    {
        (new RadiusClient($this->nasFor($session)))->disconnect($this->sessionAttributes($session));
        Log::info('RADIUS disconnect sent', ['user' => $session->username, 'nas' => $session->nasipaddress]);
    }

    // CoA with the new speed, for NAS types that take it live. False = not possible, disconnect instead.
    public function changeSpeed(object $session, ?Package $package): bool
    {
        $nas = $this->nasFor($session);
        if (! $package || $nas->nas_type !== 'mikrotik' || ! ($rate = self::rateAttributes($package, ['mikrotik']))) {
            return false;
        }
        return (new RadiusClient($nas))->coa($this->sessionAttributes($session) + ['Mikrotik-Rate-Limit' => $rate[0][2]]);
    }

    // Registers the NAS as a FreeRADIUS client (nas table, read_clients = yes), or removes it.
    public static function syncNas(Router $router, ?string $oldHost = null): void
    {
        $db = self::db();
        if ($oldHost && $oldHost !== $router->host) {
            $db->table('nas')->where('nasname', $oldHost)->delete();
        }
        if (! $router->isRadius() || ! $router->is_active || ! $router->radius_secret) {
            $db->table('nas')->where('nasname', $router->host)->delete();
            return;
        }
        $db->table('nas')->updateOrInsert(['nasname' => $router->host], [
            'shortname' => mb_substr($router->name, 0, 32),
            'type' => $router->nas_type, // FreeRADIUS checkrad type
            'ports' => $router->coa_port,
            'secret' => mb_substr($router->radius_secret, 0, 60),
            'description' => 'ISP billing: ' . mb_substr($router->name, 0, 180),
        ]);
    }
}
