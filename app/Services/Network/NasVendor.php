<?php

namespace App\Services\Network;

use App\Models\Package;

// A RADIUS NAS vendor from config/nas_vendors.php: its speed attributes and what it accepts live.
// Unknown types behave like "other" (login allowed or rejected, speeds set on the NAS).
class NasVendor
{
    public static function all(): array
    {
        return config('nas_vendors', []);
    }

    public static function get(?string $type): array
    {
        $all = self::all();
        return $all[$type] ?? $all['other'] ?? ['label' => (string) $type, 'checkrad' => 'other', 'rate' => [], 'own_group' => false, 'coa_rate' => false, 'disconnect' => true];
    }

    // [type => label] for the router form and validation
    public static function labels(): array
    {
        return array_map(fn ($v) => $v['label'], self::all());
    }

    // [type => [label, note, coa_rate, disconnect]] for the router form
    public static function options(): array
    {
        return array_map(fn ($v) => [
            'label' => $v['label'],
            'note' => $v['note'] ?? '',
            'coa_rate' => (bool) ($v['coa_rate'] ?? false),
            'disconnect' => (bool) ($v['disconnect'] ?? true),
        ], self::all());
    }

    public static function ownGroup(?string $type): bool
    {
        return (bool) (self::get($type)['own_group'] ?? false);
    }

    public static function canChangeSpeedLive(?string $type): bool
    {
        return (bool) (self::get($type)['coa_rate'] ?? false);
    }

    public static function canDisconnect(?string $type): bool
    {
        return (bool) (self::get($type)['disconnect'] ?? true);
    }

    public static function checkradType(?string $type): string
    {
        return self::get($type)['checkrad'] ?? 'other';
    }

    // [[attribute, op, value], ...] for the package's speed; [] when the package has no speed or the vendor none
    public static function rateAttributes(?string $type, Package $package): array
    {
        $up = (int) $package->upload_mbps;
        $down = (int) $package->download_mbps;
        if (! $up || ! $down) {
            return [];
        }
        $vars = [
            '{up_kbps}' => $up * 1000, '{down_kbps}' => $down * 1000,
            '{up_bps}' => $up * 1000000, '{down_bps}' => $down * 1000000,
            '{up}' => $up, '{down}' => $down,
        ];
        return array_map(fn ($a) => [$a[0], $a[1], strtr($a[2], $vars)], self::get($type)['rate'] ?? []);
    }

    // Every speed attribute any vendor uses: the ones this system owns in radgroupreply.
    public static function rateAttributeNames(): array
    {
        $names = [];
        foreach (self::all() as $vendor) {
            foreach ($vendor['rate'] ?? [] as $a) {
                $names[$a[0]] = true;
            }
        }
        return array_keys($names);
    }
}
