<?php

namespace App\Services\Isp;

use App\Models\Connection;
use App\Models\IpPool;
use RuntimeException;

// IP address management (roadmap 4.6).
//   static   a subnet whose addresses are given to connections one by one (connections.static_ip)
//   ipv6_pd  a prefix cut into /delegated_length prefixes, one per connection (connections.ipv6_prefix)
//   cgnat    deterministic NAT: private address n of the pool always leaves through public address
//            floor(n / users_per_ip) with its own fixed port block, so a lawful request for a public
//            IP + port at a time names one private address (and the session log names the customer)
//            without storing per-connection NAT logs.
class IpamService
{
    // ---- IPv4 ------------------------------------------------------------------------------

    // [first, last] addresses of an IPv4 CIDR as integers.
    public static function range4(string $cidr): array
    {
        [$ip, $bits] = array_pad(explode('/', trim($cidr)), 2, 32);
        $long = ip2long($ip);
        $bits = (int) $bits;
        if ($long === false || $bits < 0 || $bits > 32) {
            throw new RuntimeException("Not an IPv4 network: {$cidr}");
        }
        $mask = $bits === 0 ? 0 : (~0 << (32 - $bits)) & 0xFFFFFFFF;
        $first = $long & $mask;
        return [$first, $first + (2 ** (32 - $bits)) - 1];
    }

    public static function contains4(string $cidr, string $ip): bool
    {
        $long = ip2long($ip);
        if ($long === false) {
            return false;
        }
        [$first, $last] = self::range4($cidr);
        return $long >= $first && $long <= $last;
    }

    // Usable host addresses of a static pool: without network, broadcast (for /30 and larger) and gateway.
    private static function hosts(IpPool $pool): array
    {
        [$first, $last] = self::range4($pool->network);
        if ($last - $first >= 3) {
            $first++;
            $last--;
        }
        return [$first, $last, $pool->gateway ? ip2long($pool->gateway) : null];
    }

    public static function usedStatic(IpPool $pool): array
    {
        [$first, $last] = self::range4($pool->network);
        return Connection::where('branch_id', $pool->branch_id)->where('status', '!=', 'terminated')->whereNotNull('static_ip')->pluck('static_ip')
            ->map(fn ($ip) => ip2long($ip))->filter(fn ($l) => $l !== false && $l >= $first && $l <= $last)->values()->all();
    }

    // The lowest free address of a static pool, or null when it is full.
    public static function nextStatic(IpPool $pool): ?string
    {
        [$first, $last, $gateway] = self::hosts($pool);
        $used = array_flip(self::usedStatic($pool));
        for ($l = $first; $l <= $last; $l++) {
            if ($l !== $gateway && ! isset($used[$l])) {
                return long2ip($l);
            }
        }
        return null;
    }

    public static function size(IpPool $pool): int
    {
        return match ($pool->type) {
            'static' => (function () use ($pool) {
                [$first, $last, $gateway] = self::hosts($pool);
                return $last - $first + 1 - ($gateway && $gateway >= $first && $gateway <= $last ? 1 : 0);
            })(),
            'ipv6_pd' => (int) min(PHP_INT_MAX, 2 ** max(0, $pool->delegated_length - self::prefix6($pool->network)[1])),
            'cgnat' => self::cgnatCapacity($pool),
            default => 0,
        };
    }

    public static function used(IpPool $pool): int
    {
        return match ($pool->type) {
            'static', 'cgnat' => count(self::usedStatic($pool)),
            'ipv6_pd' => Connection::where('branch_id', $pool->branch_id)->where('status', '!=', 'terminated')->whereNotNull('ipv6_prefix')->count(),
            default => 0,
        };
    }

    // The same address can't be on two live connections of a branch.
    public static function assertStaticFree(int $branchId, string $ip, ?int $exceptConnectionId = null): void
    {
        $taken = Connection::where('branch_id', $branchId)->where('status', '!=', 'terminated')->where('static_ip', $ip)
            ->when($exceptConnectionId, fn ($q) => $q->where('id', '!=', $exceptConnectionId))->value('code');
        if ($taken) {
            throw new RuntimeException("{$ip} is already used by connection {$taken}.");
        }
    }

    // ---- IPv6 prefix delegation -------------------------------------------------------------

    // [network as 16-byte binary, prefix length]
    public static function prefix6(string $cidr): array
    {
        [$ip, $bits] = array_pad(explode('/', trim($cidr)), 2, 128);
        $bin = @inet_pton($ip);
        if ($bin === false || strlen($bin) !== 16) {
            throw new RuntimeException("Not an IPv6 prefix: {$cidr}");
        }
        return [$bin, (int) $bits];
    }

