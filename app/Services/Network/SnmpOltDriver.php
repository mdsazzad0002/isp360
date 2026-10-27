<?php

namespace App\Services\Network;

use App\Models\Olt;
use RuntimeException;

// Reads OLTs over SNMP v2c using the vendor's OIDs from config/olt_vendors.php.
class SnmpOltDriver implements OltDriver
{
    private const SYS_DESCR = '1.3.6.1.2.1.1.1.0';
    private const SYS_UPTIME = '1.3.6.1.2.1.1.3.0';
    private const SYS_NAME = '1.3.6.1.2.1.1.5.0';
    private const IF_DESCR = '1.3.6.1.2.1.2.2.1.2';

    public function test(Olt $olt): string
    {
        $sys = $this->client($olt)->get([self::SYS_DESCR, self::SYS_UPTIME, self::SYS_NAME]);
        if ($sys[self::SYS_DESCR] === null && $sys[self::SYS_NAME] === null) {
            throw new RuntimeException('The OLT answered but gave no system information');
        }
        $descr = mb_substr(preg_replace('/\s+/', ' ', self::text($sys[self::SYS_DESCR] ?? '')), 0, 120);
        $uptime = $sys[self::SYS_UPTIME] !== null ? ' · uptime ' . self::uptime((int) $sys[self::SYS_UPTIME]) : '';
        return 'OK · ' . trim(self::text($sys[self::SYS_NAME] ?? '') . ' · ' . $descr, ' ·') . $uptime;
    }

    public function onus(Olt $olt): array
    {
        $vendor = $olt->vendorConfig();
        if (empty($vendor['oids'])) {
            throw new RuntimeException('This OLT brand is kept by hand: no SNMP OIDs in config/olt_vendors.php');
        }
        $client = $this->client($olt);
        $ifDescr = self::suffixes($client->walk(self::IF_DESCR), self::IF_DESCR);
        $columns = [];
        foreach ($vendor['oids'] as $column => $oid) {
            $columns[$column] = self::suffixes($client->walk($oid), $oid);
        }

        // row key => [pon_port, onu_id]
        $keys = [];
        $onuIf = ($vendor['rows'] ?? 'port_onu') === 'onu_if';
        foreach ($columns as $values) {
            foreach (array_keys($values) as $suffix) {
                $arcs = explode('.', (string) $suffix);
                if ($onuIf) {
                    $descr = self::text($ifDescr[$arcs[0]] ?? '');
                    if (isset($keys[$arcs[0]]) || ! preg_match($vendor['onu_if_pattern'], $descr, $m)) {
                        continue;
                    }
                    $keys[$arcs[0]] = [preg_replace('/:\d+$/', '', $descr), (int) $m[2]];
                } elseif (count($arcs) >= 2 && ! isset($keys["{$arcs[0]}.{$arcs[1]}"])) {
                    $port = self::text($ifDescr[$arcs[0]] ?? '') ?: "port {$arcs[0]}";
                    $keys["{$arcs[0]}.{$arcs[1]}"] = [$port, (int) $arcs[1]];
                }
            }
        }

        $rows = [];
        foreach ($keys as $key => [$port, $onuId]) {
            $value = fn ($column) => $columns[$column][$key] ?? null;
            $status = $value('status');
            $rows[] = [
                'pon_port' => mb_substr($port, 0, 50),
                'onu_id' => $onuId,
                'serial' => self::serial($value('serial')),
                'mac' => self::mac($value('mac')),
                'name' => ($n = self::text($value('name') ?? '')) !== '' ? mb_substr($n, 0, 100) : null,
                'status' => $status === null ? 'unknown' : ($vendor['status_map'][(int) $status] ?? 'unknown'),
                'rx_power' => self::power($value('rx_power'), $vendor['power'] ?? []),
                'tx_power' => self::power($value('tx_power'), $vendor['power'] ?? []),
                'distance_m' => is_int($d = $value('distance')) && $d >= 0 && $d < 100000 ? $d : null,
            ];
        }
        return $rows;
    }

    protected function client(Olt $olt): SnmpClient
    {
        return app(SnmpClient::class, [
            'host' => $olt->host,
            'port' => (int) ($olt->snmp_port ?: 161),
            'community' => (string) $olt->snmp_community,
            'timeout' => (float) config('isp.olt.snmp_timeout', 3),
        ]);
    }

    // [index suffix after the table OID => value]
    private static function suffixes(array $walk, string $base): array
    {
        $out = [];
        $len = strlen(trim($base, '.')) + 1;
        foreach ($walk as $oid => $value) {
            $out[substr($oid, $len)] = $value;
        }
        return $out;
    }

    public static function power(mixed $raw, array $rule): ?float
    {
        if (! is_int($raw) || in_array($raw, $rule['invalid'] ?? [], true)) {
            return null;
        }
        if (! empty($rule['signed16']) && $raw > 32767) {
            $raw -= 65536;
        }
        $dbm = round($raw * ($rule['scale'] ?? 1) + ($rule['offset'] ?? 0), 2);
        return $dbm > -60 && $dbm < 15 ? $dbm : null; // outside that is no real optical reading
    }

    // GPON serial: 4 vendor letters + 4 bytes shown as hex (HWTC1A2B3C4D); already-text serials as they are
    public static function serial(mixed $raw): ?string
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        if (strlen($raw) === 8 && ctype_alpha(substr($raw, 0, 4))) {
            return substr($raw, 0, 4) . strtoupper(bin2hex(substr($raw, 4)));
        }
        $text = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw));
        return $text !== '' ? substr($text, 0, 32) : null;
    }

    public static function mac(mixed $raw): ?string
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        $hex = strlen($raw) === 6 ? bin2hex($raw) : strtolower(preg_replace('/[^0-9A-Fa-f]/', '', $raw));
        return strlen($hex) === 12 ? implode(':', str_split($hex, 2)) : null;
    }

    private static function text(mixed $raw): string
    {
        return is_string($raw) ? trim(preg_replace('/[^\P{C}]+/u', '', mb_convert_encoding($raw, 'UTF-8', 'UTF-8'))) : (string) $raw;
    }

    private static function uptime(int $ticks): string
    {
        $s = intdiv($ticks, 100);
        return sprintf('%dd %02dh %02dm', intdiv($s, 86400), intdiv($s % 86400, 3600), intdiv($s % 3600, 60));
    }
}
