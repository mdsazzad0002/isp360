<?php

namespace App\Http\Controllers\Isp;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReportController extends IspController
{
    public function dashboard(Request $request)
    {
        if ($r = $this->deny('ispReport')) return $r;
        $b = $this->branchId;
        $today = now()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();
        $collected = ['completed', 'partially_refunded', 'refunded'];

        $customers = DB::table('customers')->where('branch_id', $b)->whereNull('deleted_at')
            ->selectRaw("count(*) as total, sum(account_status = 'active') as active, sum(account_status = 'inactive') as inactive, sum(created_at >= ?) as new_this_month", [$monthStart])
            ->first();
        $connections = DB::table('connections')->where('branch_id', $b)->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');

        $payments = DB::table('customer_payments')->where('branch_id', $b)->whereIn('status', $collected);
        $todayCollection = (clone $payments)->where('payment_date', $today)->sum(DB::raw('amount - refunded_amount'));
        $monthCollection = (clone $payments)->where('payment_date', '>=', $monthStart)->sum(DB::raw('amount - refunded_amount'));

        $outstanding = DB::table('customers')->where('branch_id', $b)->where('ledger_balance', '>', 0)->sum('ledger_balance');
        $advance = DB::table('customers')->where('branch_id', $b)->where('ledger_balance', '<', 0)->sum('ledger_balance');
        $overdue = DB::table('invoices')->where('branch_id', $b)->where('status', 'overdue')->sum('due');
        $monthBilled = DB::table('invoices')->where('branch_id', $b)->whereNotIn('status', ['void', 'cancelled', 'draft'])->where('invoice_date', '>=', $monthStart)->sum('total');

        $boxes = DB::table('boxes')->where('branch_id', $b)->whereNull('deleted_at')->selectRaw('count(*) as boxes, coalesce(sum(capacity),0) as capacity')->first();
        $usedPorts = DB::table('connections')->where('branch_id', $b)->whereNotNull('box_id')->where('status', '!=', 'terminated')->count();

        // last 12 months billed vs collected
        $from = now()->subMonths(11)->startOfMonth();
        $billed = DB::table('invoices')->where('branch_id', $b)->whereNotIn('status', ['void', 'cancelled', 'draft'])->where('invoice_date', '>=', $from)
            ->selectRaw("date_format(invoice_date, '%Y-%m') as m, sum(total) as v")->groupBy('m')->pluck('v', 'm');
        $paid = DB::table('customer_payments')->where('branch_id', $b)->whereIn('status', $collected)->where('payment_date', '>=', $from)
            ->selectRaw("date_format(payment_date, '%Y-%m') as m, sum(amount - refunded_amount) as v")->groupBy('m')->pluck('v', 'm');
        $newCustomers = DB::table('customers')->where('branch_id', $b)->whereNull('deleted_at')->where('created_at', '>=', $from)
            ->selectRaw("date_format(created_at, '%Y-%m') as m, count(*) as v")->groupBy('m')->pluck('v', 'm');
        $months = [];
        for ($d = $from->copy(); $d->lte(now()); $d->addMonthNoOverflow()) {
            $k = $d->format('Y-m');
            $months[] = ['month' => $d->format('M y'), 'billed' => round((float) ($billed[$k] ?? 0), 2), 'collected' => round((float) ($paid[$k] ?? 0), 2), 'new_customers' => (int) ($newCustomers[$k] ?? 0)];
        }

        $packageWise = DB::table('connections')->join('packages', 'packages.id', '=', 'connections.package_id')
            ->where('connections.branch_id', $b)->whereIn('connections.status', ['active', 'suspended'])
            ->selectRaw('packages.name as label, count(*) as value')->groupBy('packages.name')->orderByDesc('value')->limit(8)->get();
        $areaWise = DB::table('customers')->leftJoin('areas', 'areas.id', '=', 'customers.area_id')
            ->where('customers.branch_id', $b)->whereNull('customers.deleted_at')
            ->selectRaw("coalesce(areas.name, 'No area') as label, count(*) as value, sum(greatest(customers.ledger_balance, 0)) as due")
            ->groupBy('label')->orderByDesc('value')->limit(8)->get();
        $methodWise = DB::table('customer_payments')->where('branch_id', $b)->whereIn('status', $collected)->where('payment_date', '>=', $monthStart)
            ->selectRaw('method as label, sum(amount - refunded_amount) as value')->groupBy('method')->orderByDesc('value')->get();

        return response()->json([
            'customers' => $customers,
            'connections' => $connections,
            'new_connections' => DB::table('connections')->where('branch_id', $b)->where('created_at', '>=', $monthStart)->count(),
            'today_collection' => round((float) $todayCollection, 2),
            'month_collection' => round((float) $monthCollection, 2),
            'month_billed' => round((float) $monthBilled, 2),
            'outstanding' => round((float) $outstanding, 2),
            'advance' => round(abs((float) $advance), 2),
            'overdue' => round((float) $overdue, 2),
            'boxes' => ['count' => (int) $boxes->boxes, 'capacity' => (int) $boxes->capacity, 'used' => $usedPorts],
            'active_packages' => DB::table('packages')->where('branch_id', $b)->where('is_active', true)->whereNull('deleted_at')->count(),
            'months' => $months,
            'package_wise' => $packageWise,
            'area_wise' => $areaWise,
            'method_wise' => $methodWise,
        ]);
    }

