<?php

namespace App\Services\Isp;

use App\Models\Branch;
use App\Models\Region;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// Owner / regional view across branches (roadmap 3.1, 3.2): subscribers, churn, billing, collection,
// due and profit per branch, with region and company totals. One currency per installation, so
// figures add up without conversion.
class CompanyReport
{
    private const COLLECTED = ['completed', 'partially_refunded', 'refunded'];
    private const SUMMED = ['subscribers', 'suspended', 'new', 'churned', 'billed', 'revenue', 'tax', 'collection', 'other_income',
        'expense', 'bandwidth_cost', 'profit', 'due', 'advance', 'overdue'];

    /**
     * @param  array<int>|null  $branchIds  null = every branch
     * @return array{branches: array, regions: array, total: array, from: string, to: string}
     */
    public static function build(Carbon $from, Carbon $to, ?array $branchIds = null, ?int $regionId = null): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();
        $branches = Branch::query()
            ->when($branchIds !== null, fn ($q) => $q->whereIn('id', $branchIds ?: [0]))
            ->when($regionId, fn ($q) => $q->where('region_id', $regionId))
            ->orderBy('name')->get(['id', 'name', 'region_id']);
        $ids = $branches->pluck('id')->all() ?: [0];
        $d1 = $from->toDateString();
        $d2 = $to->toDateString();

        $sum = fn ($query, string $expr) => $query->whereIn('branch_id', $ids)->groupBy('branch_id')->selectRaw("branch_id, {$expr} as v")->pluck('v', 'branch_id');

        $subscribers = $sum(DB::table('connections')->where('status', 'active'), 'count(*)');
        $suspended = $sum(DB::table('connections')->where('status', 'suspended'), 'count(*)');
        $new = $sum(DB::table('connections')->whereBetween('created_at', [$from, $to]), 'count(*)');
        $churned = $sum(DB::table('connections')->where('status', 'terminated')->whereBetween('terminated_at', [$from, $to]), 'count(*)');
        $invoices = DB::table('invoices')->whereNotIn('status', ['void', 'cancelled', 'draft'])->whereBetween('invoice_date', [$d1, $d2]);
        $billed = $sum(clone $invoices, 'sum(total)');
        $tax = $sum(clone $invoices, 'sum(tax)');
        $collection = $sum(DB::table('customer_payments')->whereIn('status', self::COLLECTED)->whereBetween('payment_date', [$d1, $d2]), 'sum(amount - refunded_amount)');
        $txn = fn (string $type) => $sum(DB::table('transactions')->where('type', $type)->where('status', 'a')->whereNull('deleted_at')->whereBetween('date', [$d1, $d2]), 'sum(amount)');
        $otherIncome = $txn('income');
        $expense = $txn('expense');
        $due = $sum(DB::table('customers')->whereNull('deleted_at')->where('ledger_balance', '>', 0), 'sum(ledger_balance)');
        $advance = $sum(DB::table('customers')->whereNull('deleted_at')->where('ledger_balance', '<', 0), '-sum(ledger_balance)');
        $overdue = $sum(DB::table('invoices')->where('status', 'overdue'), 'sum(due)');
        $bandwidth = self::bandwidthCost($ids, $from, $to);
        $regions = Region::whereIn('id', $branches->pluck('region_id')->filter()->unique())->pluck('name', 'id');

        $rows = $branches->map(function ($b) use ($subscribers, $suspended, $new, $churned, $billed, $tax, $collection, $otherIncome, $expense, $due, $advance, $overdue, $bandwidth, $regions) {
            $f = fn ($c) => (float) ($c[$b->id] ?? 0);
            $revenue = $f($billed) - $f($tax); // billed net of tax collected for the state
            $row = [
                'branch_id' => $b->id, 'branch' => $b->name, 'region_id' => $b->region_id, 'region' => $regions[$b->region_id] ?? null,
                'subscribers' => (int) $f($subscribers), 'suspended' => (int) $f($suspended), 'new' => (int) $f($new), 'churned' => (int) $f($churned),
                'billed' => $f($billed), 'tax' => $f($tax), 'revenue' => $revenue, 'collection' => $f($collection), 'other_income' => $f($otherIncome),
                'expense' => $f($expense), 'bandwidth_cost' => $bandwidth[$b->id] ?? 0.0,
                'due' => $f($due), 'advance' => $f($advance), 'overdue' => $f($overdue),
            ];
            $row['profit'] = $revenue + $row['other_income'] - $row['expense'] - $row['bandwidth_cost'];
            return self::finish($row);
        });

        $regionRows = $rows->groupBy(fn ($r) => $r['region_id'] ?? 0)->map(fn (Collection $g, $id) => self::finish(
            ['region_id' => $id ?: null, 'region' => $g->first()['region'] ?? null, 'branches' => $g->count()] + self::totals($g)
        ))->sortBy(fn ($r) => $r['region'] === null ? "\u{FFFF}" : $r['region'])->values();

        return [
            'from' => $d1, 'to' => $d2,
            'branches' => $rows->values()->all(),
            'regions' => $regionRows->all(),
            'total' => self::finish(['branches' => $rows->count()] + self::totals($rows)),
        ];
    }

    // Upstream bandwidth cost falling in the window: each purchase's monthly cost spread per day.
    public static function bandwidthCost(array $branchIds, Carbon $from, Carbon $to): array
    {
        $cost = [];
        $rows = DB::table('bandwidth_purchases')->whereIn('branch_id', $branchIds)->where('start_date', '<=', $to->toDateString())
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $from->toDateString()))->get();
        foreach ($rows as $p) {
            $start = max(Carbon::parse($p->start_date)->startOfDay(), $from->copy()->startOfDay());
            $end = min($p->end_date ? Carbon::parse($p->end_date)->startOfDay() : $to->copy()->startOfDay(), $to->copy()->startOfDay());
            $days = (int) $start->diffInDays($end) + 1;
            if ($days > 0) {
                $cost[$p->branch_id] = ($cost[$p->branch_id] ?? 0) + (float) $p->monthly_cost * 12 / 365 * $days;
            }
        }
        return $cost;
    }

    private static function totals(Collection $rows): array
    {
        return collect(self::SUMMED)->mapWithKeys(fn ($k) => [$k => $rows->sum($k)])->all();
    }

    // Ratios and money rounding. ARPU = revenue per active subscriber; churn = lines terminated in
    // the window against the base they came from (active now + terminated).
    private static function finish(array $row): array
    {
        foreach (['billed', 'tax', 'revenue', 'collection', 'other_income', 'expense', 'bandwidth_cost', 'profit', 'due', 'advance', 'overdue'] as $k) {
            $row[$k] = Money::round((float) $row[$k]);
        }
        $row['arpu'] = $row['subscribers'] > 0 ? Money::round($row['revenue'] / $row['subscribers']) : 0.0;
        $base = $row['subscribers'] + $row['suspended'] + $row['churned'];
        $row['churn_pct'] = $base > 0 ? round($row['churned'] * 100 / $base, 2) : 0.0;
        $row['collection_pct'] = $row['billed'] > 0 ? round($row['collection'] * 100 / $row['billed'], 1) : null;
        return $row;
    }
}
