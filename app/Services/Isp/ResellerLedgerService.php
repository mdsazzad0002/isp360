<?php

namespace App\Services\Isp;

use App\Models\CustomerPayment;
use App\Models\PaymentAllocation;
use App\Models\ResellerTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

// Reseller statement, built from the same source rows as ResellerWalletService::summary(),
// so the closing balance always equals the wallet balance. Balance > 0: the company owes
// the reseller. Credit raises it, debit lowers it.
//
//  credit  margin earned when a customer payment is allocated to a reseller-package invoice
//          (allocation amount x (1 - reseller_cost / invoice total)); a reversed allocation
//          shows again as a debit on the day it was reversed
//  debit   cash the reseller collected from a customer, or a customer bill paid from the wallet
//          (and a credit if that payment is reversed)
//  credit  cash the reseller deposited with the company
//  debit   withdrawal paid to the reseller
class ResellerLedgerService
{
    public static function statement(int $resellerId, ?string $from = null, ?string $to = null): array
    {
        $rows = self::entries($resellerId)->sortBy([['date', 'asc'], ['sort', 'asc']])->values();

        $from = $from ? Carbon::parse($from)->toDateString() : null;
        $to = $to ? Carbon::parse($to)->toDateString() : null;

        $opening = 0.0;
        $balance = 0.0;
        $out = [];
        $credit = 0.0;
        $debit = 0.0;
        foreach ($rows as $row) {
            if ($from && $row['date'] < $from) {
                $opening += $row['credit'] - $row['debit'];
                $balance = $opening;
                continue;
            }
            if ($to && $row['date'] > $to) {
                continue;
            }
            $balance += $row['credit'] - $row['debit'];
            $credit += $row['credit'];
            $debit += $row['debit'];
            $out[] = $row + ['balance' => round($balance, 2)];
        }

        return [
            'opening' => round($opening, 2),
            'credit' => round($credit, 2),
            'debit' => round($debit, 2),
            'closing' => round($opening + $credit - $debit, 2),
            'rows' => array_map(fn ($r) => array_diff_key($r, ['sort' => 1]), $out),
        ];
    }

    private static function entries(int $resellerId): Collection
    {
        $rows = collect();

        $allocations = PaymentAllocation::query()
            ->join('invoices', 'invoices.id', '=', 'payment_allocations.invoice_id')
            ->join('customer_payments', 'customer_payments.id', '=', 'payment_allocations.customer_payment_id')
            ->leftJoin('customers', 'customers.id', '=', 'invoices.customer_id')
            ->where('invoices.reseller_id', $resellerId)
            ->whereNotIn('invoices.status', ['draft', 'void', 'cancelled'])
            ->where('invoices.total', '>', 0)
            ->get([
                'payment_allocations.id', 'payment_allocations.amount', 'payment_allocations.status', 'payment_allocations.created_at', 'payment_allocations.reversed_at',
                'invoices.invoice_no', 'invoices.total', 'invoices.reseller_cost', 'customer_payments.receipt_no', 'customers.name as customer_name',
            ]);
        foreach ($allocations as $a) {
            $margin = round((float) $a->amount * (1 - (float) $a->reseller_cost / (float) $a->total), 2);
            $rows->push(self::row($a->created_at, 1, 'earning', "Earning on {$a->invoice_no} ({$a->customer_name}) · receipt {$a->receipt_no}", $a->invoice_no, $margin, 0));
            if ($a->status === 'reversed' && $a->reversed_at) {
                $rows->push(self::row($a->reversed_at, 2, 'earning_reversed', "Payment taken off {$a->invoice_no} ({$a->customer_name})", $a->invoice_no, 0, $margin));
            }
        }

        $collections = CustomerPayment::with('customer:id,name')
            ->where('collected_by_reseller_id', $resellerId)
            ->whereNotIn('status', ['failed', 'pending'])
            ->get(['id', 'receipt_no', 'customer_id', 'payment_date', 'created_at', 'amount', 'method', 'status', 'reversed_at']);
        foreach ($collections as $p) {
            $when = Carbon::parse($p->payment_date->toDateString() . ' ' . $p->created_at->format('H:i:s'));
            $rows->push($p->method === 'wallet'
                ? self::row($when, 0, 'wallet_payment', "Paid {$p->customer?->name}'s bill from wallet · {$p->receipt_no}", $p->receipt_no, 0, (float) $p->amount)
                : self::row($when, 0, 'collection', "Collected from {$p->customer?->name} ({$p->method}) · {$p->receipt_no}", $p->receipt_no, 0, (float) $p->amount));
            if ($p->status === 'reversed' && $p->reversed_at) {
                $rows->push(self::row($p->reversed_at, 2, 'collection_reversed', "Collection {$p->receipt_no} reversed", $p->receipt_no, (float) $p->amount, 0));
            }
        }

        $settlements = ResellerTransaction::where('reseller_id', $resellerId)->where('status', 'paid')
            ->get(['ref_no', 'type', 'amount', 'method', 'processed_at', 'created_at']);
        foreach ($settlements as $t) {
            $rows->push($t->type === 'deposit'
                ? self::row($t->processed_at ?? $t->created_at, 3, 'deposit', "Cash deposited to company ({$t->method})", $t->ref_no, (float) $t->amount, 0)
                : self::row($t->processed_at ?? $t->created_at, 3, 'withdrawal', "Withdrawal paid ({$t->method})", $t->ref_no, 0, (float) $t->amount));
        }

        return $rows;
    }

    private static function row($when, int $sort, string $type, string $description, ?string $ref, float $credit, float $debit): array
    {
        $when = Carbon::parse($when);
        return [
            'date' => $when->toDateString(),
            'time' => $when->format('H:i'),
            'sort' => $when->format('His') . $sort,
            'type' => $type,
            'description' => $description,
            'ref' => $ref,
            'credit' => round($credit, 2),
            'debit' => round($debit, 2),
        ];
    }
}
