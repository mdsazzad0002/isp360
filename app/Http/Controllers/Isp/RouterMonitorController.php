<?php

namespace App\Http\Controllers\Isp;

use App\Models\Router;
use App\Services\Isp\AuditLogger;
use App\Services\Network\MikroTikClient;
use App\Services\Network\MikroTikDriver;
use Illuminate\Http\Request;

// Live monitor of one MikroTik router (roadmap 4.6, monitoring step 1): CPU, memory, disk, health,
// online users and interfaces, read on demand while the page is open. Nothing is stored except the
// pinned interfaces. The browser only talks to this server, never to the router.
class RouterMonitorController extends IspController
{
    // interface names as RouterOS shows them; no comma, which monitor-traffic reads as a list
    private const NAME_RULE = ['string', 'max:64', 'regex:/^[^,<>]+$/'];

    public function show(int $id)
    {
        $router = Router::where('branch_id', $this->branchId)->findOrFail($id);
        return $this->page('router', 'Isp/RouterMonitor', [
            'router' => $router->only(['id', 'name', 'host', 'port', 'use_https', 'driver', 'nas_type', 'is_active']) + ['monitor_interfaces' => $router->monitor_interfaces ?? []],
        ]);
    }

    // One reading of everything but the graph; the page asks every few seconds.
    public function overview(Request $request)
    {
        if ($r = $this->deny('router')) return $r;
        $router = $this->router($request);
        if ($router->isRadius()) {
            return send_error('The live monitor reads a MikroTik router over its API. A RADIUS NAS only reports sessions.', null, 422);
        }
        $api = new MikroTikClient($router);
        try {
            return response()->json([
                'resource' => MikroTikDriver::resourceSample($api),
                'interfaces' => MikroTikDriver::interfaceList($api),
                'online' => MikroTikDriver::onlineCount($api),
                'at' => microtime(true),
            ]);
        } catch (\Throwable $th) {
            return send_error($th->getMessage(), null, 422);
        }
    }

    // One live rate of one interface, in the shape LiveTrafficChart reads (down = rx, up = tx).
    public function traffic(Request $request)
    {
        if ($r = $this->deny('router')) return $r;
        if ($r = $this->validateOrFail($request->all(), ['id' => 'required|integer', 'interface' => array_merge(['required'], self::NAME_RULE)])) return $r;
        $router = $this->router($request);
        if ($router->isRadius()) {
            return response()->json(['managed' => false]);
        }
        try {
            $sample = MikroTikDriver::interfaceTraffic(new MikroTikClient($router), $request->interface);
            return response()->json([
                'managed' => true,
                'online' => (bool) $sample,
                'sample' => $sample ? ['down_bps' => $sample['rx_bps'], 'up_bps' => $sample['tx_bps']] : null,
                'at' => microtime(true),
            ]);
        } catch (\Throwable $th) {
            return send_error($th->getMessage(), null, 422);
        }
    }

    // Pinned interfaces (WAN / uplink): listed first and graphed when the monitor opens.
    public function pins(Request $request)
    {
        if ($r = $this->deny('router')) return $r;
        if ($r = $this->validateOrFail($request->all(), [
            'id' => 'required|integer',
            'interfaces' => 'present|array|max:4',
            'interfaces.*' => self::NAME_RULE,
        ], ['interfaces.max' => 'Pin at most 4 interfaces.'])) return $r;
        $router = $this->router($request);
        $old = $router->monitor_interfaces ?? [];
        $router->monitor_interfaces = array_values(array_unique($request->interfaces));
        $router->updated_by = $this->userId;
        $router->save();
        AuditLogger::log('router.monitor_pins', $router, ['interfaces' => $old], ['interfaces' => $router->monitor_interfaces]);
        return $this->ok('Pinned interfaces saved', ['interfaces' => $router->monitor_interfaces]);
    }

    private function router(Request $request): Router
    {
        return Router::where('branch_id', $this->branchId)->findOrFail((int) $request->id);
    }
}
