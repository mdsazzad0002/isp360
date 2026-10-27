<?php

namespace App\Http\Controllers\Isp;

use App\Support\Money;
use App\Models\Connection;
use App\Models\Invoice;
use App\Models\Onu;
use App\Services\Isp\AuditLogger;
use App\Services\Isp\BillingService;
use App\Services\Isp\ConnectionService;
use App\Services\Isp\PackageChangeService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ConnectionController extends IspController
{
    public function create()
    {
        return $this->page('connection', 'Isp/Connection', [
            'canAct' => checkAccess('connectionAction'),
            'canSecret' => checkAccess('connectionSecret'),
            'canPay' => checkAccess('ispPayment'),
            'canCredit' => checkAccess('connectionCredit'),
            'canWorkOrder' => checkAccess('workOrder'),
            'canBulkSms' => checkAccess('bulkSms'),
            'canPromise' => checkAccess('promiseToPay'),
        ]);
    }

    public function index(Request $request)
    {
        $query = $this->filtered($request)->with(['customer:id,code,name,phone,area_id,zone_id', 'customer.area:id,name', 'package:id,name,price,download_mbps,network_profile', 'box:id,name,code'])
            // the connection's unpaid service bill, so it can be paid from the list
            ->addSelect(['connections.*',
                'open_invoice_id' => Invoice::select('id')->whereColumn('connection_id', 'connections.id')->whereNotNull('service_months')->whereIn('status', Invoice::OPEN_STATUSES)->limit(1),
                'open_due' => Invoice::selectRaw('coalesce(sum(due), 0)')->whereColumn('connection_id', 'connections.id')->whereIn('status', Invoice::OPEN_STATUSES),
                'on_credit' => Invoice::selectRaw('count(*) > 0')->whereColumn('connection_id', 'connections.id')->whereIn('status', Invoice::OPEN_STATUSES)->whereNotNull('credit_at'),
            ])
            ->latest('id');

        $page = $query->paginate(min(100, (int) ($request->per_page ?: 20)));
        // "active but not really on the router" is what staff need to spot
        $page = $page->toArray() + ['syncCounts' => Connection::where('branch_id', $this->branchId)->where('status', 'active')
            ->groupBy('network_sync_status')->selectRaw('network_sync_status, count(*) as total')->pluck('total', 'network_sync_status')];
        return response()->json($page);
    }

    // The list's filters, shared by the list and its bulk actions ("all matching").
    private function filtered(Request $request)
    {
        return Connection::where('connections.branch_id', $this->branchId)
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
            });
    }

    /**
     * One action on many connections: the ticked ones (ids) or every one matching the list's filters
     * (all_matching). Without `run` it only counts them, for the confirmation. Each connection goes
     * through the same service call as its own button; one failing doesn't stop the others.
     */
    public function bulk(Request $request)
    {
        if ($r = $this->validateOrFail($request->all(), [
            'action' => 'required|in:suspend,reactivate,deactivate,change_package,sync,sms,work_order',
            'ids' => 'required_without:all_matching|array|max:' . self::BULK_MAX,
            'ids.*' => 'integer',
            'reason' => 'required_if:action,suspend,reactivate,deactivate|nullable|max:255',
            'package_id' => 'required_if:action,change_package|nullable|integer',
            'message' => 'required_if:action,sms|nullable|max:640',
            'sms_kind' => 'nullable|in:service,promotion',
            'wo_type' => 'required_if:action,work_order|nullable|in:' . implode(',', array_keys(\App\Models\WorkOrder::TYPES)),
            'assigned_to' => 'nullable|integer|exists:users,id',
            'notes' => 'nullable|max:2000',
        ])) return $r;
        $need = ['sms' => 'bulkSms', 'work_order' => 'workOrder'][$request->action] ?? 'connectionAction';
        if ($r = $this->deny($need)) return $r;

        $query = $request->boolean('all_matching') ? $this->filtered($request) : Connection::where('branch_id', $this->branchId)->whereIn('id', (array) $request->ids);
        $count = (clone $query)->count();
        if ($count > self::BULK_MAX) {
            return send_error("{$count} connections match: narrow the filter to at most " . self::BULK_MAX . ' at a time.', null, 422);
        }
        if (! $request->boolean('run')) {
            return response()->json(['count' => $count, 'sample' => (clone $query)->with('customer:id,name')->limit(8)->get(['id', 'code', 'customer_id'])->map(fn ($c) => "{$c->code} {$c->customer?->name}")]);
        }

        @set_time_limit(600);
        $done = 0;
        $failed = [];
        $smsSkipped = 0;
        foreach ((clone $query)->with(['customer', 'package'])->orderBy('id')->get() as $connection) {
            if ($request->action === 'sms') {
                $this->bulkSms($connection, $request->message, $request->sms_kind ?: 'service') ? $done++ : $smsSkipped++;
                continue;
            }
            try {
                match ($request->action) {
                    'suspend' => ConnectionService::suspend($connection, $request->reason),
                    'reactivate' => ConnectionService::reactivate($connection, $request->reason),
                    'deactivate' => ConnectionService::deactivate($connection, $request->reason),
                    'change_package' => ConnectionService::changePackage($connection, (int) $request->package_id, $request->reason ?: 'Bulk package change', $summary),
                    'sync' => \App\Jobs\SyncConnectionToNetwork::dispatch($connection->id),
                    'work_order' => \App\Services\Isp\WorkOrderService::create(['type' => $request->wo_type, 'connection_id' => $connection->id,
                        'assigned_to' => $request->assigned_to, 'notes' => $request->notes], $this->branchId, $this->userId),
                };
                $done++;
            } catch (\Throwable $th) {
                $failed[] = ['code' => $connection->code, 'error' => $th instanceof \RuntimeException ? $th->getMessage() : 'Something went wrong'];
                if (! $th instanceof \RuntimeException) {
                    \Illuminate\Support\Facades\Log::error($th);
                }
            }
        }
        AuditLogger::log('connection.bulk', null, null, ['action' => $request->action, 'matched' => $count, 'done' => $done, 'failed' => count($failed),
            'package_id' => $request->package_id, 'reason' => $request->reason, 'message' => $request->action === 'sms' ? mb_substr((string) $request->message, 0, 200) : null]);
        $verb = ['suspend' => 'suspended', 'reactivate' => 'reactivated', 'deactivate' => 'deactivated', 'change_package' => 'moved to the new package',
            'sync' => 'queued for the router', 'sms' => 'SMS queued', 'work_order' => 'work orders created'][$request->action];
        return $this->ok("{$done} of {$count} {$verb}" . ($smsSkipped ? ", {$smsSkipped} skipped (no phone, erased or opted out of promotions)" : '') . ($failed ? ', ' . count($failed) . ' failed' : ''), ['failed' => $failed]);
    }

    public const BULK_MAX = 2000;

    // true when queued; false when this customer must not get it
    private function bulkSms(Connection $connection, string $message, string $kind): bool
    {
        $c = $connection->customer;
        if (! $c || ! $c->phone || $c->erased_at || ($kind === 'promotion' && $c->marketing_opt_out)) {
            return false;
        }
        $text = strtr($message, ['{name}' => $c->name, '{code}' => $c->code, '{connection}' => $connection->code, '{package}' => $connection->package?->name ?? '',
            '{balance}' => \App\Support\Money::number(max(0, \App\Services\Isp\LedgerService::balance($c->id)))]);
        \App\Jobs\SendSms::dispatch($this->branchId, $c->phone, $text, $c->id, $kind === 'promotion' ? 'isp_promotion' : 'isp_notice', $this->userId, request()->ip());
        return true;
    }

    public function show(Request $request)
    {
        $connection = Connection::with(['customer:id,code,name,phone,reseller_id', 'package', 'box:id,name,code', 'router', 'histories.createdBy', 'packageHistories.oldPackage', 'packageHistories.newPackage', 'packageHistories.changedBy'])
            ->where('branch_id', $this->branchId)->findOrFail($request->id);
        // the panel hides Activate / Reactivate and offers Pay instead
        return response()->json($connection->toArray() + [
            'needs_payment' => ConnectionService::needsPayment($connection),
            'onu' => Onu::with('olt')->where('connection_id', $connection->id)->first(),
            'promise' => \App\Models\PaymentPromise::where('connection_id', $connection->id)->where('status', 'open')->first(),
            'promises_broken' => \App\Models\PaymentPromise::where('connection_id', $connection->id)->where('status', 'broken')->where('created_at', '>=', now()->subDays(180))->count(),
        ]);
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
                    $request->only(['connection_type', 'pppoe_username', 'pppoe_password', 'static_ip', 'ipv6_prefix', 'mac_address', 'box_id', 'router_id', 'discount', 'installation_date', 'notes', 'reason'])
                );
                return $this->ok('Connection updated successfully', ['id' => $connection->id]);
            }

            // starting a line on due is an admin decision (the reseller portal can't do it)
            if ($request->boolean('on_credit') && ! checkAccess('connectionCredit')) {
                return send_error('You are not allowed to start a connection on due', null, 403);
            }
            $connection = ConnectionService::create($request->all(), $this->branchId);
            try {
                \App\Services\Isp\WorkOrderService::autoInstall($connection, $this->userId); // the installation job for the technicians
            } catch (\Throwable $th) {
                \Illuminate\Support\Facades\Log::warning('Installation work order not created', ['connection' => $connection->id, 'error' => $th->getMessage()]);
            }
            $message = "Connection {$connection->code} created";
            if ($request->boolean('charge_installation') && $connection->package && ((float) $connection->package->installation_fee > 0 || (float) $connection->package->activation_fee > 0)) {
                $items = [];
                foreach (['installation_fee' => 'Installation fee', 'activation_fee' => 'Activation fee'] as $field => $label) {
                    if ((float) $connection->package->{$field} > 0) {
                        $items[] = ['description' => "{$label} — {$connection->code}", 'unit_price' => (float) $connection->package->{$field}, 'quantity' => 1];
                    }
                }
                BillingService::createManual($connection->customer, ['connection_id' => $connection->id], $items);
            }
            $message .= $this->billingSummary($connection);
            return $this->ok($message, ['id' => $connection->id]);
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
    }

    // What the new connection was billed and how much of it the customer's balance paid.
    private function billingSummary(Connection $connection): string
    {
        $invoices = Invoice::where('connection_id', $connection->id)->whereNotIn('status', ['draft', 'void', 'cancelled'])->get(['invoice_no', 'total', 'paid', 'due']);
        if ($invoices->isEmpty()) {
            return '';
        }
        $paid = Money::round((float) $invoices->sum('paid'));
        $due = Money::round((float) $invoices->sum('due'));
        $text = '. Invoice ' . $invoices->pluck('invoice_no')->implode(', ') . ' (' . Money::format($invoices->sum('total')) . ')';
        if ($paid > 0) {
            $text .= ', ' . Money::format($paid) . ' paid from balance';
        }
        return $text . ($due > 0 ? ', ' . Money::format($due) . ' due' : '');
    }

    // Pay panel: cost and paid-until for 1..12 cycles.
    public function payQuote(Request $request)
    {
        if ($r = $this->deny('ispPayment')) return $r;
        $connection = Connection::with('customer:id,code,name,phone', 'package:id,name,price,billing_cycle,download_mbps')
            ->where('branch_id', $this->branchId)->findOrFail($request->id);
        return response()->json(['connection' => $connection] + BillingService::payQuote($connection));
    }

    // Admin only: start the unpaid bill's time now; the customer keeps the due and pays later.
    public function credit(Request $request)
    {
        if ($r = $this->deny('connectionCredit')) return $r;
        if ($r = $this->validateOrFail($request->all(), ['id' => 'required|integer', 'note' => 'nullable|max:255'])) return $r;
        try {
            $connection = Connection::where('branch_id', $this->branchId)->findOrFail($request->id);
            BillingService::grantCredit($connection, $request->note);
            $connection->refresh();
            return $this->ok("Started on due. Runs until {$connection->expire_at?->format('d M Y h:i A')}; the bill stays as the customer's due.");
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
    }

    public function pay(Request $request)
    {
        if ($r = $this->deny('ispPayment')) return $r;
        if ($r = $this->validateOrFail($request->all(), [
            'id' => 'required|integer',
            'cycles' => 'required|integer|min:1|max:12',
            'method' => 'required|in:' . implode(',', \App\Models\CustomerPayment::METHODS),
            'bank_id' => 'required_unless:method,cash|nullable|integer|exists:banks,id',
            'transaction_id' => 'nullable|max:100',
            'notes' => 'nullable|max:500',
        ])) return $r;
        try {
            $connection = Connection::where('branch_id', $this->branchId)->findOrFail($request->id);
            $payment = BillingService::payConnection($connection, (int) $request->cycles,
                $request->only(['method', 'bank_id', 'transaction_id', 'notes']) + ['payment_date' => now()->toDateString()]);
            $connection->refresh();
            $until = $connection->expire_at ? ' Paid until ' . $connection->expire_at->format('d M Y h:i A') . '.' : ' The time starts when the connection is activated.';
            return $this->ok(($payment ? "Payment {$payment->receipt_no} of " . Money::format($payment->amount) . ' received.' : 'Paid from advance credit.') . $until,
                ['id' => $payment?->id]);
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
            'credit_unused' => 'nullable|boolean',
        ])) return $r;

        try {
            $connection = Connection::where('branch_id', $this->branchId)->findOrFail($request->id);
            $credited = null;
            $connection = match ($request->action) {
                'activate' => ConnectionService::activate($connection, $request->date),
                'suspend' => ConnectionService::suspend($connection, $request->reason),
                'reactivate' => ConnectionService::reactivate($connection, $request->reason),
                'deactivate' => ConnectionService::deactivate($connection, $request->reason),
                'terminate' => ConnectionService::terminate($connection, $request->reason,
                    $request->has('credit_unused') ? $request->boolean('credit_unused') : null, $credited),
            };
            return $this->ok("Connection {$connection->code} is now {$connection->status}" . ($request->action === 'activate' ? $this->billingSummary($connection) : '')
                . ($credited ? '. ' . \App\Support\Money::format($credited->amount) . " of unused time credited to the customer ({$credited->receipt_no})" : ''));
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
    }

    // Day-wise preview of a package change: days left, their value on each package, the difference.
    public function packageQuote(Request $request)
    {
        if ($r = $this->deny('connectionAction')) return $r;
        try {
            $connection = Connection::with('package')->where('branch_id', $this->branchId)->findOrFail($request->id);
            $package = ConnectionService::assertCanChangePackage($connection, (int) $request->package_id);
            return response()->json(PackageChangeService::quote($connection, $package));
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
            'reason' => 'nullable|max:255',
        ])) return $r;
        try {
            $connection = Connection::where('branch_id', $this->branchId)->findOrFail($request->id);
            $connection = ConnectionService::changePackage($connection, (int) $request->package_id, $request->reason, $summary);
            return $this->ok("Package changed to {$connection->package->name}. {$summary}");
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
        if ($router->isRadius()) {
            try {
                $session = \App\Services\Network\RadiusDriver::activeSession($connection);
                $reason = $session ? null : $this->radiusOfflineReason($connection);
                return response()->json(['managed' => true, 'radius' => true, 'router' => $router->name, 'router_host' => $router->host, 'online' => (bool) $session, 'session' => $session, 'reason' => $reason]);
            } catch (\Throwable $th) {
                return response()->json(['managed' => true, 'radius' => true, 'router' => $router->name, 'router_host' => $router->host, 'error' => $th->getMessage()]);
            }
        }
        try {
            $api = new \App\Services\Network\MikroTikClient($router);
            $session = \App\Services\Network\MikroTikDriver::activeSession($api, $connection);
            $reason = $session ? null : \App\Services\Network\MikroTikDriver::offlineReason($api, $connection);
            return response()->json(['managed' => true, 'router' => $router->name, 'router_host' => $router->host, 'online' => (bool) $session, 'session' => $session, 'reason' => $reason]);
        } catch (\Throwable $th) {
            return response()->json(['managed' => true, 'router' => $router->name, 'router_host' => $router->host, 'error' => $th->getMessage()]);
        }
    }

    // Why a RADIUS user is offline, from the last login attempt FreeRADIUS logged (radpostauth).
    private function radiusOfflineReason(Connection $connection): ?string
    {
        if ($connection->status !== 'active') {
            return "The connection is {$connection->status}: RADIUS rejects its login.";
        }
        $last = \App\Services\Network\RadiusDriver::db()->table('radpostauth')->where('username', $connection->pppoe_username)->orderByDesc('id')->first();
        if (! $last) {
            return 'No login attempt reached RADIUS yet (router off, or not pointing at this RADIUS server).';
        }
        return str_contains(strtolower($last->reply), 'reject')
            ? "Last login {$last->authdate} was rejected (wrong password, or the MAC lock)."
            : "Last login {$last->authdate} was accepted; no session is open now.";
    }

    // RADIUS session history: start/stop, IP, MAC and data used per session.
    public function sessions(Request $request)
    {
        if ($r = $this->deny('connection')) return $r;
        $connection = Connection::where('branch_id', $this->branchId)->findOrFail($request->id);
        $router = \App\Models\Router::forConnection($connection);
        if (! $router?->isRadius() || ! $connection->pppoe_username) {
            return response()->json(['radius' => false, 'sessions' => []]);
        }
        try {
            return response()->json(['radius' => true, 'sessions' => \App\Services\Network\RadiusDriver::sessions($connection, min(200, (int) ($request->limit ?: 50)))]);
        } catch (\Throwable $th) {
            return send_error($th->getMessage(), null, 422);
        }
    }

    // One live traffic reading for the connection panel's graph (polled every few seconds while it is open).
    public function traffic(Request $request)
    {
        if ($r = $this->deny('connection')) return $r;
        $connection = Connection::where('branch_id', $this->branchId)->findOrFail($request->id);
        $router = \App\Models\Router::forConnection($connection);
        // live graphs read the MikroTik interface; a RADIUS NAS only reports totals (see sessions)
        if (! $router || $router->isRadius() || ! $connection->pppoe_username || ! isset(\App\Services\Network\MikroTikDriver::SERVICES[$connection->connection_type])) {
            return response()->json(['managed' => false]);
        }
        try {
            $sample = \App\Services\Network\MikroTikDriver::trafficSample(new \App\Services\Network\MikroTikClient($router), $connection);
            return response()->json(['managed' => true, 'online' => (bool) $sample, 'sample' => $sample, 'at' => microtime(true)]);
        } catch (\Throwable $th) {
            return response()->json(['managed' => true, 'error' => $th->getMessage()]);
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
    // router's REST API, plus a few server-side probes (see NetworkTerminalService), never a shell.
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
            // one live connection per address in a branch (IPAM)
            'static_ip' => ['nullable', 'required_if:connection_type,static', 'ip', function ($attr, $value, $fail) use ($request, $branchId) {
                try {
                    \App\Services\Isp\IpamService::assertStaticFree($branchId, $value, $request->id ? (int) $request->id : null);
                } catch (\RuntimeException $e) {
                    $fail($e->getMessage());
                }
            }],
            'ipv6_prefix' => ['nullable', 'max:64', 'regex:/^[0-9a-fA-F:]+\/\d{1,3}$/'],
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
            $rules['bonus_days'] = 'nullable|integer|min:0|max:365';
            $rules['referred_by_id'] = 'nullable|integer|different:customer_id';
        }
        return $rules;
    }
}
