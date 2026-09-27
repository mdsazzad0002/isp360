<?php

namespace App\Services\Isp;

use App\Models\Connection;
use App\Models\Router;
use App\Models\SessionLog;
use App\Services\Network\MikroTikClient;
use App\Services\Network\MikroTikDriver;
use App\Services\Network\RadiusDriver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

// The lawful session log (roadmap 2.6): who had which IP when. Filled from RADIUS accounting
// (radacct, incrementally) and, for routers driven over the MikroTik API, by polling their active
// sessions. Kept for the company's retention period (company_profiles.log_retention_days, set from
// the country pack) and searchable by IP + time for regulator requests.
class SessionLogService
{
    public const RADIUS_CURSOR = 'session-logs:radius-since';
    // re-read a little before the last run, so rows updated during it are not missed (upserts are idempotent)
    public const OVERLAP_MINUTES = 10;

    // Copies new and changed radacct rows. Returns the number of rows written.
    public static function syncRadius(): int
    {
        if (! Router::where('driver', 'radius')->exists()) {
            return 0;
        }
        $since = Carbon::parse(Cache::get(self::RADIUS_CURSOR, now()->subDay()->toDateTimeString()))->subMinutes(self::OVERLAP_MINUTES);
        $runStarted = now();
        $written = 0;

        RadiusDriver::db()->table('radacct')
            ->where(fn ($q) => $q->where('acctstarttime', '>=', $since)->orWhere('acctupdatetime', '>=', $since)->orWhere('acctstoptime', '>=', $since))
            ->orderBy('radacctid')
            ->chunk(500, function ($rows) use (&$written) {
                $connections = Connection::whereIn('pppoe_username', $rows->pluck('username')->unique())->get(['id', 'customer_id', 'branch_id', 'pppoe_username'])->keyBy('pppoe_username');
                $nasBranches = Router::whereIn('host', $rows->pluck('nasipaddress')->unique())->pluck('branch_id', 'host');
                foreach ($rows as $r) {
                    $c = $connections->get($r->username);
                    self::upsert('radius', $r->acctuniqueid ?: "{$r->nasipaddress}:{$r->acctsessionid}", [
                        'branch_id' => $c?->branch_id ?? $nasBranches->get($r->nasipaddress),
                        'connection_id' => $c?->id,
                        'customer_id' => $c?->customer_id,
                        'username' => $r->username,
                        'nas_ip' => $r->nasipaddress,
                        'session_id' => $r->acctsessionid,
                        'framed_ip' => $r->framedipaddress ?: null,
                        'framed_ipv6' => ($r->framedipv6prefix ?? '') ?: (($r->framedipv6address ?? '') ?: null),
                        'mac' => $r->callingstationid ?: null,
                        'started_at' => $r->acctstarttime,
                        'stopped_at' => $r->acctstoptime,
                        'upload_bytes' => $r->acctinputoctets,
                        'download_bytes' => $r->acctoutputoctets,
                        'terminate_cause' => $r->acctterminatecause ?: null,
                    ]);
                    $written++;
                }
            });
        Cache::forever(self::RADIUS_CURSOR, $runStarted->toDateTimeString());
        return $written;
    }

    // Records the live PPPoE / Hotspot sessions of a MikroTik router and closes the ones that are gone.
    public static function pollMikrotik(Router $router): int
    {
        $api = new MikroTikClient($router);
        $seen = [];
        $written = 0;
        foreach (MikroTikDriver::SERVICES as $type => $menu) {
            foreach ($api->get($menu['active']) as $s) {
                $user = $s[$menu['activeUser']] ?? null;
                if (! $user) {
                    continue;
                }
                // a session is the user + when it started (now - uptime, to the minute so polls agree)
                $started = now()->subSeconds(self::seconds($s['uptime'] ?? '0s'))->startOfMinute();
                $key = "{$router->id}:{$type}:{$user}:" . $started->format('YmdHi');
                $existing = SessionLog::where('source', 'mikrotik')->where('username', $user)->whereNull('stopped_at')
                    ->where('nas_ip', $router->host)->whereBetween('started_at', [$started->copy()->subMinutes(2), $started->copy()->addMinutes(2)])->first();
                if ($existing) {
                    $seen[] = $existing->id;
                    continue;
                }
                $c = Connection::where('branch_id', $router->branch_id)->where('pppoe_username', $user)->first(['id', 'customer_id']);
                $log = self::upsert('mikrotik', $key, [
                    'branch_id' => $router->branch_id,
                    'connection_id' => $c?->id,
                    'customer_id' => $c?->customer_id,
                    'username' => $user,
                    'nas_ip' => $router->host,
                    'session_id' => $s['session-id'] ?? ($s['.id'] ?? null),
                    'framed_ip' => $s['address'] ?? null,
                    'mac' => $s['caller-id'] ?? ($s['mac-address'] ?? null),
                    'started_at' => $started,
                ]);
                $seen[] = $log->id;
                $written++;
            }
        }
        // open sessions of this router that are no longer active ended since the last poll
        $written += SessionLog::where('source', 'mikrotik')->where('nas_ip', $router->host)->whereNull('stopped_at')
            ->whereNotIn('id', $seen ?: [0])->update(['stopped_at' => now(), 'terminate_cause' => 'Not-Seen', 'updated_at' => now()]);
        return $written;
    }

