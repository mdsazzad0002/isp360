<?php

namespace App\Services\Isp\Payments;

use App\Models\Customer;
use App\Models\OnlinePayment;
use App\Models\PaymentGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

// Nagad merchant checkout API (v-0.2.0): initialize -> complete -> customer pays -> verify.
// Request data is RSA-encrypted with Nagad's public key and signed with the merchant's private key.
class NagadDriver implements GatewayDriver
{
    public function __construct(private PaymentGateway $gateway)
    {
    }

    private function base(): string
    {
        return $this->gateway->sandbox
            ? 'http://sandbox.mynagad.com:10080/remote-payment-gateway-1.0/api/dfs'
            : 'https://api.mynagad.com/api/dfs';
    }

    private static function pem(string $key, string $type): string
    {
        $key = trim($key);
        if (str_contains($key, '-----BEGIN')) {
            return $key;
        }
        return "-----BEGIN {$type}-----\n" . chunk_split(preg_replace('/\s+/', '', $key), 64, "\n") . "-----END {$type}-----";
    }

    private function encrypt(array $data): string
    {
        $key = openssl_pkey_get_public(self::pem($this->gateway->credential('nagad_public_key'), 'PUBLIC KEY'));
        if (! $key || ! openssl_public_encrypt(json_encode($data), $encrypted, $key)) {
            throw new RuntimeException('Nagad: invalid Nagad public key');
        }
        return base64_encode($encrypted);
    }

    private function sign(array $data): string
    {
        $key = openssl_pkey_get_private(self::pem($this->gateway->credential('merchant_private_key'), 'PRIVATE KEY'));
        if (! $key || ! openssl_sign(json_encode($data), $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Nagad: invalid merchant private key');
        }
        return base64_encode($signature);
    }

    private function decrypt(string $data): array
    {
        $key = openssl_pkey_get_private(self::pem($this->gateway->credential('merchant_private_key'), 'PRIVATE KEY'));
        if (! $key || ! openssl_private_decrypt(base64_decode($data), $plain, $key)) {
            throw new RuntimeException('Nagad: could not read the gateway response');
        }
        return json_decode($plain, true) ?? [];
    }

    private function headers(): array
    {
        return [
            'X-KM-IP-V4' => request()->ip() ?? '127.0.0.1',
            'X-KM-Client-Type' => 'PC_WEB',
            'X-KM-Api-Version' => 'v-0.2.0',
        ];
    }

    public function initiate(OnlinePayment $payment, Customer $customer, string $callbackUrl): string
    {
        $merchantId = $this->gateway->credential('merchant_id');
        $orderId = $payment->ref;

        $sensitive = [
            'merchantId' => $merchantId,
            'datetime' => now('Asia/Dhaka')->format('YmdHis'),
            'orderId' => $orderId,
            'challenge' => Str::random(40),
        ];
        $init = Http::timeout(30)->acceptJson()->withHeaders($this->headers())
            ->post($this->base() . "/check-out/initialize/{$merchantId}/{$orderId}?locale=EN", [
                'accountNumber' => $this->gateway->credential('merchant_number'),
                'dateTime' => $sensitive['datetime'],
                'sensitiveData' => $this->encrypt($sensitive),
                'signature' => $this->sign($sensitive),
            ])->json() ?? [];
        $payment->logPayload('initialize', array_diff_key($init, ['sensitiveData' => 1, 'signature' => 1]));
        if (empty($init['sensitiveData'])) {
            throw new RuntimeException('Nagad: ' . ($init['message'] ?? 'could not start the payment'));
        }
        $session = $this->decrypt($init['sensitiveData']);
        if (empty($session['paymentReferenceId'])) {
            throw new RuntimeException('Nagad: no payment reference returned');
        }

        $order = [
            'merchantId' => $merchantId,
            'orderId' => $orderId,
            'currencyCode' => '050',
            'amount' => number_format((float) $payment->amount, 2, '.', ''),
            'challenge' => $session['challenge'] ?? '',
        ];
        $complete = Http::timeout(30)->acceptJson()->withHeaders($this->headers())
            ->post($this->base() . '/check-out/complete/' . $session['paymentReferenceId'], [
                'sensitiveData' => $this->encrypt($order),
                'signature' => $this->sign($order),
                'merchantCallbackURL' => $callbackUrl,
                'additionalMerchantInfo' => ['purpose' => $payment->purpose],
            ])->json() ?? [];
        $payment->logPayload('complete', $complete);
        if (($complete['status'] ?? null) !== 'Success' || empty($complete['callBackUrl'])) {
            throw new RuntimeException('Nagad: ' . ($complete['message'] ?? 'could not start the payment'));
        }
        $payment->gateway_payment_id = $session['paymentReferenceId'];
        return $complete['callBackUrl'];
    }

    public function verify(OnlinePayment $payment, array $input): GatewayResult
    {
        $ref = $input['payment_ref_id'] ?? null;
        if (! $ref || $ref !== $payment->gateway_payment_id || ($input['order_id'] ?? null) !== $payment->ref) {
            return GatewayResult::failed('Payment reference mismatch', $input);
        }
        if (strtolower((string) ($input['status'] ?? '')) === 'aborted') {
            return GatewayResult::failed('Cancelled by customer', $input, 'cancelled');
        }

        $data = Http::timeout(30)->acceptJson()->withHeaders($this->headers())
            ->get($this->base() . '/verify/payment/' . $ref)->json() ?? [];
        if (($data['status'] ?? null) === 'Success' && ($data['orderId'] ?? null) === $payment->ref) {
            return GatewayResult::completed((string) ($data['issuerPaymentRefNo'] ?? $ref), (float) $data['amount'], $data);
        }
        return GatewayResult::failed('Nagad: ' . ($data['message'] ?? $data['status'] ?? 'not completed'), $data);
    }
}
