<?php

namespace App\Services\Isp;

use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\OnlinePayment;
use App\Models\PaymentGateway;
use App\Services\Isp\Payments\BkashDriver;
use App\Services\Isp\Payments\GatewayDriver;
use App\Services\Isp\Payments\NagadDriver;
use App\Services\Isp\Payments\SslcommerzDriver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

// Customer self-service payments (bKash, Nagad, Rocket, SSLCommerz).
//
//  API mode     start() -> gateway checkout -> handleCallback() verifies server to server -> complete()
//  manual mode  submitManual() -> admin checks the TrxID in their wallet app -> approve() / reject()
//
// Money reaches the ledger only through complete(), which records a normal CollectionService
// payment. Unallocated money stays as advance credit: that is the customer's wallet balance,
// used automatically for the next open invoices.
class OnlinePaymentService
{
    public const DRIVERS = [
        'bkash' => BkashDriver::class,
        'nagad' => NagadDriver::class,
        'sslcommerz' => SslcommerzDriver::class,
    ];

    // Gateways a customer of this branch can use right now, in display order.
    public static function available(int $branchId): Collection
    {
        return PaymentGateway::where('branch_id', $branchId)->where('is_active', true)
            ->orderBy('sort')->orderBy('id')->get()
            ->filter(fn (PaymentGateway $g) => self::isUsable($g))
            ->values();
    }

    public static function isUsable(PaymentGateway $g): bool
    {
        if (! isset(PaymentGateway::GATEWAYS[$g->gateway]) || ! $g->bank_id) {
            return false;
        }
        return $g->mode === 'api'
            ? isset(self::DRIVERS[$g->gateway]) && $g->hasCredentials()
            : ! empty($g->manual_number);
    }

    private static function gateway(Customer $customer, string $key, string $mode): PaymentGateway
    {
        $gateway = PaymentGateway::where('branch_id', $customer->branch_id)->where('gateway', $key)->first();
        if (! $gateway || ! $gateway->is_active || ! self::isUsable($gateway) || $gateway->mode !== $mode) {
            throw new RuntimeException('This payment method is not available right now.');
        }
        return $gateway;
    }

    private static function checkAmount(PaymentGateway $gateway, float $amount): float
    {
        $amount = round($amount, 2);
        if ($amount < (float) $gateway->min_amount || $amount > (float) $gateway->max_amount) {
            throw new RuntimeException('Amount must be between Tk ' . number_format((float) $gateway->min_amount, 2)
                . ' and Tk ' . number_format((float) $gateway->max_amount, 2) . " for {$gateway->label()}.");
        }
        return $amount;
    }

    private static function newRef(): string
    {
        // alphanumeric and at most 20 characters: Nagad's orderId limit
        do {
            $ref = 'OP' . now()->format('ymd') . strtoupper(Str::random(8));
        } while (OnlinePayment::where('ref', $ref)->exists());
        return $ref;
    }

    public static function driver(PaymentGateway $gateway): GatewayDriver
    {
        $class = self::DRIVERS[$gateway->gateway] ?? null;
        if (! $class) {
            throw new RuntimeException("{$gateway->label()} has no online checkout; use manual payment.");
        }
        return new $class($gateway);
    }

    // API mode: creates the attempt and returns the gateway URL to redirect the customer to.
    public static function start(Customer $customer, string $key, float $amount, string $purpose, callable $callbackUrl): string
    {
        $gateway = self::gateway($customer, $key, 'api');
        $payment = OnlinePayment::create([
            'ref' => self::newRef(),
            'branch_id' => $customer->branch_id,
            'customer_id' => $customer->id,
            'gateway' => $key,
            'mode' => 'api',
            'purpose' => $purpose === 'wallet' ? 'wallet' : 'bill',
            'amount' => self::checkAmount($gateway, $amount),
            'status' => 'initiated',
            'ipAddress' => request()->ip(),
        ]);

        try {
            $url = self::driver($gateway)->initiate($payment, $customer, $callbackUrl($payment));
            $payment->save();
            return $url;
        } catch (\Throwable $e) {
            $payment->status = 'failed';
            $payment->failure_reason = mb_substr($e->getMessage(), 0, 255);
            $payment->save();
            if (! $e instanceof RuntimeException) {
                Log::error($e);
            }
            throw new RuntimeException($e instanceof RuntimeException ? $e->getMessage() : 'Could not reach the payment gateway. Please try again.');
        }
    }

