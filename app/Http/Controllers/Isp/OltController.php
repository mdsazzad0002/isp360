<?php

namespace App\Http\Controllers\Isp;

use App\Models\Connection;
use App\Models\NetworkAlert;
use App\Models\Olt;
use App\Models\Onu;
use App\Services\Isp\AuditLogger;
use App\Services\Network\OltAlerts;
use App\Services\Network\OltCli;
use App\Services\Network\OltService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// Network → OLT & ONU: the branch's OLTs, read over SNMP, and the ONUs on their PON ports.
class OltController extends IspController
{
    public function create()
    {
        return $this->page('olt', 'Isp/Olt', [
            'vendors' => Olt::vendors(),
            // which vendors have CLI commands, and which values their authorise command needs
            'cliVendors' => collect(config('olt_vendors'))->filter(fn ($v) => ! empty($v['cli']['commands']))
                ->map(fn ($v) => ['authorize' => self::needs($v['cli']['commands']['authorize'] ?? []), 'unregistered' => ! empty($v['cli']['commands']['unregistered'])]),
            'weakRx' => (float) config('isp.olt.weak_rx_dbm', -27),
            'statuses' => Onu::STATUSES,
            'alertKinds' => NetworkAlert::KINDS,
            'canConfigure' => checkAccess('oltConfigure'),
        ]);
    }

    // placeholders a command template uses, e.g. ['serial', 'line_profile', 'vlan']
    private static function needs(array $lines): array
    {
        preg_match_all('/\{(\w+)\}/', implode("\n", $lines), $m);
        return array_values(array_unique(array_map(fn ($p) => match ($p) { 'serial_hex' => 'serial', 'mac_dotted' => 'mac', 'name_token' => 'name', default => $p }, $m[1])));
    }

    public function index()
    {
        $weak = (float) config('isp.olt.weak_rx_dbm', -27);
        $counts = Onu::where('branch_id', $this->branchId)->groupBy('olt_id')
            ->selectRaw("olt_id, count(*) as total, sum(status = 'online') as online, sum(rx_power < ?) as weak, sum(connection_id is not null) as linked", [$weak])
            ->get()->keyBy('olt_id');
        $alerts = NetworkAlert::where('branch_id', $this->branchId)->whereNull('resolved_at')->groupBy('olt_id')->selectRaw('olt_id, count(*) as total')->pluck('total', 'olt_id');
        return response()->json(Olt::where('branch_id', $this->branchId)->orderBy('name')->get()->map(fn (Olt $o) => $o->toArray() + [
            'can_poll' => $o->canPoll(),
            'can_configure' => $o->canConfigure(),
            'has_community' => filled($o->snmp_community),
            'has_cli_password' => filled($o->cli_password),
            'open_alerts' => (int) ($alerts[$o->id] ?? 0),
            'onus_total' => (int) ($counts[$o->id]->total ?? 0),
            'onus_online' => (int) ($counts[$o->id]->online ?? 0),
            'onus_weak' => (int) ($counts[$o->id]->weak ?? 0),
            'onus_linked' => (int) ($counts[$o->id]->linked ?? 0),
        ]));
    }

