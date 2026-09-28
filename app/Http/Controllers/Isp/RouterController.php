<?php

namespace App\Http\Controllers\Isp;

use App\Jobs\SyncConnectionToNetwork;
use App\Models\Connection;
use App\Models\Router;
use App\Services\Isp\AuditLogger;
use App\Services\Network\MikroTikClient;
use App\Services\Network\NasVendor;
use App\Services\Network\RadiusDriver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RouterController extends IspController
{
    public function create()
    {
        return $this->page('router', 'Isp/Router', ['drivers' => Router::drivers(), 'nasVendors' => NasVendor::options()]);
    }

    public function index()
    {
        $routers = Router::where('branch_id', $this->branchId)->orderByDesc('is_default')->orderBy('name')->get()
            ->map(function ($r) {
                $r->connections_count = Connection::where('branch_id', $r->branch_id)
                    ->where(fn ($q) => $r->is_default ? $q->where('router_id', $r->id)->orWhereNull('router_id') : $q->where('router_id', $r->id))
                    ->whereIn('connection_type', ['pppoe', 'hotspot'])->where('status', '!=', 'terminated')->count();
                return $r;
            });
        return response()->json($routers);
    }

    public function store(Request $request)
    {
        if ($r = $this->deny('router')) return $r;
        if ($r = $this->validateOrFail($request->all(), [
            'id' => 'nullable|integer',
            'name' => 'required|max:100',
            'driver' => 'nullable|in:' . implode(',', array_keys(Router::drivers())),
            'host' => $request->driver === 'radius' ? 'required|ip' : 'required|max:255',
            'port' => 'nullable|integer|min:1|max:65535',
        ] + ($request->driver === 'radius' ? [
            'nas_type' => 'required|in:' . implode(',', array_keys(NasVendor::labels())),
            'radius_secret' => $request->id ? 'nullable|max:60' : 'required|max:60',
            'coa_port' => 'nullable|integer|min:1|max:65535',
        ] : [
            'username' => 'required|max:100',
            'password' => $request->id ? 'nullable|max:255' : 'required|max:255',
        ]), ['host.ip' => 'A RADIUS NAS is identified by its IP address (the NAS-IP it sends).'])) return $r;

        return DB::transaction(function () use ($request) {
            $router = $request->id ? Router::where('branch_id', $this->branchId)->findOrFail($request->id) : new Router(['branch_id' => $this->branchId, 'created_by' => $this->userId]);
            $oldHost = $router->host;
            $radius = $request->driver === 'radius';
            $router->fill($request->only(['name', 'host', 'port']) + [
                'driver' => $radius ? 'radius' : 'mikrotik',
                'username' => $radius ? null : $request->username,
                'nas_type' => $radius ? $request->nas_type : 'mikrotik',
                'coa_port' => $radius ? ($request->coa_port ?: 3799) : 3799,
                'use_https' => $request->boolean('use_https'),
                'is_active' => $request->boolean('is_active', true),
                'is_default' => $request->boolean('is_default'),
            ]);
            if (! $radius && $request->filled('password')) {
                $router->password = $request->password;
            }
            if ($radius && $request->filled('radius_secret')) {
                $router->radius_secret = $request->radius_secret;
            }
            $router->updated_by = $this->userId;
            $router->save();
            if ($router->is_default) {
                Router::where('branch_id', $this->branchId)->where('id', '!=', $router->id)->update(['is_default' => false]);
            }
            // a RADIUS NAS is a FreeRADIUS client (nas table); switching back to the API removes it
            if ($radius || $oldHost) {
                RadiusDriver::syncNas($router, $oldHost);
            }
            AuditLogger::log($request->id ? 'router.updated' : 'router.created', $router, null, $router->only(['name', 'driver', 'nas_type', 'host', 'port', 'username', 'is_default', 'is_active']));
            return $this->ok('Router saved', ['id' => $router->id]);
        });
    }

    public function test(Request $request)
    {
        if ($r = $this->deny('router')) return $r;
        $router = Router::where('branch_id', $this->branchId)->findOrFail($request->id);
        if ($router->isRadius()) {
            return $this->testRadius($router);
        }
        try {
            $res = (new MikroTikClient($router))->get('/system/resource');
            $status = "OK · RouterOS {$res['version']} · {$res['board-name']} · uptime {$res['uptime']}";
            $router->update(['last_checked_at' => now(), 'last_status' => mb_substr($status, 0, 250)]);
            return $this->ok($status, ['resource' => $res]);
        } catch (\Throwable $th) {
            $router->update(['last_checked_at' => now(), 'last_status' => mb_substr('FAILED · ' . $th->getMessage(), 0, 250)]);
            return send_error($th->getMessage(), null, 422);
        }
    }

    // RADIUS NAS: the FreeRADIUS database is reachable, the NAS is registered as a client, and the
    // last accounting seen from it. (The NAS itself is only contacted for Disconnect/CoA.)
    private function testRadius(Router $router)
    {
        try {
            $db = RadiusDriver::db();
            $registered = $db->table('nas')->where('nasname', $router->host)->exists();
            $online = $db->table('radacct')->where('nasipaddress', $router->host)->whereNull('acctstoptime')->count();
            $last = $db->table('radacct')->where('nasipaddress', $router->host)->max('acctupdatetime');
            $status = 'OK · FreeRADIUS database reachable · NAS ' . ($registered ? 'registered' : 'NOT registered (set the secret and save)')
                . " · {$online} online" . ($last ? " · last accounting {$last}" : ' · no accounting received yet');
            $router->update(['last_checked_at' => now(), 'last_status' => mb_substr($status, 0, 250)]);
            return $this->ok($status);
        } catch (\Throwable $th) {
            $router->update(['last_checked_at' => now(), 'last_status' => mb_substr('FAILED · ' . $th->getMessage(), 0, 250)]);
            return send_error('RADIUS database: ' . $th->getMessage(), null, 422);
        }
    }

    // Read-only setup check for the Routers page guide: what the router has for PPPoE customers to connect.
    public function readiness(Request $request)
    {
        if ($r = $this->deny('router')) return $r;
        $router = Router::where('branch_id', $this->branchId)->findOrFail($request->id);
        if ($router->isRadius()) {
            return send_error('The setup check reads a MikroTik router over its API. For a RADIUS NAS use Test.', null, 422);
        }
        $api = new MikroTikClient($router);
        try {
            $res = $api->get('/system/resource');
            $profiles = $api->get('/ppp/profile');
            $ours = array_values(array_filter($profiles, fn ($p) => str_starts_with($p['comment'] ?? '', 'ISP package') || str_starts_with($p['name'] ?? '', 'isp-pkg-')));
            $default = collect($profiles)->firstWhere('name', 'default') ?? [];
            $bw = $api->get('/tool/bandwidth-server');
            return response()->json([
                'version' => $res['version'] ?? '',
                'board' => $res['board-name'] ?? '',
                'interfaces' => array_values(array_map(fn ($i) => ['name' => $i['name'], 'type' => $i['type'] ?? '', 'running' => ($i['running'] ?? 'false') === 'true'], array_filter($api->get('/interface'), fn ($i) => ! str_starts_with($i['name'] ?? '', '<')))),
                'addresses' => array_map(fn ($a) => ['address' => $a['address'], 'interface' => $a['interface'] ?? ''], $api->get('/ip/address')),
                'pppoe_servers' => array_map(fn ($s) => ['service' => $s['service-name'] ?? '', 'interface' => $s['interface'] ?? '', 'profile' => $s['default-profile'] ?? '', 'enabled' => ($s['disabled'] ?? 'false') !== 'true'], $api->get('/interface/pppoe-server/server')),
                'pools' => array_map(fn ($p) => ['name' => $p['name'], 'ranges' => $p['ranges'] ?? ''], $api->get('/ip/pool')),
                'default_profile' => ['local' => $default['local-address'] ?? '', 'remote' => $default['remote-address'] ?? ''],
                'profiles' => array_map(fn ($p) => ['name' => $p['name'], 'rate' => $p['rate-limit'] ?? '', 'local' => $p['local-address'] ?? '', 'remote' => $p['remote-address'] ?? ''], $ours),
                'masquerade' => count(array_filter($api->get('/ip/firewall/nat'), fn ($n) => ($n['action'] ?? '') === 'masquerade' && ($n['disabled'] ?? 'false') !== 'true')),
                'bandwidth_server' => ($bw['enabled'] ?? 'false') === 'true',
                'hotspot_servers' => count($api->get('/ip/hotspot')),
                'online' => count($api->get('/ppp/active')),
            ]);
        } catch (\Throwable $th) {
            return send_error($th->getMessage(), null, 422);
        }
    }

    // Live PPPoE + Hotspot sessions, matched to connections by username.
    public function sessions(Request $request)
    {
        if ($r = $this->deny('router')) return $r;
        $router = Router::where('branch_id', $this->branchId)->findOrFail($request->id);
        $sessions = [];
        if ($router->isRadius()) {
            // from RADIUS accounting: sessions this NAS reported as started and not stopped
            try {
                foreach (RadiusDriver::db()->table('radacct')->where('nasipaddress', $router->host)->whereNull('acctstoptime')->orderByDesc('acctstarttime')->limit(2000)->get() as $s) {
                    $sessions[] = ['type' => 'radius', 'id' => $s->acctsessionid, 'user' => $s->username, 'address' => $s->framedipaddress, 'mac' => $s->callingstationid,
                        'uptime' => $s->acctstarttime ? \Illuminate\Support\Carbon::parse($s->acctstarttime)->diffForHumans(null, true) : ''];
                }
            } catch (\Throwable $th) {
                return send_error($th->getMessage(), null, 422);
            }
            return $this->withConnections($sessions);
        }
        $api = new MikroTikClient($router);
        try {
            foreach ($api->get('/ppp/active') as $s) {
                $sessions[] = ['type' => 'pppoe', 'id' => $s['.id'], 'user' => $s['name'] ?? '', 'address' => $s['address'] ?? '', 'mac' => $s['caller-id'] ?? '', 'uptime' => $s['uptime'] ?? ''];
            }
            foreach ($api->get('/ip/hotspot/active') as $s) {
                $sessions[] = ['type' => 'hotspot', 'id' => $s['.id'], 'user' => $s['user'] ?? '', 'address' => $s['address'] ?? '', 'mac' => $s['mac-address'] ?? '', 'uptime' => $s['uptime'] ?? ''];
            }
        } catch (\Throwable $th) {
            return send_error($th->getMessage(), null, 422);
        }
        return $this->withConnections($sessions);
    }

    private function withConnections(array $sessions)
    {
        $connections = Connection::with('customer:id,name,code')->where('branch_id', $this->branchId)
            ->whereIn('pppoe_username', array_column($sessions, 'user'))->get()->keyBy('pppoe_username');
        return response()->json(array_map(fn ($s) => $s + [
            'connection' => optional($connections->get($s['user']))->only(['id', 'code', 'status', 'customer']),
        ], $sessions));
    }

    public function syncAll(Request $request)
    {
        if ($r = $this->deny('router')) return $r;
        $router = Router::where('branch_id', $this->branchId)->findOrFail($request->id);
        $ids = Connection::where('branch_id', $this->branchId)->whereIn('connection_type', ['pppoe', 'hotspot'])->whereNotNull('pppoe_username')
            ->where(fn ($q) => $router->is_default ? $q->where('router_id', $router->id)->orWhereNull('router_id') : $q->where('router_id', $router->id))
            ->pluck('id');
        foreach ($ids as $id) {
            SyncConnectionToNetwork::dispatch($id);
        }
        if (config('queue.default') !== 'sync') {
            // a queue worker pushes them; each connection's sync status shows the result
            AuditLogger::log('router.sync_all', $router, null, ['connections' => $ids->count(), 'queued' => true]);
            return $this->ok("{$ids->count()} connection(s) queued for {$router->name}. Each connection's sync status updates as they are pushed.");
        }
        $failed = Connection::whereIn('id', $ids)->whereNotNull('network_sync_error')->count();
        AuditLogger::log('router.sync_all', $router, null, ['connections' => $ids->count(), 'failed' => $failed]);
        return $this->ok("{$ids->count()} connection(s) pushed to {$router->name}" . ($failed ? ", {$failed} failed — see connection sync errors" : ''));
    }

    public function destroy(Request $request)
    {
        if ($r = $this->deny('router')) return $r;
        $router = Router::where('branch_id', $this->branchId)->findOrFail($request->id);
        if (Connection::where('router_id', $router->id)->exists()) {
            return send_error('Connections are assigned to this router. Move them first.', null, 422);
        }
        $router->delete();
        if ($router->isRadius()) {
            $router->is_active = false;
            RadiusDriver::syncNas($router); // no longer a FreeRADIUS client
        }
        AuditLogger::log('router.deleted', $router, $router->only(['name', 'host']));
        return $this->ok('Router deleted');
    }
}