    // The customer came back from the gateway (or the gateway sent an IPN).
    // Safe to call more than once for the same payment.
    public static function handleCallback(string $key, string $ref, array $input): OnlinePayment
    {
        $payment = OnlinePayment::where('ref', $ref)->where('gateway', $key)->where('mode', 'api')->firstOrFail();
        if ($payment->status !== 'initiated') {
            return $payment;
        }
        $gateway = PaymentGateway::where('branch_id', $payment->branch_id)->where('gateway', $key)->firstOrFail();

        try {
            $result = self::driver($gateway)->verify($payment, $input);
        } catch (\Throwable $e) {
            Log::error($e);
            $payment->failure_reason = mb_substr('Verification error: ' . $e->getMessage(), 0, 255);
            $payment->save();
            return $payment; // stays 'initiated' so a later callback / IPN can still complete it
        }

        return DB::transaction(function () use ($payment, $result) {
            $payment = OnlinePayment::lockForUpdate()->findOrFail($payment->id);
            if ($payment->status !== 'initiated') {
                return $payment; // a parallel callback / IPN got here first
            }
            $payment->logPayload('verify', $result->raw);

            if ($result->status !== 'completed') {
                $payment->status = $result->status === 'cancelled' ? 'cancelled' : 'failed';
                $payment->failure_reason = mb_substr((string) $result->reason, 0, 255);
                $payment->save();
                return $payment;
            }

            $payment->trx_id = $result->trxId;
            // money was taken but not the amount we asked for: let an admin decide
            if (abs((float) $result->amount - (float) $payment->amount) > 0.009) {
                $payment->status = 'pending_review';
                $payment->failure_reason = 'Gateway reported Tk ' . number_format((float) $result->amount, 2) . ' instead of Tk ' . number_format((float) $payment->amount, 2);
                $payment->save();
                return $payment;
            }
            $payment->save();
            return self::complete($payment);
        });
    }

    // Manual mode: the customer already sent money to our number and reports the TrxID.
    public static function submitManual(Customer $customer, string $key, float $amount, string $purpose, string $trxId, string $sender): OnlinePayment
    {
        $gateway = self::gateway($customer, $key, 'manual');
        $amount = self::checkAmount($gateway, $amount);
        $trxId = strtoupper(trim($trxId));
        $method = PaymentGateway::GATEWAYS[$key]['method'];

        $used = OnlinePayment::where('gateway', $key)->where('trx_id', $trxId)->whereNotIn('status', ['rejected', 'failed', 'cancelled'])->exists()
            || CustomerPayment::where('method', $method)->where('transaction_id', $trxId)->whereNotIn('status', ['reversed', 'failed'])->exists();
        if ($used) {
            throw new RuntimeException("Transaction ID {$trxId} has already been submitted.");
        }
        if (OnlinePayment::where('customer_id', $customer->id)->where('status', 'pending_review')->count() >= 5) {
            throw new RuntimeException('You already have 5 payments waiting for verification. Please wait until they are checked.');
        }

        $payment = OnlinePayment::create([
            'ref' => self::newRef(),
            'branch_id' => $customer->branch_id,
            'customer_id' => $customer->id,
            'gateway' => $key,
            'mode' => 'manual',
            'purpose' => $purpose === 'wallet' ? 'wallet' : 'bill',
            'amount' => $amount,
            'status' => 'pending_review',
            'trx_id' => $trxId,
            'sender_number' => $sender,
            'ipAddress' => request()->ip(),
        ]);
        AuditLogger::log('online_payment.submitted', $payment, null, $payment->only(['ref', 'gateway', 'amount', 'trx_id', 'sender_number']));
        return $payment;
    }

