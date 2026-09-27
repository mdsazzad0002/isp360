<?php

namespace App\Services\Network;

use App\Models\Connection;
use App\Models\Package;
use App\Models\Router;
use RuntimeException;

// Keeps the router in line with the billing system, for PPPoE and Hotspot:
//  package    -> PPP profile / hotspot user profile with a rate limit (created on demand)
//  connection -> PPP secret / hotspot user, tagged "isp-conn:<id>" so a username change renames it
//  not active -> user disabled and any live session kicked
class MikroTikDriver implements NetworkDriver
{
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
        if (! $existing) {
            $data = ['name' => $name, 'rate-limit' => $rate];
            if ($profileMenu === '/ppp/profile') {
                $data['comment'] = 'ISP package ' . $package->name; // hotspot user profiles have no comment field
            }
            $api->create($profileMenu, array_filter($data));
        } elseif (! $package->network_profile && $rate && ($existing['rate-limit'] ?? '') !== $rate) {
            // only manage rate limits of profiles this system created
            $api->update($profileMenu, $existing['.id'], ['rate-limit' => $rate]);
        }
        return $name;
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
