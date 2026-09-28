<?php

namespace App\Services\Isp;

use App\Support\Money;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\Reseller;
use App\Models\ResellerTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

// What a reseller and the level above it (its parent reseller, or the company for a top-level
// reseller) owe each other. See ResellerChainService for the tree.
//
//  earned     = the reseller's own margin on paid bills where it is in the chain, recognised as the
//               customer pays (reversals reduce it again): for the seller, net total - its cost;
//               for an upper level, what the level below owes it - what it owes above
//  downline   = the margins of its sub-resellers on the same payments: the money passes through
//               this reseller to them, so the parent owes it this too
//  collected  = cash its whole subtree took from customers in the reseller portal (still in hand),
//               plus customer bills paid from a wallet (method 'wallet'), split into own / downline
//  balance    = earned + downline - collected - downline collected + deposits - paid withdrawals
//               (deposits and withdrawals with the parent / company only; settlements between the
//               reseller and its own sub-resellers stay inside the subtree)
//               > 0 the parent owes the reseller, < 0 the reseller owes the parent
//  available  = balance - pending withdrawal requests
//
// Nothing here is stored as a running total; every figure comes from the source rows.
class ResellerWalletService
{
    // SQL for one level's margin on a bill, and for the chain margin (its own + everyone's below):
    // s = this reseller's invoice_reseller_shares row, c = the row of the level below it.
    public const OWN_MARGIN = "(case when s.level = 0 then invoices.total - invoices.tax_total - coalesce(s.cost, invoices.total - invoices.tax_total)
        else coalesce(c.cost, invoices.total - invoices.tax_total) - coalesce(s.cost, invoices.total - invoices.tax_total) end)";
    public const CHAIN_MARGIN = "(invoices.total - invoices.tax_total - coalesce(s.cost, invoices.total - invoices.tax_total))";

    // this reseller's share rows joined to their bills (and the level below, for the own margin)
    public static function shares(int $resellerId)
    {
        return DB::table('invoice_reseller_shares as s')
            ->join('invoices', 'invoices.id', '=', 's.invoice_id')
            ->leftJoin('invoice_reseller_shares as c', fn ($j) => $j->on('c.invoice_id', '=', 's.invoice_id')->whereRaw('c.level + 1 = s.level'))
            ->where('s.reseller_id', $resellerId)
            ->whereNotIn('invoices.status', ['draft', 'void', 'cancelled'])
            ->where('invoices.total', '>', 0);
    }

    public static function summary(int $resellerId): array
    {
        // Per allocation, rounded the same way as ResellerLedgerService, so statement == wallet.
        // Margins are on the bill's net (total less tax), pro rata to what was paid: the tax always
        // goes to the company.
        $d = Money::decimals();
        $paid = self::shares($resellerId)
            ->join('payment_allocations', 'payment_allocations.invoice_id', '=', 'invoices.id')
            ->where('payment_allocations.status', 'active')
            ->selectRaw('coalesce(sum(round(payment_allocations.amount * ' . self::CHAIN_MARGIN . " / invoices.total, $d)), 0) as chain,
                coalesce(sum(round(payment_allocations.amount * " . self::OWN_MARGIN . " / invoices.total, $d)), 0) as own")
            ->first();
        $expected = (float) self::shares($resellerId)->sum(DB::raw(self::OWN_MARGIN));

        $subtree = ResellerChainService::subtreeIds($resellerId) ?: [$resellerId];
        $collected = CustomerPayment::whereIn('collected_by_reseller_id', $subtree)
            ->whereNotIn('status', ['reversed', 'failed', 'pending'])
            ->selectRaw('coalesce(sum(case when collected_by_reseller_id = ? then amount end), 0) as own, coalesce(sum(amount), 0) as total', [$resellerId])
            ->first();

        $tx = ResellerTransaction::where('reseller_id', $resellerId)
            ->selectRaw("
                coalesce(sum(case when type = 'deposit' and status = 'paid' then amount end), 0) as deposits,
                coalesce(sum(case when type = 'withdrawal' and status = 'paid' then amount end), 0) as withdrawn,
                coalesce(sum(case when type = 'withdrawal' and status = 'pending' then amount end), 0) as pending
            ")->first();

        $reseller = Reseller::withTrashed()->with('parent')->find($resellerId);
        $balance = Money::round((float) $paid->chain - (float) $collected->total + (float) $tx->deposits - (float) $tx->withdrawn);
        $limit = $reseller?->credit_limit !== null ? Money::round((float) $reseller->credit_limit) : null;
        return [
            'earned' => Money::round((float) $paid->own),
            'downline' => Money::round((float) $paid->chain - (float) $paid->own),
            'expected' => Money::round($expected),
            'collected' => Money::round((float) $collected->own),
            'downline_collected' => Money::round((float) $collected->total - (float) $collected->own),
            'deposits' => Money::round((float) $tx->deposits),
            'withdrawn' => Money::round((float) $tx->withdrawn),
            'pending' => Money::round((float) $tx->pending),
            'balance' => $balance,
            'available' => Money::round(max(0, $balance - (float) $tx->pending)),
            // who this reseller settles with: its parent reseller, or the company
            'settles_with' => $reseller?->parent ? $reseller->parent->name : null,
            'credit_limit' => $limit,
            'over_limit' => $limit !== null && $balance < -$limit - 0.0001,
        ];
    }

    // Cash collection that would leave the reseller owing more than its credit limit is refused
    // until it settles (deposits cash with its parent / the company).
    public static function assertWithinCreditLimit(Reseller $reseller, float $amount): void
    {
        if ($reseller->credit_limit === null) {
            return;
        }
        $limit = (float) $reseller->credit_limit;
        $balance = self::summary($reseller->id)['balance'];
        if ($balance - $amount < -$limit - 0.0001) {
            $with = $reseller->parent_id ? (Reseller::withTrashed()->find($reseller->parent_id)?->name ?? 'your parent reseller') : 'the company';
            throw new RuntimeException('Over your credit limit: you would owe ' . Money::format(-($balance - $amount)) . ', the limit is '
                . Money::format($limit) . ". Deposit the cash you hold with {$with} first.");
        }
    }

    // The reseller pays a customer's bill out of their wallet balance. It is recorded as a
    // payment collected by the reseller (method 'wallet'), so the wallet drops by the amount
    // and the customer's invoices are paid, oldest first.
    public static function payFromWallet(Reseller $reseller, Customer $customer, float $amount, ?string $notes = null): CustomerPayment
    {
        return DB::transaction(function () use ($reseller, $customer, $amount, $notes) {
            // Same lock as withdrawals, so two requests can't both spend the same balance.
            Reseller::whereKey($reseller->id)->lockForUpdate()->first();
            if ((int) $customer->reseller_id !== (int) $reseller->id) {
                throw new RuntimeException('This customer is not yours.');
            }
            $amount = Money::round($amount);
            $available = self::summary($reseller->id)['available'];
            if ($amount > $available + 0.001) {
                throw new RuntimeException('Your wallet only has ' . Money::format($available) . ' available.');
            }
            return CollectionService::receive($customer, [
                'amount' => $amount,
                'method' => 'wallet',
                'payment_date' => now()->toDateString(),
                'source' => 'reseller',
                'reference' => 'Reseller wallet ' . $reseller->code,
                'notes' => $notes,
                'collected_by_reseller_id' => $reseller->id,
            ]);
        });
    }

    public static function requestWithdrawal(Reseller $reseller, array $data): ResellerTransaction
    {
        return DB::transaction(function () use ($reseller, $data) {
            // One request at a time per reseller, so two tabs can't both spend the same balance.
            Reseller::whereKey($reseller->id)->lockForUpdate()->first();
            $amount = Money::round((float) $data['amount']);
            if ($amount <= 0) {
                throw new RuntimeException('Amount must be greater than zero.');
            }
            $available = self::summary($reseller->id)['available'];
            if ($amount > $available + 0.001) {
                throw new RuntimeException('Only ' . Money::format($available) . ' is available to withdraw.');
            }

            $tx = ResellerTransaction::create([
                'ref_no' => SequenceService::next($reseller->branch_id, 'reseller_withdrawal', 'WD'),
                'reseller_id' => $reseller->id,
                'parent_reseller_id' => $reseller->parent_id, // who pays it: the parent, or the company (null)
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

    // A settlement is between a reseller and the level above it: the company (null) for a top-level
    // reseller, else its parent reseller. Only that side may pay, reject or record it.
    private static function assertCounterparty(?int $parentResellerId, ?Reseller $by): void
    {
        if ($parentResellerId === null && $by !== null) {
            throw new RuntimeException('This reseller settles with the company, not with you.');
        }
        if ($parentResellerId !== null && (int) $by?->id !== $parentResellerId) {
            $parent = Reseller::withTrashed()->find($parentResellerId);
            throw new RuntimeException("This reseller settles with its parent reseller {$parent?->name} in the reseller portal.");
        }
    }

    // The company paid the reseller (the money leaves the chosen cash/bank account), or a parent
    // reseller paid its sub-reseller out of its own pocket ($by; no company book is touched).
    public static function pay(ResellerTransaction $tx, array $data, ?Reseller $by = null): ResellerTransaction
    {
        return DB::transaction(function () use ($tx, $data, $by) {
            $tx = ResellerTransaction::lockForUpdate()->findOrFail($tx->id);
            if ($tx->type !== 'withdrawal' || $tx->status !== 'pending') {
                throw new RuntimeException('Only a pending withdrawal request can be paid.');
            }
            self::assertCounterparty($tx->parent_reseller_id ? (int) $tx->parent_reseller_id : null, $by);
            $method = $data['method'] ?? $tx->method;
            if (! $by && $method !== 'cash' && empty($data['bank_id'])) {
                throw new RuntimeException('Select the bank / mobile-banking account the money is paid from.');
            }
            $tx->update([
                'status' => 'paid',
                'method' => $method,
                'bank_id' => $by || $method === 'cash' ? null : $data['bank_id'],
                'transaction_id' => $data['transaction_id'] ?? null,
                'admin_note' => $data['note'] ?? null,
                'processed_at' => now(),
                'processed_by' => $by ? null : Auth::guard('web')->id(),
            ]);
            if (! $by) {
                self::mirror('payments', $tx, "Reseller withdrawal {$tx->ref_no}");
            }
            AuditLogger::log('reseller.withdrawal_paid', $tx, ['status' => 'pending'], $tx->only(['status', 'amount', 'method', 'transaction_id']), $tx->admin_note, $tx->branch_id);
            return $tx;
        });
    }

    public static function reject(ResellerTransaction $tx, string $note, ?Reseller $by = null): ResellerTransaction
    {
        return DB::transaction(function () use ($tx, $note, $by) {
            $tx = ResellerTransaction::lockForUpdate()->findOrFail($tx->id);
            if ($tx->type !== 'withdrawal' || $tx->status !== 'pending') {
                throw new RuntimeException('Only a pending withdrawal request can be rejected.');
            }
            self::assertCounterparty($tx->parent_reseller_id ? (int) $tx->parent_reseller_id : null, $by);
            $tx->update(['status' => 'rejected', 'admin_note' => mb_substr($note, 0, 255), 'processed_at' => now(), 'processed_by' => $by ? null : Auth::guard('web')->id()]);
            AuditLogger::log('reseller.withdrawal_rejected', $tx, ['status' => 'pending'], ['status' => 'rejected'], $note, $tx->branch_id);
            return $tx;
        });
    }

    // The reseller handed collected cash over to the company, or to its parent reseller ($by).
    public static function deposit(Reseller $reseller, array $data, ?Reseller $by = null): ResellerTransaction
    {
        return DB::transaction(function () use ($reseller, $data, $by) {
            self::assertCounterparty($reseller->parent_id ? (int) $reseller->parent_id : null, $by);
            $amount = Money::round((float) $data['amount']);
            if ($amount <= 0) {
                throw new RuntimeException('Amount must be greater than zero.');
            }
            $method = $data['method'] ?? 'cash';
            if (! $by && $method !== 'cash' && empty($data['bank_id'])) {
                throw new RuntimeException('Select the bank / mobile-banking account the money was received into.');
            }
            $tx = ResellerTransaction::create([
                'ref_no' => SequenceService::next($reseller->branch_id, 'reseller_deposit', 'RD'),
                'reseller_id' => $reseller->id,
                'parent_reseller_id' => $reseller->parent_id,
                'type' => 'deposit',
                'amount' => $amount,
                'status' => 'paid',
                'method' => $method,
                'bank_id' => $by || $method === 'cash' ? null : $data['bank_id'],
                'transaction_id' => $data['transaction_id'] ?? null,
                'admin_note' => $data['note'] ?? null,
                'processed_at' => now(),
                'processed_by' => $by ? null : Auth::guard('web')->id(),
                'branch_id' => $reseller->branch_id,
                'ipAddress' => request()->ip(),
            ]);
            if (! $by) {
                self::mirror('receives', $tx, "Reseller deposit {$tx->ref_no}");
            }
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