    public function dueReport()
    {
        return $this->page('ispReport', 'Isp/DueReport');
    }

    // Customer-wise due from the ledger, with zone / area / box filters and the overdue part.
    public function getDueReport(Request $request)
    {
        if ($r = $this->deny('ispReport')) return $r;
        $b = $this->branchId;
        $mode = in_array($request->mode, ['due', 'advance', 'overdue', 'all'], true) ? $request->mode : 'due';

        $overdueSub = DB::table('invoices')->selectRaw('customer_id, sum(due) as overdue, min(due_date) as oldest_due_date')
            ->where('status', 'overdue')->groupBy('customer_id');
        $query = Customer::query()
            ->leftJoinSub($overdueSub, 'od', 'od.customer_id', '=', 'customers.id')
            ->leftJoin('areas', 'areas.id', '=', 'customers.area_id')
            ->leftJoin('zones', 'zones.id', '=', 'customers.zone_id')
            ->leftJoin('boxes', 'boxes.id', '=', 'customers.box_id')
            ->where('customers.branch_id', $b)
            ->when($mode === 'due', fn ($q) => $q->where('customers.ledger_balance', '>', 0))
            ->when($mode === 'advance', fn ($q) => $q->where('customers.ledger_balance', '<', 0))
            ->when($mode === 'overdue', fn ($q) => $q->where('od.overdue', '>', 0))
            ->when($request->zoneId, fn ($q, $id) => $q->where('customers.zone_id', $id))
            ->when($request->areaId, fn ($q, $id) => $q->where('customers.area_id', $id))
            ->when($request->boxId, fn ($q, $id) => $q->where('customers.box_id', $id))
            ->when($request->search, fn ($q, $t) => $q->where(fn ($w) => $w->where('customers.name', 'like', "%{$t}%")->orWhere('customers.phone', 'like', "%{$t}%")->orWhere('customers.code', 'like', "%{$t}%")));

        $totals = (clone $query)->selectRaw('count(*) as customers, coalesce(sum(greatest(customers.ledger_balance,0)),0) as due, coalesce(sum(least(customers.ledger_balance,0)),0) as advance, coalesce(sum(od.overdue),0) as overdue')->first();

        $sortable = ['name' => 'customers.name', 'code' => 'customers.code', 'balance' => 'customers.ledger_balance', 'overdue' => 'od.overdue', 'oldest' => 'od.oldest_due_date', 'area' => 'areas.name'];
        $sort = $sortable[$request->sortBy] ?? 'customers.ledger_balance';
        $dir = $request->sortDir === 'asc' ? 'asc' : 'desc';

        $query->select('customers.id', 'customers.code', 'customers.name', 'customers.phone', 'customers.address', 'customers.ledger_balance as balance',
            DB::raw('coalesce(od.overdue, 0) as overdue'), 'od.oldest_due_date', 'areas.name as area_name', 'zones.name as zone_name', 'boxes.name as box_name',
            DB::raw("(select group_concat(distinct c.status) from connections c where c.customer_id = customers.id and c.status <> 'terminated') as connection_status"))
            ->orderBy($sort, $dir)->orderBy('customers.id');

        $perPage = $request->all_rows ? 100000 : min(200, (int) ($request->per_page ?: 25));
        return response()->json(['page' => $query->paginate($perPage), 'totals' => $totals]);
    }

