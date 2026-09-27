<?php

namespace App\Services\Isp;

use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\PaymentAllocation;
use App\Models\Refund;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

// Money in (payments), its allocation to invoices, reversal and refund.
//
//  wrong amount       -> reverse() the payment, then receive() the correct one
//  wrong invoice      -> reverseAllocation(), then allocate() to the right invoice
//  over-payment       -> the unallocated rest is advance credit, auto-applied to the next invoice
//  money back         -> refund() from the unallocated part
//
// Completed payments are never edited or deleted.
class CollectionService
{
    /**
     * @param array $data amount, method, bank_id, payment_date, transaction_id, reference, notes, provider, source
     * @param array|null $allocations [invoice_id => amount]; null = auto-allocate oldest invoices first
     */
    public static function receive(Customer $customer, array $data, ?array $allocations = null, bool $autoAllocate = true): CustomerPayment
    {
        $payment = DB::transaction(function () use ($customer, $data, $allocations, $autoAllocate) {
            $amount = round((float) $data['amount'], 2);
            if ($amount <= 0) {
                throw new RuntimeException('Amount must be greater than zero.');
            }
            $method = $data['method'] ?? 'cash';
            // Money a reseller collects stays with the reseller (their own cash / wallet) until
            // they settle with the company, so it has no company account and no cash-book entry.
            $resellerId = $data['collected_by_reseller_id'] ?? null;
            if ($method !== 'cash' && empty($data['bank_id']) && ! $resellerId) {
                throw new RuntimeException('Select the bank / mobile-banking account this money was received into.');
            }
            if (! empty($data['transaction_id'])) {
                $dup = CustomerPayment::where('branch_id', $customer->branch_id)
                    ->where('method', $method)
                    ->where('transaction_id', $data['transaction_id'])
                    ->whereNotIn('status', ['reversed', 'failed'])
                    ->exists();
                if ($dup) {
                    throw new RuntimeException("Transaction ID {$data['transaction_id']} is already recorded.");
                }
            }

            $payment = CustomerPayment::create([
                'receipt_no' => SequenceService::next($customer->branch_id, 'receipt', IspSettings::get($customer->branch_id, 'receipt_prefix')),
                'customer_id' => $customer->id,
                'payment_date' => Carbon::parse($data['payment_date'] ?? now())->toDateString(),
                'amount' => $amount,
                'method' => $method,
                'bank_id' => $method === 'cash' || $resellerId ? null : $data['bank_id'],
                'collected_by_reseller_id' => $resellerId,
                'provider' => $data['provider'] ?? null,
                'transaction_id' => $data['transaction_id'] ?? null,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'source' => $data['source'] ?? 'admin',
                'status' => 'completed',
                'received_by' => Auth::guard('web')->id(),
                'branch_id' => $customer->branch_id,
                'created_by' => Auth::guard('web')->id(),
                'ipAddress' => app()->runningInConsole() ? null : request()->ip(),
            ]);

            LedgerService::post($customer->id, $customer->branch_id, 'payment', 0, $amount,
                "Payment {$payment->receipt_no} ({$method})" . ($payment->transaction_id ? " TrxID {$payment->transaction_id}" : ''),
                'customer_payment', $payment->id, $payment->payment_date);

            if (! $resellerId) {
                self::mirrorReceive($payment);
            }

            if ($allocations) {
                $total = round(array_sum(array_map('floatval', $allocations)), 2);
                if ($total > $amount + 0.001) {
                    throw new RuntimeException('Allocated total is more than the payment amount.');
                }
                foreach ($allocations as $invoiceId => $allocAmount) {
                    if ((float) $allocAmount > 0) {
                        $invoice = Invoice::where('customer_id', $customer->id)->findOrFail($invoiceId);
                        self::allocate($payment, $invoice, (float) $allocAmount);
                    }
                }
            }
            if ($autoAllocate) {
                self::autoAllocate($customer->id);
            }

            AuditLogger::log('payment.received', $payment, null, $payment->only(['receipt_no', 'customer_id', 'amount', 'method', 'transaction_id']));
            return $payment->fresh(['allocations.invoice']);
        });

        OverdueService::reactivateIfClear($customer->id);
        IspNotifier::send($customer->branch_id, $customer, 'payment', [
            'amount' => number_format((float) $payment->amount, 2),
            'receipt' => $payment->receipt_no,
        ]);
        return $payment;
    }

