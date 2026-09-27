<?php

namespace App\Services\Isp;

use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\Reseller;
use App\Models\ResellerTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

// What the company and a reseller owe each other.
//
//  earned     = the reseller's margin on paid invoices of their packages, recognised as the
//               customer pays: paid - reseller_cost * paid / total (reversals reduce it again)
//  collected  = cash the reseller took from customers in the reseller portal (still in their hand)
//  balance    = earned - collected + deposits - paid withdrawals
//               > 0 the company owes the reseller, < 0 the reseller owes the company
//  available  = balance - pending withdrawal requests
//
// Nothing here is stored as a running total; every figure comes from the source rows.
class ResellerWalletService
{
    public static function summary(int $resellerId): array
    {
        // Per allocation, rounded the same way as ResellerLedgerService, so statement == wallet.
        $earned = (float) DB::table('payment_allocations')
            ->join('invoices', 'invoices.id', '=', 'payment_allocations.invoice_id')
            ->where('invoices.reseller_id', $resellerId)
            ->where('payment_allocations.status', 'active')
            ->whereNotIn('invoices.status', ['draft', 'void', 'cancelled'])
            ->where('invoices.total', '>', 0)
            ->sum(DB::raw('round(payment_allocations.amount * (1 - invoices.reseller_cost / invoices.total), 2)'));
        $expected = (float) Invoice::where('reseller_id', $resellerId)
            ->whereNotIn('status', ['draft', 'void', 'cancelled'])
            ->sum(DB::raw('total - reseller_cost'));
        $collected = (float) CustomerPayment::where('collected_by_reseller_id', $resellerId)
            ->whereNotIn('status', ['reversed', 'failed', 'pending'])
            ->sum('amount');

        $tx = ResellerTransaction::where('reseller_id', $resellerId)
            ->selectRaw("
                coalesce(sum(case when type = 'deposit' and status = 'paid' then amount end), 0) as deposits,
                coalesce(sum(case when type = 'withdrawal' and status = 'paid' then amount end), 0) as withdrawn,
                coalesce(sum(case when type = 'withdrawal' and status = 'pending' then amount end), 0) as pending
            ")->first();

        $balance = round($earned - $collected + (float) $tx->deposits - (float) $tx->withdrawn, 2);
        return [
            'earned' => round($earned, 2),
            'expected' => round($expected, 2),
            'collected' => round($collected, 2),
            'deposits' => round((float) $tx->deposits, 2),
            'withdrawn' => round((float) $tx->withdrawn, 2),
            'pending' => round((float) $tx->pending, 2),
            'balance' => $balance,
            'available' => round(max(0, $balance - (float) $tx->pending), 2),
        ];
    }

    public static function requestWithdrawal(Reseller $reseller, array $data): ResellerTransaction
    {
        return DB::transaction(function () use ($reseller, $data) {
            // One request at a time per reseller, so two tabs can't both spend the same balance.
            Reseller::whereKey($reseller->id)->lockForUpdate()->first();
            $amount = round((float) $data['amount'], 2);
            if ($amount <= 0) {
                throw new RuntimeException('Amount must be greater than zero.');
            }
            $available = self::summary($reseller->id)['available'];
            if ($amount > $available + 0.001) {
                throw new RuntimeException('Only ' . number_format($available, 2) . ' is available to withdraw.');
            }

            $tx = ResellerTransaction::create([
                'ref_no' => SequenceService::next($reseller->branch_id, 'reseller_withdrawal', 'WD'),
                'reseller_id' => $reseller->id,
                'type' => 'withdrawal',
                'amount' => $amount,
                'status' => 'pending',
                'method' => $data['method'] ?? 'cash',
                'account_details' => $data['account_details'] ?? null,
                'reseller_note' => $data['note'] ?? null,
                'branch_id' => $reseller->branch_id,
                'ipAddress' => request()->ip(),
            ]);
            AuditLogger::log('reseller.withdrawal_requested', $tx, null, $tx->only(['ref_no', 'reseller_id', 'amount', 'method']), null, $reseller->branch_id);
            return $tx;
        });
    }

    public static function cancel(ResellerTransaction $tx): ResellerTransaction
    {
        return DB::transaction(function () use ($tx) {
            $tx = ResellerTransaction::lockForUpdate()->findOrFail($tx->id);
            if ($tx->type !== 'withdrawal' || $tx->status !== 'pending') {
                throw new RuntimeException('Only a pending withdrawal request can be cancelled.');
            }
            $tx->update(['status' => 'cancelled', 'processed_at' => now()]);
            AuditLogger::log('reseller.withdrawal_cancelled', $tx, ['status' => 'pending'], ['status' => 'cancelled'], null, $tx->branch_id);
            return $tx;
        });
    }

    // Company paid the reseller: the money leaves the chosen cash/bank account.
    public static function pay(ResellerTransaction $tx, array $data): ResellerTransaction
    {
        return DB::transaction(function () use ($tx, $data) {
            $tx = ResellerTransaction::lockForUpdate()->findOrFail($tx->id);
            if ($tx->type !== 'withdrawal' || $tx->status !== 'pending') {
                throw new RuntimeException('Only a pending withdrawal request can be paid.');
            }
            $method = $data['method'] ?? $tx->method;
            if ($method !== 'cash' && empty($data['bank_id'])) {
                throw new RuntimeException('Select the bank / mobile-banking account the money is paid from.');
            }
            $tx->update([
                'status' => 'paid',
                'method' => $method,
                'bank_id' => $method === 'cash' ? null : $data['bank_id'],
                'transaction_id' => $data['transaction_id'] ?? null,
                'admin_note' => $data['note'] ?? null,
                'processed_at' => now(),
                'processed_by' => Auth::guard('web')->id(),
            ]);
            self::mirror('payments', $tx, "Reseller withdrawal {$tx->ref_no}");
            AuditLogger::log('reseller.withdrawal_paid', $tx, ['status' => 'pending'], $tx->only(['status', 'amount', 'method', 'transaction_id']), $tx->admin_note, $tx->branch_id);
            return $tx;
        });
    }

    public static function reject(ResellerTransaction $tx, string $note): ResellerTransaction
    {
        return DB::transaction(function () use ($tx, $note) {
            $tx = ResellerTransaction::lockForUpdate()->findOrFail($tx->id);
            if ($tx->type !== 'withdrawal' || $tx->status !== 'pending') {
                throw new RuntimeException('Only a pending withdrawal request can be rejected.');
            }
            $tx->update(['status' => 'rejected', 'admin_note' => mb_substr($note, 0, 255), 'processed_at' => now(), 'processed_by' => Auth::guard('web')->id()]);
            AuditLogger::log('reseller.withdrawal_rejected', $tx, ['status' => 'pending'], ['status' => 'rejected'], $note, $tx->branch_id);
            return $tx;
        });
    }

    // Reseller handed collected cash over to the company.
    public static function deposit(Reseller $reseller, array $data): ResellerTransaction
    {
        return DB::transaction(function () use ($reseller, $data) {
            $amount = round((float) $data['amount'], 2);
            if ($amount <= 0) {
                throw new RuntimeException('Amount must be greater than zero.');
            }
            $method = $data['method'] ?? 'cash';
            if ($method !== 'cash' && empty($data['bank_id'])) {
                throw new RuntimeException('Select the bank / mobile-banking account the money was received into.');
            }
            $tx = ResellerTransaction::create([
                'ref_no' => SequenceService::next($reseller->branch_id, 'reseller_deposit', 'RD'),
                'reseller_id' => $reseller->id,
                'type' => 'deposit',
                'amount' => $amount,
                'status' => 'paid',
                'method' => $method,
                'bank_id' => $method === 'cash' ? null : $data['bank_id'],
                'transaction_id' => $data['transaction_id'] ?? null,
                'admin_note' => $data['note'] ?? null,
                'processed_at' => now(),
                'processed_by' => Auth::guard('web')->id(),
                'branch_id' => $reseller->branch_id,
                'ipAddress' => request()->ip(),
            ]);
            self::mirror('receives', $tx, "Reseller deposit {$tx->ref_no}");
            AuditLogger::log('reseller.deposit', $tx, null, $tx->only(['ref_no', 'reseller_id', 'amount', 'method']), $tx->admin_note, $reseller->branch_id);
            return $tx;
        });
    }

    // Cash/bank books: company money in (deposit) or out (withdrawal).
    private static function mirror(string $book, ResellerTransaction $tx, string $note): void
    {
        DB::table($book)->insert([
            'invoice' => $tx->ref_no,
            'reseller_id' => $tx->reseller_id,
            'reseller_transaction_id' => $tx->id,
            'date' => Carbon::parse($tx->processed_at)->toDateString(),
            'type' => 'reseller',
            'payment_method' => $tx->method === 'cash' ? 'cash' : 'bank',
            'bank_id' => $tx->bank_id,
            'amount' => $tx->amount,
            'previous_due' => 0,
            'note' => $note . ($tx->transaction_id ? ' / ' . $tx->transaction_id : ''),
            'status' => 'a',
            'created_by' => Auth::guard('web')->id(),
            'created_at' => now(),
            'ipAddress' => request()->ip() ?? '127.0.0.1',
            'branch_id' => $tx->branch_id,
        ]);
    }
}