    // Due summary grouped by zone, area or box.
    public function dueSummary(Request $request)
    {
        if ($r = $this->deny('ispReport')) return $r;
        $group = in_array($request->group, ['zone', 'area', 'box'], true) ? $request->group : 'area';
        $table = ['zone' => 'zones', 'area' => 'areas', 'box' => 'boxes'][$group];
        $rows = DB::table('customers')
            ->leftJoin($table, "{$table}.id", '=', "customers.{$group}_id")
            ->leftJoinSub(DB::table('invoices')->selectRaw('customer_id, sum(due) as overdue')->where('status', 'overdue')->groupBy('customer_id'), 'od', 'od.customer_id', '=', 'customers.id')
            ->where('customers.branch_id', $this->branchId)->whereNull('customers.deleted_at')
            ->selectRaw("coalesce({$table}.name, '— Not set —') as name, count(*) as customers, sum(customers.ledger_balance > 0) as due_customers, coalesce(sum(greatest(customers.ledger_balance,0)),0) as due, coalesce(sum(od.overdue),0) as overdue")
            ->groupBy('name')->orderByDesc('due')->get();
        return response()->json($rows);
    }

    public function collectionReport()
    {
        return $this->page('ispReport', 'Isp/CollectionReport');
    }

    public function getCollectionReport(Request $request)
    {
        if ($r = $this->deny('ispReport')) return $r;
        $from = sqlDate($request->dateFrom) ?? now()->startOfMonth()->toDateString();
        $to = sqlDate($request->dateTo) ?? now()->toDateString();
        $base = DB::table('customer_payments as p')->where('p.branch_id', $this->branchId)
            ->whereIn('p.status', ['completed', 'partially_refunded', 'refunded'])
            ->whereBetween('p.payment_date', [$from, $to]);

        $net = 'sum(p.amount - p.refunded_amount)';
        return response()->json([
            'from' => $from,
            'to' => $to,
            'total' => round((float) (clone $base)->sum(DB::raw('p.amount - p.refunded_amount')), 2),
            'count' => (clone $base)->count(),
            'reversed' => round((float) DB::table('customer_payments')->where('branch_id', $this->branchId)->where('status', 'reversed')->whereBetween('payment_date', [$from, $to])->sum('amount'), 2),
            'by_day' => (clone $base)->selectRaw("p.payment_date as label, count(*) as count, {$net} as amount")->groupBy('p.payment_date')->orderBy('p.payment_date')->get(),
            'by_method' => (clone $base)->selectRaw("p.method as label, count(*) as count, {$net} as amount")->groupBy('p.method')->orderByDesc('amount')->get(),
            'by_collector' => (clone $base)->leftJoin('users as u', 'u.id', '=', 'p.received_by')
                ->selectRaw("coalesce(u.name, 'System / Online') as label, count(*) as count, {$net} as amount")->groupBy('label')->orderByDesc('amount')->get(),
            'by_area' => (clone $base)->join('customers as c', 'c.id', '=', 'p.customer_id')->leftJoin('areas as a', 'a.id', '=', 'c.area_id')
                ->selectRaw("coalesce(a.name, '— Not set —') as label, count(*) as count, {$net} as amount")->groupBy('label')->orderByDesc('amount')->get(),
        ]);
    }
}
