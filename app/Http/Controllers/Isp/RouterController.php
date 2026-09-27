<?php

namespace App\Http\Controllers\Isp;

use App\Jobs\SyncConnectionToNetwork;
use App\Models\Connection;
use App\Models\Router;
use App\Services\Isp\AuditLogger;
use App\Services\Network\MikroTikClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RouterController extends IspController
{
    public function create()
    {
        return $this->page('router', 'Isp/Router');
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
            'host' => 'required|max:255',
            'port' => 'nullable|integer|min:1|max:65535',
            'username' => 'required|max:100',
            'password' => $request->id ? 'nullable|max:255' : 'required|max:255',
        ])) return $r;

        return DB::transaction(function () use ($request) {
            $router = $request->id ? Router::where('branch_id', $this->branchId)->findOrFail($request->id) : new Router(['branch_id' => $this->branchId, 'created_by' => $this->userId]);
            $router->fill($request->only(['name', 'host', 'port', 'username']) + [
                'use_https' => $request->boolean('use_https'),
                'is_active' => $request->boolean('is_active', true),
                'is_default' => $request->boolean('is_default'),
            ]);
            if ($request->filled('password')) {
                $router->password = $request->password;
            }
            $router->updated_by = $this->userId;
            $router->save();
            if ($router->is_default) {
                Router::where('branch_id', $this->branchId)->where('id', '!=', $router->id)->update(['is_default' => false]);
            }
            AuditLogger::log($request->id ? 'router.updated' : 'router.created', $router, null, $router->only(['name', 'host', 'port', 'username', 'is_default', 'is_active']));
            return $this->ok('Router saved', ['id' => $router->id]);
        });
    }

    public function test(Request $request)
    {
        if ($r = $this->deny('router')) return $r;
        $router = Router::where('branch_id', $this->branchId)->findOrFail($request->id);
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

    // Live PPPoE + Hotspot sessions, matched to connections by username.
    public function sessions(Request $request)
    {
        if ($r = $this->deny('router')) return $r;
        $router = Router::where('branch_id', $this->branchId)->findOrFail($request->id);
        $api = new MikroTikClient($router);
        $sessions = [];
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
        AuditLogger::log('router.deleted', $router, $router->only(['name', 'host']));
        return $this->ok('Router deleted');
    }
}
