<?php

namespace App\Services\Isp;

use App\Support\Money;
use Illuminate\Support\Facades\DB;

// Output tax for a period, company-wide (the company files one return), split by branch:
//   + tax on invoices issued in the period, by rate (a later void doesn't rewrite the period)
//   - tax on invoices voided in the period
//   + tax on debit notes / - tax on credit notes raised in the period
//   - tax given back with package-downgrade credits in the period
// Drafts and cancelled drafts never count.
class TaxReportService
{
    public static function report(string $from, string $to): array
    {
        $branches = DB::table('branches')->pluck('name', 'id');

        // by rate, from each line's snapshot
        $rates = [];
        $byBranch = [];
        DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->whereNotIn('invoices.status', ['draft', 'cancelled'])
            ->whereBetween('invoices.invoice_date', [$from, $to])
            ->whereNotNull('invoice_items.taxes')
            ->orderBy('invoice_items.id')
            ->select('invoice_items.id', 'invoice_items.taxes', 'invoices.branch_id')
            ->chunk(1000, function ($items) use (&$rates, &$byBranch) {
                foreach ($items as $item) {
                    foreach (json_decode($item->taxes, true) ?: [] as $t) {
                        $key = $t['name'] . '|' . (float) $t['rate'];
                        $rates[$key] ??= ['name' => $t['name'], 'rate' => (float) $t['rate'], 'taxable' => 0.0, 'tax' => 0.0];
                        $rates[$key]['taxable'] += (float) $t['taxable'];
                        $rates[$key]['tax'] += (float) $t['amount'];
                        $byBranch[$item->branch_id]['sales'] = ($byBranch[$item->branch_id]['sales'] ?? 0) + (float) $t['amount'];
                    }
                }
            });

        $voided = DB::table('invoices')->where('status', 'void')->where('tax_total', '!=', 0)
            ->whereBetween('voided_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->groupBy('branch_id')->selectRaw('branch_id, sum(tax_total) as tax')->pluck('tax', 'branch_id');

        $notes = DB::table('billing_notes')->where('tax_amount', '!=', 0)
            ->whereBetween('note_date', [$from, $to])
            ->groupBy('branch_id', 'type')->selectRaw('branch_id, type, sum(tax_amount) as tax')->get();

        $credits = DB::table('customer_payments')->where('method', 'adjustment')->where('tax_amount', '>', 0)
            ->where('status', '!=', 'reversed')->whereBetween('payment_date', [$from, $to])
            ->groupBy('branch_id')->selectRaw('branch_id, sum(tax_amount) as tax')->pluck('tax', 'branch_id');

        foreach ($voided as $branchId => $tax) {
            $byBranch[$branchId]['voided'] = (float) $tax;
        }
        foreach ($notes as $n) {
            $byBranch[$n->branch_id][$n->type . '_notes'] = (float) $n->tax;
        }
        foreach ($credits as $branchId => $tax) {
            $byBranch[$branchId]['credits'] = (float) $tax;
        }

        $rows = [];
        foreach ($byBranch as $branchId => $b) {
            $row = [
                'branch' => $branches[$branchId] ?? "#{$branchId}",
                'sales' => Money::round($b['sales'] ?? 0),
                'voided' => Money::round($b['voided'] ?? 0),
                'debit_notes' => Money::round($b['debit_notes'] ?? 0),
                'credit_notes' => Money::round($b['credit_notes'] ?? 0),
                'credits' => Money::round($b['credits'] ?? 0),
            ];
            $row['net'] = Money::round($row['sales'] - $row['voided'] + $row['debit_notes'] - $row['credit_notes'] - $row['credits']);
            $rows[] = $row;
        }
        usort($rows, fn ($a, $b) => strcmp($a['branch'], $b['branch']));

        $totals = ['sales' => 0.0, 'voided' => 0.0, 'debit_notes' => 0.0, 'credit_notes' => 0.0, 'credits' => 0.0, 'net' => 0.0];
        foreach ($rows as $row) {
            foreach ($totals as $k => $v) {
                $totals[$k] = Money::round($v + $row[$k]);
            }
        }

        return [
            'from' => $from,
            'to' => $to,
            'label' => TaxService::label(),
            'tax_number' => company()?->tax_number,
            'rates' => array_values(array_map(fn ($r) => ['name' => $r['name'], 'rate' => $r['rate'], 'taxable' => Money::round($r['taxable']), 'tax' => Money::round($r['tax'])], $rates)),
            'branches' => $rows,
            'totals' => $totals,
        ];
    }
}