    public static function allocate(CustomerPayment $payment, Invoice $invoice, float $amount): PaymentAllocation
    {
        return DB::transaction(function () use ($payment, $invoice, $amount) {
            $payment = CustomerPayment::lockForUpdate()->findOrFail($payment->id);
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);
            $amount = round($amount, 2);

            if ($payment->customer_id !== $invoice->customer_id) {
                throw new RuntimeException('Payment and invoice belong to different customers.');
            }
            if (! in_array($invoice->status, Invoice::OPEN_STATUSES, true)) {
                throw new RuntimeException("Invoice {$invoice->invoice_no} is not open for payment ({$invoice->status}).");
            }
            if ($amount <= 0 || $amount > $payment->unallocated() + 0.001) {
                throw new RuntimeException("Only " . number_format($payment->unallocated(), 2) . " of payment {$payment->receipt_no} is unallocated.");
            }
            if ($amount > (float) $invoice->due + 0.001) {
                throw new RuntimeException("Invoice {$invoice->invoice_no} only has " . number_format((float) $invoice->due, 2) . ' due.');
            }

            $allocation = PaymentAllocation::create([
                'customer_payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'amount' => $amount,
                'status' => 'active',
                'created_by' => Auth::guard('web')->id(),
            ]);
            $payment->allocated_amount = round((float) $payment->allocated_amount + $amount, 2);
            $payment->save();
            BillingService::recalculate($invoice);
            return $allocation;
        });
    }

    // Applies every unallocated payment of the customer to open invoices, oldest due date first.
    public static function autoAllocate(int $customerId): void
    {
        DB::transaction(function () use ($customerId) {
            $payments = CustomerPayment::where('customer_id', $customerId)
                ->whereIn('status', ['completed', 'partially_refunded'])
                ->whereRaw('amount - allocated_amount - refunded_amount > 0')
                ->orderBy('payment_date')->orderBy('id')
                ->lockForUpdate()->get();
            if ($payments->isEmpty()) {
                return;
            }
            $invoices = Invoice::where('customer_id', $customerId)
                ->whereIn('status', Invoice::OPEN_STATUSES)
                ->where('due', '>', 0)
                ->orderBy('due_date')->orderBy('id')
                ->lockForUpdate()->get();

            foreach ($invoices as $invoice) {
                foreach ($payments as $payment) {
                    $free = $payment->unallocated();
                    $due = (float) $invoice->due;
                    if ($free <= 0 || $due <= 0) {
                        continue;
                    }
                    self::allocate($payment, $invoice, min($free, $due));
                    $payment->refresh();
                    $invoice->refresh();
                }
            }
        });
    }

    // Takes money off an invoice; it goes back to the payment's unallocated (advance) part.
    public static function reverseAllocation(PaymentAllocation $allocation, string $reason): void
    {
        DB::transaction(function () use ($allocation, $reason) {
            $allocation = PaymentAllocation::lockForUpdate()->findOrFail($allocation->id);
            if ($allocation->status !== 'active') {
                throw new RuntimeException('Allocation is already reversed.');
            }
            $payment = CustomerPayment::lockForUpdate()->findOrFail($allocation->customer_payment_id);
            $invoice = Invoice::lockForUpdate()->findOrFail($allocation->invoice_id);

            $allocation->update([
                'status' => 'reversed',
                'reversed_at' => now(),
                'reversed_by' => Auth::guard('web')->id(),
                'reversal_reason' => mb_substr($reason, 0, 255),
            ]);
            $payment->allocated_amount = max(0, round((float) $payment->allocated_amount - (float) $allocation->amount, 2));
            $payment->save();
            BillingService::recalculate($invoice);

            AuditLogger::log('allocation.reversed', $payment, ['invoice' => $invoice->invoice_no, 'amount' => (float) $allocation->amount], null, $reason);
        });
    }

    // Moves an allocation from one invoice to another (payment posted to the wrong invoice).
    public static function reallocate(PaymentAllocation $allocation, Invoice $target, string $reason): PaymentAllocation
    {
        return DB::transaction(function () use ($allocation, $target, $reason) {
            $payment = $allocation->payment;
            self::reverseAllocation($allocation, $reason);
            $target->refresh();
            $amount = min((float) $allocation->amount, (float) $target->due);
            if ($amount <= 0) {
                throw new RuntimeException("Invoice {$target->invoice_no} has nothing due.");
            }
            $new = self::allocate($payment->fresh(), $target, $amount);
            AuditLogger::log('allocation.moved', $payment, null, ['to_invoice' => $target->invoice_no, 'amount' => $amount], $reason);
            return $new;
        });
    }

    // Wrong/bounced payment: undo it completely. The record stays, marked reversed.
    public static function reverse(CustomerPayment $payment, string $reason): CustomerPayment
    {
        return DB::transaction(function () use ($payment, $reason) {
            $payment = CustomerPayment::lockForUpdate()->findOrFail($payment->id);
            if ($payment->status !== 'completed') {
                throw new RuntimeException("Only a completed payment can be reversed (current: {$payment->status}). Refunded payments cannot be reversed.");
            }
            foreach ($payment->allocations()->where('status', 'active')->get() as $allocation) {
                self::reverseAllocation($allocation, 'Payment reversed: ' . $reason);
            }
            $payment->refresh();

            $old = $payment->only(['status', 'amount']);
            $payment->status = 'reversed';
            $payment->reversed_at = now();
            $payment->reversed_by = Auth::guard('web')->id();
            $payment->reversal_reason = mb_substr($reason, 0, 255);
            $payment->save();

            LedgerService::post($payment->customer_id, $payment->branch_id, 'payment_reversal', (float) $payment->amount, 0,
                "Payment {$payment->receipt_no} reversed: {$reason}", 'customer_payment', $payment->id);

            DB::table('receives')->where('customer_payment_id', $payment->id)->where('status', 'a')->update([
                'status' => 'd', 'deleted_at' => now(), 'deleted_by' => Auth::guard('web')->id(),
            ]);

            AuditLogger::log('payment.reversed', $payment, $old, ['status' => 'reversed'], $reason);
            return $payment;
        });
    }

    // Refund from the payment's unallocated (advance) part. To refund money already on an
    // invoice, reverse that allocation first — this keeps invoices and ledger consistent.
    public static function refund(CustomerPayment $payment, array $data): Refund
    {
        return DB::transaction(function () use ($payment, $data) {
            $payment = CustomerPayment::lockForUpdate()->findOrFail($payment->id);
            $amount = round((float) $data['amount'], 2);
            $method = $data['method'] ?? 'cash';
            if ($amount <= 0) {
                throw new RuntimeException('Amount must be greater than zero.');
            }
            if ($amount > $payment->unallocated() + 0.001) {
                throw new RuntimeException('Only ' . number_format($payment->unallocated(), 2) . ' of this payment is unallocated and refundable. Reverse an invoice allocation first to refund more.');
            }
            if ($method !== 'cash' && empty($data['bank_id'])) {
                throw new RuntimeException('Select the bank / mobile-banking account the refund is paid from.');
            }

            $refund = Refund::create([
                'refund_no' => SequenceService::next($payment->branch_id, 'refund', IspSettings::get($payment->branch_id, 'refund_prefix')),
                'customer_payment_id' => $payment->id,
                'customer_id' => $payment->customer_id,
                'amount' => $amount,
                'refund_date' => Carbon::parse($data['refund_date'] ?? now())->toDateString(),
                'method' => $method,
                'bank_id' => $method === 'cash' ? null : $data['bank_id'],
                'reason' => mb_substr($data['reason'], 0, 255),
                'branch_id' => $payment->branch_id,
                'created_by' => Auth::guard('web')->id(),
                'ipAddress' => request()->ip(),
            ]);

            $old = $payment->only(['status', 'refunded_amount']);
            $payment->refunded_amount = round((float) $payment->refunded_amount + $amount, 2);
            $payment->status = $payment->refunded_amount >= (float) $payment->amount - 0.001 ? 'refunded' : 'partially_refunded';
            $payment->save();

            LedgerService::post($payment->customer_id, $payment->branch_id, 'refund', $amount, 0,
                "Refund {$refund->refund_no} of payment {$payment->receipt_no}: {$refund->reason}", 'refund', $refund->id, $refund->refund_date);

            self::mirrorRefund($refund);
            AuditLogger::log('payment.refunded', $payment, $old, $payment->only(['status', 'refunded_amount']) + ['refund_no' => $refund->refund_no], $refund->reason);
            return $refund;
        });
    }

    // Unallocated money across all the customer's payments (advance credit).
    public static function advanceCredit(int $customerId): float
    {
        return round((float) CustomerPayment::where('customer_id', $customerId)
            ->whereIn('status', ['completed', 'partially_refunded'])
            ->sum(DB::raw('amount - allocated_amount - refunded_amount')), 2);
    }

    // Cash/bank books: the payment appears as a customer receive.
    private static function mirrorReceive(CustomerPayment $payment): void
    {
        DB::table('receives')->insert([
            'invoice' => $payment->receipt_no,
            'customer_id' => $payment->customer_id,
            'date' => $payment->payment_date->toDateString(),
            'type' => 'customer',
            'payment_method' => $payment->method === 'cash' ? 'cash' : 'bank',
            'bank_id' => $payment->bank_id,
            'amount' => $payment->amount,
            'previous_due' => 0,
            'note' => 'ISP bill collection ' . $payment->receipt_no . ($payment->transaction_id ? ' / ' . $payment->transaction_id : ''),
            'status' => 'a',
            'created_by' => Auth::guard('web')->id(),
            'created_at' => now(),
            'ipAddress' => request()->ip() ?? '127.0.0.1',
            'branch_id' => $payment->branch_id,
            'customer_payment_id' => $payment->id,
        ]);
    }

    private static function mirrorRefund(Refund $refund): void
    {
        DB::table('payments')->insert([
            'invoice' => $refund->refund_no,
            'customer_id' => $refund->customer_id,
            'date' => $refund->refund_date->toDateString(),
            'type' => 'customer',
            'payment_method' => $refund->method === 'cash' ? 'cash' : 'bank',
            'bank_id' => $refund->bank_id,
            'amount' => $refund->amount,
            'previous_due' => 0,
            'note' => 'ISP refund ' . $refund->refund_no,
            'status' => 'a',
            'created_by' => Auth::guard('web')->id(),
            'created_at' => now(),
            'ipAddress' => request()->ip() ?? '127.0.0.1',
            'branch_id' => $refund->branch_id,
            'refund_id' => $refund->id,
        ]);
    }
}
