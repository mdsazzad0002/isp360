<?php

namespace App\Http\Controllers\Isp;

use App\Models\IpPool;
use App\Services\Isp\AuditLogger;
use App\Services\Isp\IpamService;
use Illuminate\Http\Request;

// Network → IP Pools: static IPv4, IPv6 prefix delegation and deterministic CGNAT.
class IpPoolController extends IspController
{
    public function create()
    {
        return $this->page('ipPool', 'Isp/IpPool');
    }

    public function index()
    {
        return response()->json(IpPool::where('branch_id', $this->branchId)->orderBy('type')->orderBy('name')->get()->map(function (IpPool $p) {
            $row = $p->toArray() + ['size' => IpamService::size($p), 'used' => IpamService::used($p)];
            if ($p->type === 'cgnat') {
                $row['users_per_ip'] = IpamService::usersPerPublicIp($p);
            }
            return $row;
        }));
    }

    public function store(Request $request)
    {
        if ($r = $this->deny('ipPool')) return $r;
        if ($r = $this->validateOrFail($request->all(), [
            'id' => 'nullable|integer',
            'name' => 'required|max:100',
            'type' => 'required|in:' . implode(',', array_keys(IpPool::TYPES)),
            'network' => 'required|max:64',
            'gateway' => 'nullable|ip',
            'delegated_length' => 'required_if:type,ipv6_pd|nullable|integer|min:48|max:64',
            'public_network' => 'required_if:type,cgnat|nullable|max:45',
            'ports_per_user' => 'required_if:type,cgnat|nullable|integer|min:64|max:64512',
            'port_start' => 'nullable|integer|min:1|max:65000',
            'router_id' => 'nullable|integer|exists:routers,id',
            'notes' => 'nullable|max:255',
        ])) return $r;
        try {
            $pool = $request->id ? IpPool::where('branch_id', $this->branchId)->findOrFail($request->id) : new IpPool(['branch_id' => $this->branchId, 'created_by' => $this->userId]);
            $pool->fill($request->only(['name', 'type', 'network', 'gateway', 'delegated_length', 'public_network', 'ports_per_user', 'router_id', 'notes']) + ['port_start' => $request->port_start ?: 1024]);
            // checks the networks parse (throws a readable error otherwise)
            $pool->type === 'ipv6_pd' ? IpamService::prefix6($pool->network) : IpamService::range4($pool->network);
            if ($pool->type === 'ipv6_pd' && $pool->delegated_length <= IpamService::prefix6($pool->network)[1]) {
                throw new \RuntimeException('The delegated prefix must be longer than the pool prefix (e.g. /40 pool, /56 each).');
            }
            if ($pool->type === 'cgnat') {
                IpamService::range4($pool->public_network);
            }
            $pool->save();
            IpamService::flush();
            AuditLogger::log($request->id ? 'ip_pool.updated' : 'ip_pool.created', $pool, null, $pool->only(['name', 'type', 'network', 'public_network', 'ports_per_user']));
            return $this->ok('Pool saved', ['id' => $pool->id]);
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
    }

    public function destroy(Request $request)
    {
        if ($r = $this->deny('ipPool')) return $r;
        $pool = IpPool::where('branch_id', $this->branchId)->findOrFail($request->id);
        $pool->delete();
        AuditLogger::log('ip_pool.deleted', $pool, $pool->only(['name', 'network']));
        return $this->ok('Pool deleted (addresses already given to connections stay on them)');
    }

    // Next free address / prefix of a pool, for the connection form.
    public function next(Request $request)
    {
        $pool = IpPool::where('branch_id', $this->branchId)->findOrFail($request->id);
        $value = $pool->type === 'ipv6_pd' ? IpamService::nextPrefix($pool) : IpamService::nextStatic($pool);
        return $value ? response()->json(['value' => $value]) : send_error("Pool {$pool->name} is full.", null, 422);
    }

    // CGNAT: which private address had a public address + port (then search the session log for it).
    public function lookup(Request $request)
    {
        if ($r = $this->validateOrFail($request->all(), ['ip' => 'required|ip', 'port' => 'required|integer|min:1|max:65535'])) return $r;
        foreach (IpPool::where('branch_id', $this->branchId)->where('type', 'cgnat')->get() as $pool) {
            if ($private = IpamService::privateFor($pool, $request->ip, (int) $request->port)) {
                return response()->json(['pool' => $pool->name, 'private_ip' => $private] + IpamService::natFor($pool, $private));
            }
        }
        return send_error('No CGNAT pool of this branch maps that address and port.', null, 404);
    }

    // RouterOS rules implementing the pool's deterministic mapping.
    public function script(Request $request)
    {
        if (! checkAccess('ipPool')) abort(403);
        $pool = IpPool::where('branch_id', $this->branchId)->where('type', 'cgnat')->findOrFail($request->id);
        return response(IpamService::mikrotikScript($pool), 200, ['Content-Type' => 'text/plain', 'Content-Disposition' => "attachment; filename=\"cgnat-{$pool->id}.rsc\""]);
    }
}
