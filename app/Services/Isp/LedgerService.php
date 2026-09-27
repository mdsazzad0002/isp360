<?php

namespace App\Services\Isp;

use App\Models\Customer;
use App\Models\LedgerEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

// Customer financial ledger. Debit = customer owes more, credit = customer owes less.
// Balance > 0 is due, balance < 0 is advance credit.
//
// Invariant kept by the billing services (checked by `isp:ledger-check`):
//   ledger balance == sum(open invoice due) - sum(unallocated payment money)
class LedgerService
{
    public static function post(int $customerId, int $branchId, string $type, float $debit, float $credit, string $description, ?string $refType = null, ?int $refId = null, $date = null): LedgerEntry
    {
        $debit = round($debit, 2);
        $credit = round($credit, 2);

        $entry = LedgerEntry::create([
            'customer_id' => $customerId,
            'entry_date' => $date ? Carbon::parse($date)->toDateString() : now()->toDateString(),
            'type' => $type,
            'reference_type' => $refType,
            'reference_id' => $refId,
            'description' => mb_substr($description, 0, 255),
            'debit' => $debit,
            'credit' => $credit,
            'branch_id' => $branchId,
            'created_by' => Auth::guard('web')->id(),
            'created_at' => now(),
        ]);

        DB::table('customers')->where('id', $customerId)->update([
            'ledger_balance' => DB::raw('ledger_balance + ' . ($debit - $credit)),
        ]);

        return $entry;
    }

    public static function balance(int $customerId): float
    {
        return round((float) DB::table('customers')->where('id', $customerId)->value('ledger_balance'), 2);
    }

    // Recomputes the cached balance from the ledger (repair tool; should be a no-op).
    public static function rebuild(int $customerId): float
    {
        $sum = (float) LedgerEntry::where('customer_id', $customerId)->sum(DB::raw('debit - credit'));
        DB::table('customers')->where('id', $customerId)->update(['ledger_balance' => round($sum, 2)]);
        return round($sum, 2);
    }

    // Opening balance before $from plus every entry in [from, to] with a running balance.
    public static function statement(int $customerId, ?string $from = null, ?string $to = null): array
    {
        $opening = 0.0;
        if ($from) {
            $opening = (float) LedgerEntry::where('customer_id', $customerId)
                ->where('entry_date', '<', $from)
                ->sum(DB::raw('debit - credit'));
        }

        $rows = LedgerEntry::where('customer_id', $customerId)
            ->when($from, fn ($q) => $q->where('entry_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('entry_date', '<=', $to))
            ->orderBy('entry_date')->orderBy('id')
            ->get();

        $running = $opening;
        $rows = $rows->map(function ($row) use (&$running) {
            $running = round($running + (float) $row->debit - (float) $row->credit, 2);
            $row->balance = $running;
            return $row;
        });

        return [
            'opening' => round($opening, 2),
            'rows' => $rows->values(),
            'total_debit' => round((float) $rows->sum('debit'), 2),
            'total_credit' => round((float) $rows->sum('credit'), 2),
            'closing' => round($running, 2),
        ];
    }
}
