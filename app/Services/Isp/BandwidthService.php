<?php

namespace App\Services\Isp;

use App\Models\BandwidthPurchase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// Bought vs sold bandwidth, and what the bandwidth bill leaves of the billing revenue.
class BandwidthService
{
    // Today: bandwidth bought (running purchases) against bandwidth sold on active connections.
    public static function usage(int $branchId): array
    {
        $today = now()->toDateString();
        $purchases = BandwidthPurchase::where('branch_id', $branchId)->runningOn($today)->orderBy('provider')->get();
        $purchasedMbps = round((float) $purchases->sum('bandwidth_mbps'), 2);
        $cost = round((float) $purchases->sum('monthly_cost'), 2);

        // package price is per billing cycle; per-month revenue divides by the cycle length
        $cycleMonths = "case packages.billing_cycle when 'quarterly' then 3 when 'half_yearly' then 6 when 'yearly' then 12 else 1 end";
        $packages = DB::table('connections')
            ->join('packages', 'packages.id', '=', 'connections.package_id')
            ->where('connections.branch_id', $branchId)
            ->where('connections.status', 'active')
            ->groupBy('packages.id', 'packages.name', 'packages.download_mbps', 'packages.upload_mbps', 'packages.billing_cycle')
            ->orderByDesc(DB::raw('count(*) * coalesce(packages.download_mbps, 0)'))
            ->get([
                'packages.id', 'packages.name', 'packages.download_mbps', 'packages.upload_mbps', 'packages.billing_cycle',
                DB::raw('count(*) as connections'),
                DB::raw('count(*) * coalesce(packages.download_mbps, 0) as sold_mbps'),
                DB::raw("sum(greatest(packages.price - connections.discount, 0) / ({$cycleMonths})) as monthly_revenue"),
            ])
            ->map(fn ($p) => [
                'id' => $p->id, 'name' => $p->name, 'download_mbps' => (float) $p->download_mbps, 'upload_mbps' => (float) $p->upload_mbps,
                'connections' => (int) $p->connections, 'sold_mbps' => round((float) $p->sold_mbps, 2), 'monthly_revenue' => round((float) $p->monthly_revenue, 2),
            ]);

        $soldMbps = round((float) $packages->sum('sold_mbps'), 2);
        $revenue = round((float) $packages->sum('monthly_revenue'), 2);
        return [
            'purchased_mbps' => $purchasedMbps,
            'sold_mbps' => $soldMbps,
            'active_connections' => (int) $packages->sum('connections'),
            'suspended_connections' => DB::table('connections')->where('branch_id', $branchId)->where('status', 'suspended')->count(),
            // sold / bought: above 1 means oversold (normal for shared contention, but watch it)
            'contention' => $purchasedMbps > 0 ? round($soldMbps / $purchasedMbps, 2) : null,
            'monthly_cost' => $cost,
            'monthly_revenue' => $revenue,
            'monthly_profit' => round($revenue - $cost, 2),
            'cost_per_mbps' => $purchasedMbps > 0 ? round($cost / $purchasedMbps, 2) : null,
            'revenue_per_mbps' => $soldMbps > 0 ? round($revenue / $soldMbps, 2) : null,
            'purchases' => $purchases,
            'packages' => $packages->values(),
        ];
    }

    /**
     * Month by month: internet revenue billed (service bills; for a reseller's package only the
     * company's share) against the bandwidth bill (each purchase's monthly cost, prorated by the
     * days it ran in that month).
     */
    public static function profit(int $branchId, Carbon $from, Carbon $to): array
    {
        $from = $from->copy()->startOfMonth();
        $to = $to->copy()->endOfMonth();

        $revenue = DB::table('invoices')
            ->where('branch_id', $branchId)
            ->whereNotNull('service_months')
            ->whereNotIn('status', ['draft', 'void', 'cancelled'])
            ->whereBetween('invoice_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw("date_format(invoice_date, '%Y-%m') as ym, sum(case when reseller_id is not null then reseller_cost else total end) as amount")
            ->groupBy('ym')->pluck('amount', 'ym');

        $purchases = BandwidthPurchase::where('branch_id', $branchId)
            ->where('start_date', '<=', $to->toDateString())
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $from->toDateString()))
            ->get();

        $months = [];
        for ($m = $from->copy(); $m->lte($to); $m->addMonthNoOverflow()) {
            $start = $m->copy()->startOfMonth();
            $end = $m->copy()->endOfMonth()->startOfDay();
            $cost = 0.0;
            $mbps = 0.0;
            foreach ($purchases as $p) {
                $s = $p->start_date->max($start);
                $e = ($p->end_date ?? $end)->min($end);
                if ($s->gt($e)) {
                    continue;
                }
                $days = $s->diffInDays($e) + 1;
                $cost += (float) $p->monthly_cost * $days / $start->daysInMonth;
                if ($p->start_date->lte($end) && (! $p->end_date || $p->end_date->gte($end))) {
                    $mbps += (float) $p->bandwidth_mbps; // bought at month end
                }
            }
            $rev = round((float) ($revenue[$m->format('Y-m')] ?? 0), 2);
            $cost = round($cost, 2);
            $months[] = [
                'month' => $m->format('Y-m'),
                'label' => $m->format('M y'),
                'revenue' => $rev,
                'cost' => $cost,
                'profit' => round($rev - $cost, 2),
                'margin' => $rev > 0 ? round(($rev - $cost) / $rev * 100, 1) : null,
                'purchased_mbps' => round($mbps, 2),
            ];
        }

        $totals = ['revenue' => round(array_sum(array_column($months, 'revenue')), 2), 'cost' => round(array_sum(array_column($months, 'cost')), 2)];
        $totals['profit'] = round($totals['revenue'] - $totals['cost'], 2);
        $totals['margin'] = $totals['revenue'] > 0 ? round($totals['profit'] / $totals['revenue'] * 100, 1) : null;
        return ['months' => $months, 'totals' => $totals];
    }
}