    // Admin confirmed a pending payment (manual TrxID seen in the wallet app, or an amount mismatch).
    public static function approve(OnlinePayment $payment, ?float $amount = null, ?string $note = null): OnlinePayment
    {
        return DB::transaction(function () use ($payment, $amount, $note) {
            $payment = OnlinePayment::lockForUpdate()->findOrFail($payment->id);
            if ($payment->status !== 'pending_review') {
                throw new RuntimeException('Only payments waiting for review can be approved.');
            }
            $old = $payment->only(['status', 'amount']);
            if ($amount !== null && $amount > 0) {
                $payment->amount = round($amount, 2);
            }
            $payment->reviewed_by = Auth::guard('web')->id();
            $payment->reviewed_at = now();
            $payment->save();
            $payment = self::complete($payment, $note);
            AuditLogger::log('online_payment.approved', $payment, $old, $payment->only(['status', 'amount', 'trx_id']), $note);
            return $payment;
        });
    }

    public static function reject(OnlinePayment $payment, string $reason): OnlinePayment
    {
        return DB::transaction(function () use ($payment, $reason) {
            $payment = OnlinePayment::lockForUpdate()->findOrFail($payment->id);
            if (! in_array($payment->status, OnlinePayment::OPEN, true)) {
                throw new RuntimeException('This payment is already ' . str_replace('_', ' ', $payment->status) . '.');
            }
            $payment->status = 'rejected';
            $payment->failure_reason = mb_substr($reason, 0, 255);
            $payment->reviewed_by = Auth::guard('web')->id();
            $payment->reviewed_at = now();
            $payment->save();
            AuditLogger::log('online_payment.rejected', $payment, null, $payment->only(['ref', 'amount', 'trx_id']), $reason);
            return $payment;
        });
    }

    // Records the money as a normal customer payment (ledger, allocation, cash/bank book, SMS).
    private static function complete(OnlinePayment $payment, ?string $note = null): OnlinePayment
    {
        $gateway = PaymentGateway::where('branch_id', $payment->branch_id)->where('gateway', $payment->gateway)->firstOrFail();
        if (! $gateway->bank_id) {
            throw new RuntimeException("Set the receiving account for {$gateway->label()} first.");
        }
        $customer = Customer::withTrashed()->findOrFail($payment->customer_id);

        $received = CollectionService::receive($customer, [
            'amount' => (float) $payment->amount,
            'method' => PaymentGateway::GATEWAYS[$payment->gateway]['method'],
            'bank_id' => $gateway->bank_id,
            'provider' => $gateway->label(),
            'transaction_id' => $payment->trx_id,
            'reference' => $payment->ref,
            'source' => $payment->mode === 'api' ? 'gateway' : 'portal',
            'notes' => trim(($payment->purpose === 'wallet' ? 'Wallet top-up' : 'Bill payment') . " via {$gateway->label()}"
                . ($payment->sender_number ? " from {$payment->sender_number}" : '') . ($note ? ". {$note}" : '')),
        ]);

        $payment->customer_payment_id = $received->id;
        $payment->status = 'completed';
        $payment->completed_at = now();
        $payment->save();
        return $payment;
    }

    // What the customer owes and holds: due is the ledger balance, wallet is the advance credit.
    public static function summary(int $customerId): array
    {
        $balance = LedgerService::balance($customerId);
        return [
            'due' => round(max(0, $balance), 2),
            'wallet' => CollectionService::advanceCredit($customerId),
            'pending' => round((float) OnlinePayment::where('customer_id', $customerId)->where('status', 'pending_review')->sum('amount'), 2),
        ];
    }
}
