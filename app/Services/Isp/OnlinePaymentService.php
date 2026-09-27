<?php

namespace App\Services\Isp;

use App\Support\Money;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\OnlinePayment;
use App\Models\PaymentGateway;
use App\Services\Isp\Payments\BkashDriver;
use App\Services\Isp\Payments\GatewayDriver;
use App\Services\Isp\Payments\NagadDriver;
use App\Services\Isp\Payments\SslcommerzDriver;
use App\Services\Isp\Payments\StripeDriver;
use App\Services\Isp\Payments\PaypalDriver;
use App\Models\GatewayEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

// Customer self-service payments (bKash, Nagad, Rocket, SSLCommerz, Stripe, PayPal).
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
        'stripe' => StripeDriver::class,
        'paypal' => PaypalDriver::class,
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
        if (! isset(PaymentGateway::GATEWAYS[$g->gateway]) || ! $g->bank_id
            || ! PaymentGateway::supportsCurrency($g->gateway, Money::code())) {
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
        $amount = Money::round($amount);
        if ($amount < (float) $gateway->min_amount || $amount > (float) $gateway->max_amount) {
            throw new RuntimeException('Amount must be between ' . Money::format($gateway->min_amount)
                . ' and ' . Money::format($gateway->max_amount) . " for {$gateway->label()}.");
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

            if ($result->status === 'pending') {
                // not paid yet (still on the checkout page, or settling later): stays open
                $payment->save();
                return $payment;
            }
            if ($result->status !== 'completed') {
                $payment->status = $result->status === 'cancelled' ? 'cancelled' : 'failed';
                $payment->failure_reason = mb_substr((string) $result->reason, 0, 255);
                $payment->save();
                return $payment;
            }

            $payment->trx_id = $result->trxId;
            // money was taken but not the amount we asked for: let an admin decide
            if (! Money::equals($result->amount, $payment->amount)) {
                $payment->status = 'pending_review';
                $payment->failure_reason = 'Gateway reported ' . Money::format($result->amount) . ' instead of ' . Money::format($payment->amount);
                $payment->save();
                return $payment;
            }
            $payment->save();
            return self::complete($payment);
        });
    }

    /**
     * A webhook / IPN event from a gateway, already authenticated by the caller. The provider's
     * event id is stored (gateway_events): an event that was processed before is acknowledged and
     * skipped, so a repeated delivery can never record the money twice. The event body is only
     * used to find the payment; handleCallback() asks the gateway for the real state.
     *
     * @return bool true when the event is done (answer 2xx), false to ask the provider to resend it
     */
    public static function handleEvent(string $key, string $eventId, ?string $type, ?string $ref, array $payload = []): bool
    {
        $event = GatewayEvent::firstOrCreate(['gateway' => $key, 'event_id' => $eventId], ['type' => $type, 'payload' => $payload]);
        if ($event->processed_at) {
            return true; // duplicate delivery
        }
        $event->increment('attempts');

        $payment = $ref ? OnlinePayment::where('ref', $ref)->where('gateway', $key)->where('mode', 'api')->first() : null;
        if (! $payment) {
            // not one of ours (another app on the same account, or a deleted attempt): nothing to do
            $event->update(['processed_at' => now(), 'result' => 'No matching payment']);
            return true;
        }
        $payment = self::handleCallback($key, $ref, ['webhook' => $type]);
        $done = $payment->status !== 'initiated' || ! self::expectsFinalState($key, $type, $payload);
        $event->update([
            'online_payment_id' => $payment->id,
            'processed_at' => $done ? now() : null,
            'result' => mb_substr($payment->status . ($payment->failure_reason ? ": {$payment->failure_reason}" : ''), 0, 255),
        ]);
        return $done;
    }

    // Events after which the payment must have left 'initiated'; if it hasn't (the gateway could
    // not be reached to confirm), the provider is asked to send the event again.
    private static function expectsFinalState(string $key, ?string $type, array $payload): bool
    {
        return match ($key) {
            // "completed" with payment_status unpaid = a method that settles later (async_payment_* follows)
            'stripe' => ($type === 'checkout.session.completed' && data_get($payload, 'data.object.payment_status') !== 'unpaid')
                || in_array($type, ['checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed', 'checkout.session.expired'], true),
            // an approved order is captured here; a pending capture (eCheck, review) completes later
            'paypal' => in_array($type, ['CHECKOUT.ORDER.APPROVED', 'PAYMENT.CAPTURE.COMPLETED', 'PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.DECLINED'], true),
            default => true,
        };
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
                $payment->amount = Money::round($amount);
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
            'due' => Money::round(max(0, $balance)),
            'wallet' => CollectionService::advanceCredit($customerId),
            'pending' => Money::round((float) OnlinePayment::where('customer_id', $customerId)->where('status', 'pending_review')->sum('amount')),
        ];
    }
}