    public static function pollAllMikrotik(): int
    {
        $count = 0;
        foreach (Router::where('driver', 'mikrotik')->where('is_active', true)->get() as $router) {
            if (! IspSettings::get($router->branch_id, 'session_log_mikrotik')) {
                continue;
            }
            try {
                $count += self::pollMikrotik($router);
            } catch (\Throwable $e) {
                Log::warning('Session log poll failed', ['router' => $router->id, 'error' => $e->getMessage()]);
            }
        }
        return $count;
    }

    // Deletes sessions that ended before the retention period (0 = keep forever).
    public static function prune(): int
    {
        $days = (int) (company()?->log_retention_days ?? 365);
        if ($days <= 0) {
            return 0;
        }
        return SessionLog::where('stopped_at', '<', now()->subDays($days))->delete();
    }

    /**
     * Sessions matching a lawful request: an IP (private or public NAT address) and/or a user,
     * customer or connection, overlapping a time window. Scoped to a branch unless null.
     */
    public static function search(array $f, ?int $branchId): Builder
    {
        $from = ! empty($f['from']) ? Carbon::parse($f['from']) : null;
        $to = ! empty($f['to']) ? Carbon::parse($f['to']) : null;
        return SessionLog::with(['customer', 'connection'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($f['ip'] ?? null, fn ($q, $ip) => $q->where(fn ($w) => $w->where('framed_ip', $ip)->orWhere('nat_ip', $ip)->orWhere('framed_ipv6', $ip)))
            ->when(isset($f['port']) && $f['port'] !== '' && $f['port'] !== null, fn ($q) => $q->where('nat_port_start', '<=', (int) $f['port'])->where('nat_port_end', '>=', (int) $f['port']))
            ->when($f['username'] ?? null, fn ($q, $u) => $q->where('username', $u))
            ->when($f['customer_id'] ?? null, fn ($q, $id) => $q->where('customer_id', $id))
            ->when($f['mac'] ?? null, fn ($q, $m) => $q->where('mac', $m))
            // overlapping the window: started before its end and not stopped before its start
            ->when($to, fn ($q) => $q->where('started_at', '<=', $to))
            ->when($from, fn ($q) => $q->where(fn ($w) => $w->whereNull('stopped_at')->orWhere('stopped_at', '>=', $from)))
            ->orderByDesc('started_at');
    }

    public const CSV_COLUMNS = ['started_at', 'stopped_at', 'username', 'customer_code', 'customer_name', 'connection_code', 'framed_ip', 'framed_ipv6', 'nat_ip', 'nat_port_start', 'nat_port_end', 'mac', 'nas_ip', 'session_id', 'upload_bytes', 'download_bytes', 'terminate_cause', 'source'];

    public static function csvRow(SessionLog $s): array
    {
        return [
            $s->started_at?->toDateTimeString(), $s->stopped_at?->toDateTimeString(), $s->username,
            $s->customer?->code, $s->customer?->name, $s->connection?->code,
            $s->framed_ip, $s->framed_ipv6, $s->nat_ip, $s->nat_port_start, $s->nat_port_end, $s->mac,
            $s->nas_ip, $s->session_id, $s->upload_bytes, $s->download_bytes, $s->terminate_cause, $s->source,
        ];
    }

    private static function upsert(string $source, string $key, array $values): SessionLog
    {
        $log = SessionLog::firstOrNew(['source' => $source, 'source_key' => mb_substr($key, 0, 191)]);
        $log->fill($values);
        $log->save();
        return $log;
    }

    // RouterOS uptime "1w2d3h4m5s" -> seconds
    public static function seconds(string $uptime): int
    {
        $units = ['w' => 604800, 'd' => 86400, 'h' => 3600, 'm' => 60, 's' => 1];
        preg_match_all('/(\d+)([wdhms])/', $uptime, $m, PREG_SET_ORDER);
        return array_sum(array_map(fn ($p) => (int) $p[1] * $units[$p[2]], $m));
    }
}
