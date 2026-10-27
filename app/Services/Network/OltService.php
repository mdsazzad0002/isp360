<?php

namespace App\Services\Network;

use App\Models\Olt;
use App\Models\Onu;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Polls OLTs and keeps the onus table in step: an ONU is matched by serial, else MAC, else PON port +
// ONU id, so its link to a connection survives being moved to another port.
class OltService
{
    public static function driver(Olt $olt): OltDriver
    {
        return app($olt->vendorConfig()['driver'] ?? SnmpOltDriver::class);
    }

    public static function test(Olt $olt): string
    {
        try {
            $status = self::driver($olt)->test($olt);
            $olt->update(['last_checked_at' => now(), 'last_status' => mb_substr($status, 0, 250)]);
            return $status;
        } catch (\Throwable $th) {
            $olt->update(['last_checked_at' => now(), 'last_status' => mb_substr('FAILED · ' . $th->getMessage(), 0, 250)]);
            throw $th;
        }
    }

    // ['found' => n, 'online' => n, 'weak' => n, 'new' => n, 'missing' => n]
    public static function poll(Olt $olt): array
    {
        $before = Onu::where('olt_id', $olt->id)->pluck('status', 'id')->all();
        try {
            $rows = self::driver($olt)->onus($olt);
        } catch (\Throwable $th) {
            $olt->update(['last_polled_at' => now(), 'last_status' => mb_substr('POLL FAILED · ' . $th->getMessage(), 0, 250)]);
            OltAlerts::oltDown($olt, $th->getMessage());
            throw $th;
        }

        $summary = DB::transaction(function () use ($olt, $rows) {
            $existing = Onu::where('olt_id', $olt->id)->get();
            $bySerial = $existing->whereNotNull('serial')->keyBy('serial');
            $byMac = $existing->whereNotNull('mac')->keyBy('mac');
            $byPort = $existing->keyBy(fn ($o) => "{$o->pon_port}#{$o->onu_id}");
            $seen = [];
            $new = 0;
            $now = now();
            foreach ($rows as $row) {
                $onu = ($row['serial'] ? $bySerial->get($row['serial']) : null)
                    ?? ($row['mac'] ? $byMac->get($row['mac']) : null)
                    ?? $byPort->get("{$row['pon_port']}#{$row['onu_id']}");
                if ($onu && isset($seen[$onu->id])) {
                    $onu = null; // two rows on one record: keep the second as its own ONU
                }
                if (! $onu) {
                    $onu = new Onu(['branch_id' => $olt->branch_id, 'olt_id' => $olt->id]);
                    $new++;
                }
                $onu->fill([
                    'pon_port' => $row['pon_port'],
                    'onu_id' => $row['onu_id'],
                    'serial' => $row['serial'] ?? $onu->serial,
                    'mac' => $row['mac'] ?? $onu->mac,
                    'name' => $row['name'] ?? $onu->name,
                    'status' => $row['status'],
                    'rx_power' => $row['rx_power'],
                    'tx_power' => $row['tx_power'],
                    'distance_m' => $row['distance_m'] ?? $onu->distance_m,
                    'source' => 'snmp',
                    'last_polled_at' => $now,
                ]);
                if ($row['status'] === 'online') {
                    $onu->last_seen_at = $now;
                }
                $onu->save();
                $seen[$onu->id] = true;
            }
            // on the list but no longer on the OLT (deleted there, or moved to another OLT)
            $missing = Onu::where('olt_id', $olt->id)->whereNotIn('id', array_keys($seen))->where('status', '!=', 'not_found')
                ->update(['status' => 'not_found', 'rx_power' => null, 'tx_power' => null, 'last_polled_at' => $now]);

            $found = collect($rows);
            $weak = $found->filter(fn ($r) => $r['rx_power'] !== null && $r['rx_power'] < (float) config('isp.olt.weak_rx_dbm', -27))->count();
            return ['found' => $found->count(), 'online' => $found->where('status', 'online')->count(), 'weak' => $weak, 'new' => $new, 'missing' => $missing];
        });

        OltAlerts::oltUp($olt);
        OltAlerts::afterPoll($olt, $before);
        $olt->update(['last_polled_at' => now(), 'last_status' => mb_substr("OK · {$summary['found']} ONU(s), {$summary['online']} online"
            . ($summary['weak'] ? ", {$summary['weak']} weak signal" : '') . ($summary['missing'] ? ", {$summary['missing']} gone from the OLT" : ''), 0, 250)]);
        return $summary;
    }

    // Every active OLT that can be polled (scheduled); returns the number polled.
    public static function pollAll(): int
    {
        $n = 0;
        foreach (Olt::where('is_active', true)->get() as $olt) {
            if (! $olt->canPoll()) {
                continue;
            }
            try {
                self::poll($olt);
                $n++;
            } catch (\Throwable $th) {
                Log::warning('OLT poll failed', ['olt' => $olt->id, 'error' => $th->getMessage()]);
            }
        }
        return $n;
    }
}
