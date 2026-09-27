<?php

namespace App\Services\Isp\Payments;

use App\Models\Customer;
use App\Models\OnlinePayment;
use App\Models\PaymentGateway;
use App\Support\Money;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

// Stripe Checkout: the customer pays on Stripe's hosted page (cards, wallets and local methods
// switched on in the Stripe dashboard), so card data never touches this server. The result is
// always read back from Stripe's API with the secret key: neither the return URL nor a webhook
// body is trusted on its own.
class StripeDriver implements GatewayDriver
{
    public const API = 'https://api.stripe.com/v1';

    // Stripe's minor units differ from ISO 4217 for a few currencies (IDR is 2-decimal at Stripe).
    public const ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];
    public const THREE_DECIMAL = ['BHD', 'JOD', 'KWD', 'OMR', 'TND'];

    // A signed webhook older than this is refused (replay protection), as Stripe's own libraries do.
    public const WEBHOOK_TOLERANCE = 300;

    public function __construct(private PaymentGateway $gateway)
    {
    }

    private function http(): PendingRequest
    {
        return Http::timeout(30)->withBasicAuth((string) $this->gateway->credential('secret_key'), '')->asForm()->acceptJson();
    }

    public static function exponent(string $currency): int
    {
        return in_array($currency, self::ZERO_DECIMAL, true) ? 0 : (in_array($currency, self::THREE_DECIMAL, true) ? 3 : 2);
    }

    // 500.00 USD => 50000; three-decimal currencies must be whole multiples of 10 at Stripe.
    public static function toMinor(float $amount, string $currency): int
    {
        $minor = (int) round($amount * 10 ** self::exponent($currency));
        if (self::exponent($currency) === 3 && $minor % 10 !== 0) {
            throw new RuntimeException("Stripe takes {$currency} amounts in steps of 0.010.");
        }
        return $minor;
    }

    public static function fromMinor(int $minor, string $currency): float
    {
        return $minor / 10 ** self::exponent($currency);
    }

    public function initiate(OnlinePayment $payment, Customer $customer, string $callbackUrl): string
    {
        $currency = Money::code();
        $sep = str_contains($callbackUrl, '?') ? '&' : '?';
        $res = $this->http()
            // the same payment can't create two Checkout sessions, even if this request is retried
            ->withHeaders(['Idempotency-Key' => 'checkout-' . $payment->ref])
            ->post(self::API . '/checkout/sessions', array_filter([
                'mode' => 'payment',
                'client_reference_id' => $payment->ref,
                'metadata[ref]' => $payment->ref,
                'payment_intent_data[metadata][ref]' => $payment->ref,
                'line_items[0][quantity]' => 1,
                'line_items[0][price_data][currency]' => strtolower($currency),
                'line_items[0][price_data][unit_amount]' => self::toMinor((float) $payment->amount, $currency),
                'line_items[0][price_data][product_data][name]' => ($payment->purpose === 'wallet' ? 'Wallet top-up' : 'Internet bill') . " ({$customer->code})",
                'customer_email' => filter_var($customer->email, FILTER_VALIDATE_EMAIL) ? $customer->email : null,
                'success_url' => $callbackUrl . $sep . 'session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $callbackUrl . $sep . 'cancelled=1',
            ], fn ($v) => $v !== null));
        $data = $res->json() ?? [];
        $payment->logPayload('init', array_intersect_key($data, array_flip(['id', 'status', 'payment_status', 'amount_total', 'currency', 'expires_at', 'error'])));
        if (! $res->successful() || empty($data['url']) || empty($data['id'])) {
            throw new RuntimeException('Stripe: ' . ($data['error']['message'] ?? 'could not start the payment'));
        }
        $payment->gateway_payment_id = $data['id'];
        return $data['url'];
    }

    public function verify(OnlinePayment $payment, array $input): GatewayResult
    {
        $sessionId = $payment->gateway_payment_id;
        if (! $sessionId) {
            return GatewayResult::failed('No Stripe session for this payment', $input);
        }
        if (! empty($input['session_id']) && $input['session_id'] !== $sessionId) {
            return GatewayResult::failed('Stripe session mismatch', $input);
        }

        $session = $this->session($sessionId);
        // the customer pressed "back" on Stripe's page: close the session so it can't be paid later
        // unnoticed; if it was paid in the meantime, expiring fails and the payment completes below
        if (! empty($input['cancelled']) && ($session['status'] ?? null) === 'open') {
            $expired = $this->http()->post(self::API . "/checkout/sessions/{$sessionId}/expire");
            if ($expired->successful()) {
                return GatewayResult::failed('Cancelled by customer', $expired->json() ?? [], 'cancelled');
            }
            $session = $this->session($sessionId);
        }

        return $this->result($payment, $session);
    }

    private function session(string $id): array
    {
        $res = $this->http()->get(self::API . "/checkout/sessions/{$id}");
        if (! $res->successful()) {
            throw new RuntimeException('Stripe: ' . ($res->json('error.message') ?? "could not read session {$id}"));
        }
        return $res->json();
    }

    private function result(OnlinePayment $payment, array $session): GatewayResult
    {
        $raw = array_intersect_key($session, array_flip(['id', 'status', 'payment_status', 'amount_total', 'currency', 'payment_intent', 'client_reference_id']));
        if (($session['client_reference_id'] ?? null) !== $payment->ref) {
            return GatewayResult::failed('Stripe session belongs to another payment', $raw);
        }
        $status = $session['status'] ?? null;
        $paid = $session['payment_status'] ?? null;

        if ($status === 'complete' && in_array($paid, ['paid', 'no_payment_required'], true)) {
            $currency = strtoupper((string) ($session['currency'] ?? ''));
            if ($currency !== Money::code()) {
                return GatewayResult::failed("Stripe charged {$currency}, the company bills in " . Money::code(), $raw);
            }
            $intent = $session['payment_intent'] ?? null;
            $trx = is_array($intent) ? ($intent['id'] ?? $session['id']) : ($intent ?: $session['id']);
            return GatewayResult::completed((string) $trx, self::fromMinor((int) ($session['amount_total'] ?? 0), $currency), $raw);
        }
        if ($status === 'complete') {
            return GatewayResult::pending('Payment is processing at Stripe', $raw); // e.g. a bank debit settles in days
        }
        if ($status === 'expired') {
            return GatewayResult::failed('Stripe checkout expired', $raw);
        }
        return GatewayResult::pending('Checkout not finished yet', $raw);
    }

    // Checks the Stripe-Signature header (t=timestamp,v1=HMAC-SHA256 of "t.body" with the
    // webhook secret) and returns the decoded event, or null when it isn't genuine or is stale.
    public static function verifyWebhook(string $payload, ?string $header, ?string $secret, ?int $now = null): ?array
    {
        if (! $header || ! $secret) {
            return null;
        }
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($k === 't') {
                $timestamp = (int) $v;
            } elseif ($k === 'v1' && $v) {
                $signatures[] = $v;
            }
        }
        if (! $timestamp || ! $signatures || abs(($now ?? now()->getTimestamp()) - $timestamp) > self::WEBHOOK_TOLERANCE) {
            return null;
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                $event = json_decode($payload, true);
                return is_array($event) && isset($event['id'], $event['type']) ? $event : null;
            }
        }
        return null;
    }
}
