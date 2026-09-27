<?php

namespace App\Http\Controllers\Isp;

use App\Models\Connection;
use App\Services\Isp\AuditLogger;
use App\Services\Isp\BillingService;
use App\Services\Isp\ConnectionService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ConnectionController extends IspController
{
    public function create()
    {
        return $this->page('connection', 'Isp/Connection', [
            'canAct' => checkAccess('connectionAction'),
            'canSecret' => checkAccess('connectionSecret'),
        ]);
    }

    public function index(Request $request)
    {
        $query = Connection::with(['customer:id,code,name,phone,area_id,zone_id', 'customer.area:id,name', 'package:id,name,price,download_mbps,network_profile', 'box:id,name,code'])
            ->where('branch_id', $this->branchId)
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->syncStatus, fn ($q, $s) => $q->where('network_sync_status', $s))
            ->when($request->customerId, fn ($q, $id) => $q->where('customer_id', $id))
            ->when($request->packageId, fn ($q, $id) => $q->where('package_id', $id))
            ->when($request->boxId, fn ($q, $id) => $q->where('box_id', $id))
            ->when($request->areaId, fn ($q, $id) => $q->whereHas('customer', fn ($c) => $c->where('area_id', $id)))
            ->when($request->search, function ($q, $term) {
                $q->where(function ($w) use ($term) {
                    $w->where('code', 'like', "%{$term}%")
                        ->orWhere('pppoe_username', 'like', "%{$term}%")
                        ->orWhere('static_ip', 'like', "%{$term}%")
                        ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%"));
                });
            })
            ->latest('id');

        $page = $query->paginate(min(100, (int) ($request->per_page ?: 20)));
        // "active but not really on the router" is what staff need to spot
        $page = $page->toArray() + ['syncCounts' => Connection::where('branch_id', $this->branchId)->where('status', 'active')
            ->groupBy('network_sync_status')->selectRaw('network_sync_status, count(*) as total')->pluck('total', 'network_sync_status')];
        return response()->json($page);
    }

    public function show(Request $request)
    {
        $connection = Connection::with(['customer:id,code,name,phone,reseller_id', 'package', 'box:id,name,code', 'router', 'histories.createdBy', 'packageHistories.oldPackage', 'packageHistories.newPackage', 'packageHistories.changedBy'])
            ->where('branch_id', $this->branchId)->findOrFail($request->id);
        return response()->json($connection);
    }

    // PPPoE password is hidden everywhere; revealing it is a separate, audited permission.
    public function secret(Request $request)
    {
        if ($r = $this->deny('connectionSecret')) return $r;
        $connection = Connection::where('branch_id', $this->branchId)->findOrFail($request->id);
        AuditLogger::log('connection.secret_viewed', $connection);
        return response()->json(['password' => $connection->pppoe_password]);
    }

    public function store(Request $request)
    {
        if ($r = $this->deny('connection')) return $r;
        if ($r = $this->validateOrFail($request->all(), $this->rules($request))) return $r;
        try {
            if ($request->id) {
                $connection = ConnectionService::update(
                    Connection::where('branch_id', $this->branchId)->findOrFail($request->id),
                    $request->only(['connection_type', 'pppoe_username', 'pppoe_password', 'static_ip', 'mac_address', 'box_id', 'router_id', 'discount', 'installation_date', 'notes', 'reason'])
                );
                return $this->ok('Connection updated successfully', ['id' => $connection->id]);
            }

            $connection = ConnectionService::create($request->all(), $this->branchId);
            $message = "Connection {$connection->code} created";
            if ($request->boolean('charge_installation') && $connection->package && ((float) $connection->package->installation_fee > 0 || (float) $connection->package->activation_fee > 0)) {
                $items = [];
                foreach (['installation_fee' => 'Installation fee', 'activation_fee' => 'Activation fee'] as $field => $label) {
                    if ((float) $connection->package->{$field} > 0) {
                        $items[] = ['description' => "{$label} — {$connection->code}", 'unit_price' => (float) $connection->package->{$field}, 'quantity' => 1];
                    }
                }
                $invoice = BillingService::createManual($connection->customer, ['connection_id' => $connection->id], $items);
                $message .= " with invoice {$invoice->invoice_no}";
            }
            return $this->ok($message, ['id' => $connection->id]);
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
    }

    public function action(Request $request)
    {
        if ($r = $this->deny('connectionAction')) return $r;
        if ($r = $this->validateOrFail($request->all(), [
            'id' => 'required|integer',
            'action' => 'required|in:activate,suspend,reactivate,deactivate,terminate',
            'reason' => 'required_unless:action,activate|nullable|max:255',
            'date' => 'nullable|date',
        ])) return $r;

        try {
            $connection = Connection::where('branch_id', $this->branchId)->findOrFail($request->id);
            $connection = match ($request->action) {
                'activate' => ConnectionService::activate($connection, $request->date),
                'suspend' => ConnectionService::suspend($connection, $request->reason),
                'reactivate' => ConnectionService::reactivate($connection, $request->reason),
                'deactivate' => ConnectionService::deactivate($connection, $request->reason),
                'terminate' => ConnectionService::terminate($connection, $request->reason),
            };
            return $this->ok("Connection {$connection->code} is now {$connection->status}");
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
    }

    public function changePackage(Request $request)
    {
        if ($r = $this->deny('connectionAction')) return $r;
        if ($r = $this->validateOrFail($request->all(), [
            'id' => 'required|integer',
            'package_id' => 'required|integer',
            'effective_date' => 'nullable|date',
            'reason' => 'nullable|max:255',
        ])) return $r;
        try {
            $connection = Connection::where('branch_id', $this->branchId)->findOrFail($request->id);
            $connection = ConnectionService::changePackage($connection, (int) $request->package_id, $request->effective_date, $request->reason);
            return $this->ok("Package changed to {$connection->package->name}. The new price applies from the next invoice.");
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
    }

    // Push the current state to the router again (after a router outage, manual edit, etc).
    public function sync(Request $request)
    {
        if ($r = $this->deny('connectionAction')) return $r;
        $connection = Connection::where('branch_id', $this->branchId)->findOrFail($request->id);
        \App\Jobs\SyncConnectionToNetwork::dispatchSync($connection->id);
        $connection->refresh();
        return match ($connection->network_sync_status) {
            'failed' => send_error('Router sync failed: ' . $connection->network_sync_error, null, 422),
            'not_managed' => $this->ok('Nothing pushed: ' . $connection->network_sync_note),
            default => $this->ok('Connection synced to router'),
        };
    }

    // Is the PPPoE user online right now?
    public function online(Request $request)
    {
        $connection = Connection::where('branch_id', $this->branchId)->findOrFail($request->id);
        $router = \App\Models\Router::forConnection($connection);
        if (! $router || ! $connection->pppoe_username) {
            return response()->json(['managed' => false]);
        }
        try {
            $session = \App\Services\Network\MikroTikDriver::activeSession(new \App\Services\Network\MikroTikClient($router), $connection);
            return response()->json(['managed' => true, 'router' => $router->name, 'router_host' => $router->host, 'online' => (bool) $session, 'session' => $session]);
        } catch (\Throwable $th) {
            return response()->json(['managed' => true, 'router' => $router->name, 'router_host' => $router->host, 'error' => $th->getMessage()]);
        }
    }

    // Live check against the router (one id, or up to 100 ids from the list). Read-only on the router.
    public function verify(Request $request)
    {
        if ($r = $this->deny('connection')) return $r;
        if ($r = $this->validateOrFail($request->all(), ['id' => 'required_without:ids|integer', 'ids' => 'required_without:id|array|max:100', 'ids.*' => 'integer'])) return $r;
        $connections = Connection::where('branch_id', $this->branchId)->whereIn('id', $request->ids ?: [$request->id])->get();
        $results = [];
        foreach ($connections as $connection) {
            $results[$connection->id] = \App\Services\Network\NetworkStatus::verify($connection);
        }
        $bad = collect($results)->whereIn('status', ['mismatch', 'failed'])->count();
        return $this->ok($request->id && ! $request->ids
            ? \App\Services\Network\NetworkStatus::LABELS[$results[$request->id]['status'] ?? 'pending'] ?? 'Checked'
            : count($results) . " checked, {$bad} need attention", ['results' => $results]);
    }

    // Diagnostic terminal on the customer page: a fixed command set run through the
    // router's REST API (see NetworkTerminalService), never a shell.
    public function terminal(Request $request)
    {
        if ($r = $this->deny('connection')) return $r;
        if ($r = $this->validateOrFail($request->all(), ['id' => 'required|integer', 'command' => 'required|string|max:300'])) return $r;
        $connection = Connection::where('branch_id', $this->branchId)->findOrFail($request->id);
        return response()->json(\App\Services\Network\NetworkTerminalService::run($request->command, $connection, [
            'action' => checkAccess('connectionAction'),
            'secret' => checkAccess('connectionSecret'),
            'router' => checkAccess('router'),
        ]));
    }

    private function rules(Request $request): array
    {
        $branchId = $this->branchId;
        $rules = [
            'id' => 'nullable|integer',
            'connection_type' => 'required|in:pppoe,hotspot,static,dhcp',
            'pppoe_username' => ['nullable', 'required_if:connection_type,pppoe,hotspot', 'max:100', Rule::unique('connections')->ignore($request->id)->where('branch_id', $branchId)],
            'pppoe_password' => 'nullable|max:100',
            'static_ip' => ['nullable', 'required_if:connection_type,static', 'ip'],
            'mac_address' => 'nullable|max:32',
            'box_id' => 'nullable|integer|exists:boxes,id',
            'router_id' => 'nullable|integer|exists:routers,id',
            'discount' => 'nullable|numeric|min:0',
            'installation_date' => 'nullable|date',
            'reason' => 'nullable|max:255',
        ];
        if (! $request->id) {
            $rules['customer_id'] = 'required|integer';
            $rules['package_id'] = 'required|integer';
            $rules['area_id'] = 'nullable|integer|exists:areas,id';
            $rules['activation_date'] = 'nullable|date';
        }
        return $rules;
    }
}