    // Prefix number $n (0-based) of the pool, e.g. 2001:db8:100:1200::/56.
    public static function delegated(IpPool $pool, int $n): string
    {
        [$bin, $bits] = self::prefix6($pool->network);
        $len = (int) $pool->delegated_length;
        if ($len <= $bits || $len > 128 || $n < 0 || $n >= 2 ** min(62, $len - $bits)) {
            throw new RuntimeException('That prefix is outside the pool.');
        }
        // the pool's host bits are zero, so prefix n is n's bits placed right above the delegated length
        $bytes = array_values(unpack('C*', $bin));
        for ($j = 0; $j < $len - $bits; $j++) {
            if (($n >> $j) & 1) {
                $k = 128 - $len + $j; // bit position counted from the right
                $bytes[15 - intdiv($k, 8)] |= 1 << ($k % 8);
            }
        }
        return inet_ntop(pack('C*', ...$bytes)) . '/' . $len;
    }

    public static function nextPrefix(IpPool $pool): ?string
    {
        $used = array_flip(Connection::where('branch_id', $pool->branch_id)->where('status', '!=', 'terminated')->whereNotNull('ipv6_prefix')->pluck('ipv6_prefix')->all());
        $max = min(self::size($pool), 1 << 20);
        for ($n = 0; $n < $max; $n++) {
            $p = self::delegated($pool, $n);
            if (! isset($used[$p])) {
                return $p;
            }
        }
        return null;
    }

    // ---- deterministic CGNAT ----------------------------------------------------------------

    public static function usersPerPublicIp(IpPool $pool): int
    {
        return max(1, intdiv(65536 - (int) $pool->port_start, max(1, (int) $pool->ports_per_user)));
    }

    public static function cgnatCapacity(IpPool $pool): int
    {
        [$pf, $pl] = self::range4($pool->public_network);
        [$f, $l] = self::range4($pool->network);
        return min($l - $f + 1, ($pl - $pf + 1) * self::usersPerPublicIp($pool));
    }

    // Public address and port block of a private address: ['nat_ip', 'nat_port_start', 'nat_port_end'], or null.
    public static function natFor(IpPool $pool, string $privateIp): ?array
    {
        if (! self::contains4($pool->network, $privateIp)) {
            return null;
        }
        [$first] = self::range4($pool->network);
        [$publicFirst, $publicLast] = self::range4($pool->public_network);
        $n = ip2long($privateIp) - $first;
        $perIp = self::usersPerPublicIp($pool);
        $public = $publicFirst + intdiv($n, $perIp);
        if ($public > $publicLast) {
            return null; // more private addresses than the public side can carry
        }
        $start = (int) $pool->port_start + ($n % $perIp) * (int) $pool->ports_per_user;
        return ['nat_ip' => long2ip($public), 'nat_port_start' => $start, 'nat_port_end' => $start + (int) $pool->ports_per_user - 1];
    }

    // The other way: which private address used this public address + port.
    public static function privateFor(IpPool $pool, string $publicIp, int $port): ?string
    {
        if (! self::contains4($pool->public_network, $publicIp) || $port < $pool->port_start) {
            return null;
        }
        [$first, $last] = self::range4($pool->network);
        [$publicFirst] = self::range4($pool->public_network);
        $slot = intdiv($port - (int) $pool->port_start, (int) $pool->ports_per_user);
        if ($slot >= self::usersPerPublicIp($pool)) {
            return null;
        }
        $n = (ip2long($publicIp) - $publicFirst) * self::usersPerPublicIp($pool) + $slot;
        return $first + $n <= $last ? long2ip($first + $n) : null;
    }

    // The CGNAT mapping of a private address from any pool of the branch (for the session log).
    public static function natLookup(?int $branchId, ?string $privateIp): ?array
    {
        if (! $privateIp || ! filter_var($privateIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null;
        }
        foreach (self::cgnatPools($branchId) as $pool) {
            if ($nat = self::natFor($pool, $privateIp)) {
                return $nat;
            }
        }
        return null;
    }

    private static array $cgnatCache = [];

    private static function cgnatPools(?int $branchId)
    {
        return self::$cgnatCache[$branchId ?? 0] ??= IpPool::where('type', 'cgnat')->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->get();
    }

    public static function flush(): void
    {
        self::$cgnatCache = [];
    }

    // RouterOS source-NAT rules that make the router follow the same mapping (TCP and UDP).
    public static function mikrotikScript(IpPool $pool): string
    {
        [$first, $last] = self::range4($pool->network);
        $lines = ["# Deterministic CGNAT for pool {$pool->name}: {$pool->network} -> {$pool->public_network}, {$pool->ports_per_user} ports each",
            '/ip firewall nat'];
        for ($l = $first; $l <= $last; $l++) {
            $nat = self::natFor($pool, long2ip($l));
            if (! $nat) {
                break;
            }
            foreach (['tcp', 'udp'] as $proto) {
                $lines[] = "add chain=srcnat src-address=" . long2ip($l) . " protocol={$proto} action=src-nat to-addresses={$nat['nat_ip']} to-ports={$nat['nat_port_start']}-{$nat['nat_port_end']} comment=\"isp-cgnat:{$pool->id}\"";
            }
            $lines[] = "add chain=srcnat src-address=" . long2ip($l) . " action=src-nat to-addresses={$nat['nat_ip']} comment=\"isp-cgnat:{$pool->id}\"";
        }
        return implode("\n", $lines) . "\n";
    }
}