    public function store(Request $request)
    {
        if ($r = $this->deny('olt')) return $r;
        if ($r = $this->validateOrFail($request->all(), [
            'id' => 'nullable|integer',
            'name' => 'required|max:100',
            'vendor' => 'required|in:' . implode(',', array_keys(Olt::vendors())),
            'model' => 'nullable|max:100',
            'host' => 'required|max:255',
            'snmp_port' => 'nullable|integer|min:1|max:65535',
            'snmp_community' => 'nullable|max:100',
            'location' => 'nullable|max:255',
            'notes' => 'nullable|max:255',
            'cli_protocol' => 'nullable|in:none,ssh,telnet',
            'cli_port' => 'nullable|integer|min:1|max:65535',
            'cli_username' => 'nullable|max:100',
            'cli_password' => 'nullable|max:255',
            'cli_enable_password' => 'nullable|max:255',
            'provision' => 'nullable|array',
            'provision.line_profile' => 'nullable|max:40',
            'provision.service_profile' => 'nullable|max:40',
            'provision.vlan' => 'nullable|integer|min:1|max:4094',
            'provision.gemport' => 'nullable|integer|min:0|max:4095',
            'provision.onu_type' => 'nullable|max:40',
        ])) return $r;

        $olt = $request->id ? Olt::where('branch_id', $this->branchId)->findOrFail($request->id) : new Olt(['branch_id' => $this->branchId, 'created_by' => $this->userId]);
        $olt->fill($request->only(['name', 'vendor', 'model', 'host', 'location', 'notes']) + [
            'snmp_port' => $request->snmp_port ?: 161,
            'is_active' => $request->boolean('is_active', true),
            'cli_protocol' => $request->cli_protocol ?: 'none',
            'cli_port' => $request->cli_port ?: null,
            'cli_username' => $request->cli_username ?: null,
            'provision' => array_filter((array) $request->provision, fn ($v) => $v !== null && $v !== '') ?: null,
        ]);
        // blank keeps the saved secret
        foreach (['snmp_community', 'cli_password', 'cli_enable_password'] as $secret) {
            if ($request->filled($secret)) {
                $olt->{$secret} = $request->{$secret};
            }
        }
        $olt->updated_by = $this->userId;
        $olt->save();
        AuditLogger::log($request->id ? 'olt.updated' : 'olt.created', $olt, null, $olt->only(['name', 'vendor', 'model', 'host', 'snmp_port', 'is_active', 'cli_protocol', 'cli_port', 'cli_username', 'provision']));
        return $this->ok('OLT saved', ['id' => $olt->id]);
    }

    public function test(Request $request)
    {
        if ($r = $this->deny('olt')) return $r;
        $olt = Olt::where('branch_id', $this->branchId)->findOrFail($request->id);
        if (! filled($olt->snmp_community)) {
            return send_error('Set the SNMP read community first.', null, 422);
        }
        try {
            return $this->ok(OltService::test($olt));
        } catch (\Throwable $th) {
            return send_error($th->getMessage(), null, 422);
        }
    }

    public function poll(Request $request)
    {
        if ($r = $this->deny('olt')) return $r;
        $olt = Olt::where('branch_id', $this->branchId)->findOrFail($request->id);
        if (! $olt->canPoll()) {
            return send_error(empty($olt->vendorConfig()['oids']) ? 'This OLT brand is kept by hand: add its ONUs below.' : 'Set the SNMP read community first.', null, 422);
        }
        try {
            $s = OltService::poll($olt);
            return $this->ok($olt->last_status, ['summary' => $s]);
        } catch (\Throwable $th) {
            return send_error($th->getMessage(), null, 422);
        }
    }

    public function destroy(Request $request)
    {
        if ($r = $this->deny('olt')) return $r;
        $olt = Olt::where('branch_id', $this->branchId)->findOrFail($request->id);
        if (Onu::where('olt_id', $olt->id)->whereNotNull('connection_id')->exists()) {
            return send_error('ONUs on this OLT are linked to connections. Unlink or move them first.', null, 422);
        }
        Onu::where('olt_id', $olt->id)->delete();
        NetworkAlert::where('olt_id', $olt->id)->whereNull('resolved_at')->update(['resolved_at' => now()]);
        $olt->delete();
        AuditLogger::log('olt.deleted', $olt, $olt->only(['name', 'vendor', 'host']));
        return $this->ok('OLT deleted');
    }

