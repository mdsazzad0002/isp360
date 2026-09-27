<?php

namespace App\Services\Isp\Payments;

use App\Models\Customer;
use App\Models\OnlinePayment;
use App\Models\PaymentGateway;
use App\Support\Money;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

// PayPal Checkout (Orders v2): the customer approves on PayPal's page, then the order is captured
// server to server here. The result is always read from PayPal's API with the client secret; the
// return URL and webhook bodies only say which order to look at.
class PaypalDriver implements GatewayDriver
{
    // PayPal presentment currencies that the app also bills in (JPY without decimals, as at PayPal).
    public const CURRENCIES = ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'MYR', 'SGD', 'PHP', 'THB', 'BRL', 'MXN', 'JPY'];

    public function __construct(private PaymentGateway $gateway)
    {
    }

    public function host(): string
    {
        return $this->gateway->sandbox ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
    }

    // OAuth access token (client credentials), cached until shortly before it expires.
    public function token(): string
    {
        $key = 'paypal-token:' . $this->gateway->id . ':' . md5((string) $this->gateway->credential('client_id'));
        if ($token = Cache::get($key)) {
            return $token;
        }
        $res = Http::timeout(30)->asForm()->withBasicAuth((string) $this->gateway->credential('client_id'), (string) $this->gateway->credential('client_secret'))
            ->post($this->host() . '/v1/oauth2/token', ['grant_type' => 'client_credentials']);
        if (! $res->successful() || ! $res->json('access_token')) {
            throw new RuntimeException('PayPal: ' . ($res->json('error_description') ?? 'could not log in with the client ID and secret'));
        }
        Cache::put($key, $res->json('access_token'), max(60, (int) $res->json('expires_in', 3600) - 120));
        return $res->json('access_token');
    }

    private function http(): PendingRequest
    {
        return Http::timeout(30)->withToken($this->token())->acceptJson()->asJson();
    }

    public static function formatAmount(float $amount, string $currency): string
    {
        return number_format($amount, $currency === 'JPY' ? 0 : 2, '.', '');
    }

    public function initiate(OnlinePayment $payment, Customer $customer, string $callbackUrl): string
    {
        $currency = Money::code();
        if (! in_array($currency, self::CURRENCIES, true)) {
            throw new RuntimeException("PayPal does not take {$currency}.");
        }
        $sep = str_contains($callbackUrl, '?') ? '&' : '?';
        $res = $this->http()
            ->withHeaders(['PayPal-Request-Id' => 'order-' . $payment->ref]) // retried create = the same order
            ->post($this->host() . '/v2/checkout/orders', [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'reference_id' => $payment->ref,
                    'custom_id' => $payment->ref,
                    'invoice_id' => $payment->ref,
                    'description' => ($payment->purpose === 'wallet' ? 'Wallet top-up' : 'Internet bill') . " ({$customer->code})",
                    'amount' => ['currency_code' => $currency, 'value' => self::formatAmount((float) $payment->amount, $currency)],
                ]],
                'payment_source' => ['paypal' => ['experience_context' => [
                    'shipping_preference' => 'NO_SHIPPING',
                    'user_action' => 'PAY_NOW',
                    'return_url' => $callbackUrl,
                    'cancel_url' => $callbackUrl . $sep . 'cancelled=1',
                ]]],
            ]);
        $data = $res->json() ?? [];
        $payment->logPayload('init', array_intersect_key($data, array_flip(['id', 'status', 'name', 'message', 'debug_id'])));
        $approve = collect($data['links'] ?? [])->first(fn ($l) => in_array($l['rel'] ?? '', ['payer-action', 'approve'], true))['href'] ?? null;
        if (! $res->successful() || empty($data['id']) || ! $approve) {
            throw new RuntimeException('PayPal: ' . ($data['message'] ?? 'could not start the payment'));
        }
        $payment->gateway_payment_id = $data['id'];
        return $approve;
    }

    public function verify(OnlinePayment $payment, array $input): GatewayResult
    {
        $orderId = $payment->gateway_payment_id;
        if (! $orderId) {
            return GatewayResult::failed('No PayPal order for this payment', $input);
        }
        if (! empty($input['token']) && $input['token'] !== $orderId) {
            return GatewayResult::failed('PayPal order mismatch', $input);
        }

        $order = $this->order($orderId);
        if (($order['status'] ?? null) === 'APPROVED') {
            // the customer approved: take the money now (idempotent per payment)
            $res = $this->http()->withHeaders(['PayPal-Request-Id' => 'capture-' . $payment->ref])
                ->post($this->host() . "/v2/checkout/orders/{$orderId}/capture", (object) []);
            $order = $res->json() ?? [];
            if (! $res->successful() && ($order['details'][0]['issue'] ?? null) !== 'ORDER_ALREADY_CAPTURED') {
                return GatewayResult::failed('PayPal capture: ' . ($order['details'][0]['description'] ?? $order['message'] ?? 'failed'), $this->raw($order));
            }
            if (! $res->successful()) {
                $order = $this->order($orderId);
            }
        }
        if (! empty($input['cancelled']) && in_array($order['status'] ?? null, ['CREATED', 'PAYER_ACTION_REQUIRED'], true)) {
            return GatewayResult::failed('Cancelled by customer', $this->raw($order), 'cancelled');
        }
        return $this->result($payment, $order);
    }

    private function order(string $id): array
    {
        $res = $this->http()->get($this->host() . "/v2/checkout/orders/{$id}");
        if (! $res->successful()) {
            throw new RuntimeException('PayPal: ' . ($res->json('message') ?? "could not read order {$id}"));
        }
        return $res->json();
    }

    private function raw(array $order): array
    {
        return ['id' => $order['id'] ?? null, 'status' => $order['status'] ?? null, 'capture' => $order['purchase_units'][0]['payments']['captures'][0] ?? null, 'debug_id' => $order['debug_id'] ?? null];
    }

    private function result(OnlinePayment $payment, array $order): GatewayResult
    {
        $raw = $this->raw($order);
        $unit = $order['purchase_units'][0] ?? [];
        if (($unit['reference_id'] ?? $payment->ref) !== $payment->ref) {
            return GatewayResult::failed('PayPal order belongs to another payment', $raw);
        }
        $capture = $unit['payments']['captures'][0] ?? null;
        $status = $order['status'] ?? null;

        if ($capture) {
            $currency = $capture['amount']['currency_code'] ?? '';
            if ($currency !== Money::code()) {
                return GatewayResult::failed("PayPal charged {$currency}, the company bills in " . Money::code(), $raw);
            }
            return match ($capture['status'] ?? null) {
                'COMPLETED' => GatewayResult::completed((string) $capture['id'], (float) $capture['amount']['value'], $raw),
                'PENDING' => GatewayResult::pending('PayPal is holding the payment: ' . ($capture['status_details']['reason'] ?? 'pending'), $raw),
                default => GatewayResult::failed('PayPal capture ' . strtolower((string) ($capture['status'] ?? 'failed')), $raw),
            };
        }
        if ($status === 'VOIDED') {
            return GatewayResult::failed('PayPal order voided', $raw);
        }
        return GatewayResult::pending('Order not approved yet', $raw);
    }

    // Asks PayPal whether a webhook is genuine (its signature, for this gateway's webhook id).
    public function verifyWebhook(array $headers, array $event): bool
    {
        $webhookId = $this->gateway->credential('webhook_id');
        if (! $webhookId) {
            return false;
        }
        $res = $this->http()->post($this->host() . '/v1/notifications/verify-webhook-signature', [
            'auth_algo' => $headers['paypal-auth-algo'] ?? '',
            'cert_url' => $headers['paypal-cert-url'] ?? '',
            'transmission_id' => $headers['paypal-transmission-id'] ?? '',
            'transmission_sig' => $headers['paypal-transmission-sig'] ?? '',
            'transmission_time' => $headers['paypal-transmission-time'] ?? '',
            'webhook_id' => $webhookId,
            'webhook_event' => $event,
        ]);
        return $res->successful() && $res->json('verification_status') === 'SUCCESS';
    }
}