    public function onus(Request $request)
    {
        $weak = (float) config('isp.olt.weak_rx_dbm', -27);
        $query = Onu::with(['olt', 'connection.customer:id,code,name,phone'])->where('branch_id', $this->branchId)
            ->when($request->olt_id, fn ($q, $id) => $q->where('olt_id', $id))
            ->when($request->pon_port, fn ($q, $p) => $q->where('pon_port', $p))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->boolean('weak'), fn ($q) => $q->where('rx_power', '<', $weak))
            ->when($request->linked === 'yes', fn ($q) => $q->whereNotNull('connection_id'))
            ->when($request->linked === 'no', fn ($q) => $q->whereNull('connection_id'))
            ->when($request->search, function ($q, $term) {
                $q->where(function ($w) use ($term) {
                    $w->where('serial', 'like', "%{$term}%")->orWhere('mac', 'like', "%{$term}%")->orWhere('name', 'like', "%{$term}%")
                        ->orWhereHas('connection', fn ($c) => $c->where('code', 'like', "%{$term}%")->orWhere('pppoe_username', 'like', "%{$term}%")
                            ->orWhereHas('customer', fn ($cu) => $cu->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%")));
                });
            })
            ->orderBy('olt_id')->orderBy('pon_port')->orderBy('onu_id');

        $page = $query->paginate(min(200, (int) ($request->per_page ?: 50)))->toArray();
        $page['ports'] = $request->olt_id
            ? Onu::where('branch_id', $this->branchId)->where('olt_id', $request->olt_id)->distinct()->orderBy('pon_port')->pluck('pon_port')
            : [];
        return response()->json($page);
    }

    // ONU added or corrected by hand (any brand); a polled OLT refreshes its readings
    public function storeOnu(Request $request)
    {
        if ($r = $this->deny('olt')) return $r;
        if ($r = $this->validateOrFail($request->all(), [
            'id' => 'nullable|integer',
            'olt_id' => ['required', 'integer', Rule::exists('olts', 'id')->where('branch_id', $this->branchId)],
            'pon_port' => 'required|max:50',
            'onu_id' => 'nullable|integer|min:0|max:100000',
            'serial' => 'nullable|max:32',
            'mac' => ['nullable', 'regex:/^([0-9A-Fa-f]{2}[:\-.]?){5}[0-9A-Fa-f]{2}$/'],
            'name' => 'nullable|max:100',
            'notes' => 'nullable|max:255',
            'connection_id' => 'nullable|integer',
        ])) return $r;
        if ($r = $this->checkConnection($request->connection_id, $request->id)) return $r;

        $onu = $request->id ? Onu::where('branch_id', $this->branchId)->findOrFail($request->id) : new Onu(['branch_id' => $this->branchId, 'created_by' => $this->userId]);
        $mac = $request->mac ? implode(':', str_split(strtolower(preg_replace('/[^0-9A-Fa-f]/', '', $request->mac)), 2)) : null;
        $onu->fill($request->only(['olt_id', 'pon_port', 'onu_id', 'name', 'notes', 'connection_id']) + [
            'serial' => $request->serial ? strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $request->serial)) : null,
            'mac' => $mac,
        ]);
        $onu->save();
        AuditLogger::log($request->id ? 'onu.updated' : 'onu.created', $onu, null, $onu->only(['olt_id', 'pon_port', 'onu_id', 'serial', 'mac', 'connection_id']));
        $this->toCustomer($onu);
        return $this->ok('ONU saved', ['id' => $onu->id]);
    }

    // link an ONU to a connection, or unlink it (connection_id empty)
    public function link(Request $request)
    {
        if ($r = $this->deny('olt')) return $r;
        $onu = Onu::where('branch_id', $this->branchId)->findOrFail($request->id);
        if ($r = $this->checkConnection($request->connection_id, $onu->id)) return $r;
        $old = $onu->connection_id;
        $onu->update(['connection_id' => $request->connection_id ?: null]);
        AuditLogger::log($onu->connection_id ? 'onu.linked' : 'onu.unlinked', $onu, ['connection_id' => $old], ['connection_id' => $onu->connection_id]);
        $this->toCustomer($onu);
        return $this->ok($onu->connection_id ? 'ONU linked to the connection' : 'ONU unlinked');
    }

    public function destroyOnu(Request $request)
    {
        if ($r = $this->deny('olt')) return $r;
        $onu = Onu::where('branch_id', $this->branchId)->findOrFail($request->id);
        $onu->delete();
        AuditLogger::log('onu.deleted', $onu, $onu->only(['olt_id', 'pon_port', 'onu_id', 'serial', 'mac', 'connection_id']));
        return $this->ok('ONU removed from the list (a polled OLT adds it again while it is still there)');
    }

    // Reboot / rename / delete an ONU on the OLT. Without `run` it only returns the commands it would send.
    public function action(Request $request)
    {
        if ($r = $this->deny('oltConfigure')) return $r;
        if ($r = $this->validateOrFail($request->all(), [
            'id' => 'required|integer',
            'action' => 'required|in:reboot,rename,delete',
            'name' => 'required_if:action,rename|nullable|max:32',
        ])) return $r;
        $onu = Onu::where('branch_id', $this->branchId)->findOrFail($request->id);
        $olt = Olt::findOrFail($onu->olt_id);
        if ($request->action === 'delete' && $onu->connection_id) {
            return send_error('This ONU is linked to a connection. Unlink it first.', null, 422);
        }
        try {
            $lines = OltCli::commands($olt, $request->action, $onu->only(['pon_port', 'onu_id', 'serial', 'mac']) + ['name' => $request->name]);
            if (! $request->boolean('run')) {
                return response()->json(['commands' => $lines]);
            }
            $transcript = OltCli::run($olt, $lines);
        } catch (\Throwable $th) {
            AuditLogger::log("onu.{$request->action}_failed", $onu, null, ['commands' => $lines ?? [], 'error' => mb_substr($th->getMessage(), 0, 3000)]);
            return send_error($th->getMessage(), null, 422);
        }
        AuditLogger::log("onu.{$request->action}", $onu, $onu->only(['pon_port', 'onu_id', 'serial', 'mac', 'name']), ['commands' => $lines]);
        if ($request->action === 'rename') {
            $onu->update(['name' => $request->name]);
        } elseif ($request->action === 'delete') {
            $onu->delete();
        }
        return $this->ok(['reboot' => 'ONU rebooting', 'rename' => 'ONU renamed', 'delete' => 'ONU deleted from the OLT'][$request->action], ['transcript' => $transcript]);
    }

    // ONUs the OLT sees on its PON ports that are not authorised yet
    public function unregistered(Request $request)
    {
        if ($r = $this->deny('oltConfigure')) return $r;
        $olt = Olt::where('branch_id', $this->branchId)->findOrFail($request->id);
        try {
            return response()->json(['onus' => OltCli::unregistered($olt)]);
        } catch (\Throwable $th) {
            return send_error($th->getMessage(), null, 422);
        }
    }

    // Authorise an ONU on the OLT (add it with its profiles and VLAN), then put it on the list, linked to
    // the connection when one is given. Without `run` it only returns the commands.
    public function authorizeOnu(Request $request)
    {
        if ($r = $this->deny('oltConfigure')) return $r;
        if ($r = $this->validateOrFail($request->all(), [
            'olt_id' => ['required', 'integer', Rule::exists('olts', 'id')->where('branch_id', $this->branchId)],
            'pon_port' => 'required|max:50',
            'onu_id' => 'nullable|integer|min:0|max:100000',
            'serial' => 'nullable|max:32',
            'mac' => 'nullable|max:20',
            'name' => 'required|max:32',
            'line_profile' => 'nullable|max:40',
            'service_profile' => 'nullable|max:40',
            'vlan' => 'nullable|integer|min:1|max:4094',
            'gemport' => 'nullable|integer|min:0|max:4095',
            'onu_type' => 'nullable|max:40',
            'connection_id' => 'nullable|integer',
        ])) return $r;
        if ($r = $this->checkConnection($request->connection_id, null)) return $r;
        $olt = Olt::findOrFail($request->olt_id);

        $values = array_merge((array) $olt->provision, array_filter($request->only(['line_profile', 'service_profile', 'vlan', 'gemport', 'onu_type']), fn ($v) => $v !== null && $v !== ''));
        try {
            $values += [
                'pon_port' => $request->pon_port,
                'onu_id' => $request->filled('onu_id') ? (int) $request->onu_id : OltCli::nextOnuId($olt, $request->pon_port),
                'serial' => $request->serial, 'mac' => $request->mac, 'name' => $request->name,
            ];
            $lines = OltCli::commands($olt, 'authorize', $values);
            if (! $request->boolean('run')) {
                return response()->json(['commands' => $lines, 'onu_id' => $values['onu_id']]);
            }
            $transcript = OltCli::run($olt, $lines);
        } catch (\Throwable $th) {
            AuditLogger::log('onu.authorize_failed', $olt, null, ['commands' => $lines ?? [], 'error' => mb_substr($th->getMessage(), 0, 3000)]);
            return send_error($th->getMessage(), null, 422);
        }

        $serial = $request->serial ? strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $request->serial)) : null;
        $mac = $request->mac ? \App\Services\Network\SnmpOltDriver::mac($request->mac) : null;
        $onu = Onu::where('olt_id', $olt->id)->where(fn ($q) => $serial ? $q->where('serial', $serial) : $q->where('mac', $mac))->first()
            ?? new Onu(['branch_id' => $this->branchId, 'olt_id' => $olt->id, 'created_by' => $this->userId]);
        $onu->fill(['pon_port' => $request->pon_port, 'onu_id' => $values['onu_id'], 'serial' => $serial, 'mac' => $mac, 'name' => $request->name,
            'connection_id' => $request->connection_id ?: $onu->connection_id])->save();
        AuditLogger::log('onu.authorized', $onu, null, ['commands' => $lines, 'connection_id' => $onu->connection_id]);
        $this->toCustomer($onu);
        if ($olt->canPoll()) {
            try {
                OltService::poll($olt); // status and power right away
            } catch (\Throwable) {
                // the next scheduled poll fills them in
            }
        }
        return $this->ok("ONU authorised as {$request->pon_port}:{$values['onu_id']}", ['id' => $onu->id, 'transcript' => $transcript]);
    }

    public function alerts(Request $request)
    {
        $page = NetworkAlert::with('olt')->where('branch_id', $this->branchId)
            ->when($request->olt_id, fn ($q, $id) => $q->where('olt_id', $id))
            ->when($request->kind, fn ($q, $k) => $q->where('kind', $k))
            ->when($request->boolean('open'), fn ($q) => $q->whereNull('resolved_at'))
            ->orderByRaw('resolved_at is not null')->latest('started_at')
            ->paginate(min(100, (int) ($request->per_page ?: 20)));
        $page->getCollection()->transform(fn (NetworkAlert $a) => $a->toArray() + [
            'customers' => $a->kind === 'onu_weak' ? null : OltAlerts::affectedConnections($a)->pluck('customer_id')->unique()->count(),
        ]);
        return response()->json($page);
    }

    // Outage notice to the customers behind an alert (SMS template "outage")
    public function notifyAlert(Request $request)
    {
        if ($r = $this->deny('olt')) return $r;
        $alert = NetworkAlert::with('olt')->where('branch_id', $this->branchId)->findOrFail($request->id);
        if ($alert->kind === 'onu_weak') {
            return send_error('A weak signal is not an outage: open a ticket for the customer instead.', null, 422);
        }
        try {
            $sent = OltAlerts::notifyCustomers($alert);
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
        return $this->ok("Outage notice sent to {$sent} customer(s)");
    }

    // an ONU from stock now on a customer's line leaves the store / technician (Inventory)
    private function toCustomer(Onu $onu): void
    {
        try {
            \App\Services\Isp\InventoryService::onuInstalled($onu, $this->userId);
        } catch (\Throwable $th) {
            \Illuminate\Support\Facades\Log::warning('ONU stock not moved', ['onu' => $onu->id, 'error' => $th->getMessage()]);
        }
    }

    // The connection must be this branch's, and not already on another ONU.
    private function checkConnection($connectionId, $onuId)
    {
        if (! $connectionId) {
            return null;
        }
        if (! Connection::where('branch_id', $this->branchId)->whereKey($connectionId)->exists()) {
            return send_error('Connection not found', null, 422);
        }
        $other = Onu::with('olt')->where('connection_id', $connectionId)->when($onuId, fn ($q) => $q->where('id', '!=', $onuId))->first();
        return $other ? send_error("That connection is already on ONU {$other->pon_port}:{$other->onu_id} of {$other->olt?->name}. Unlink it there first.", null, 422) : null;
    }
}
