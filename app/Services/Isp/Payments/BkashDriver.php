<?php

namespace App\Services\Isp\Payments;

use App\Models\Customer;
use App\Models\OnlinePayment;
use App\Models\PaymentGateway;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

// bKash Tokenized Checkout (v1.2.0-beta): grant token -> create -> customer pays -> execute.
class BkashDriver implements GatewayDriver
{
    public function __construct(private PaymentGateway $gateway)
    {
    }

    private function base(): string
    {
        return $this->gateway->sandbox
            ? 'https://tokenized.sandbox.bka.sh/v1.2.0-beta/tokenized/checkout'
            : 'https://tokenized.pay.bka.sh/v1.2.0-beta/tokenized/checkout';
    }

    // id_token is valid for an hour; cache it a little shorter.
    private function token(): string
    {
        $key = "bkash_token_{$this->gateway->id}_" . md5($this->gateway->credential('app_key') . (int) $this->gateway->sandbox);
        return Cache::remember($key, 50 * 60, function () {
            $res = Http::timeout(30)->acceptJson()->withHeaders([
                'username' => $this->gateway->credential('username'),
                'password' => $this->gateway->credential('password'),
            ])->post($this->base() . '/token/grant', [
                'app_key' => $this->gateway->credential('app_key'),
                'app_secret' => $this->gateway->credential('app_secret'),
            ]);
            $token = $res->json('id_token');
            if (! $res->successful() || ! $token) {
                throw new RuntimeException('bKash authentication failed: ' . ($res->json('statusMessage') ?? $res->json('msg') ?? 'HTTP ' . $res->status()));
            }
            return $token;
        });
    }

    private function call(string $path, array $body): array
    {
        $res = Http::timeout(30)->acceptJson()->withHeaders([
            'Authorization' => $this->token(),
            'X-APP-Key' => $this->gateway->credential('app_key'),
        ])->post($this->base() . $path, $body);
        return $res->json() ?? ['statusMessage' => 'HTTP ' . $res->status()];
    }

    public function initiate(OnlinePayment $payment, Customer $customer, string $callbackUrl): string
    {
        $data = $this->call('/create', [
            'mode' => '0011',
            'payerReference' => (string) ($customer->phone ?: $customer->code),
            'callbackURL' => $callbackUrl,
            'amount' => number_format((float) $payment->amount, 2, '.', ''),
            'currency' => 'BDT',
            'intent' => 'sale',
            'merchantInvoiceNumber' => $payment->ref,
        ]);
        $payment->logPayload('create', $data);
        if (empty($data['paymentID']) || empty($data['bkashURL'])) {
            throw new RuntimeException('bKash: ' . ($data['statusMessage'] ?? 'could not start the payment'));
        }
        $payment->gateway_payment_id = $data['paymentID'];
        return $data['bkashURL'];
    }

    public function verify(OnlinePayment $payment, array $input): GatewayResult
    {
        $status = strtolower((string) ($input['status'] ?? ''));
        if (($input['paymentID'] ?? null) !== $payment->gateway_payment_id) {
            return GatewayResult::failed('Payment ID mismatch', $input);
        }
        if ($status === 'cancel') {
            return GatewayResult::failed('Cancelled by customer', $input, 'cancelled');
        }
        if ($status !== 'success') {
            return GatewayResult::failed('Payment ' . ($status ?: 'failed'), $input);
        }

        $data = $this->call('/execute', ['paymentID' => $payment->gateway_payment_id]);
        // execute can time out after bKash already completed it; ask for the status then
        if (($data['transactionStatus'] ?? null) !== 'Completed') {
            $query = $this->call('/payment/status', ['paymentID' => $payment->gateway_payment_id]);
            if (($query['transactionStatus'] ?? null) === 'Completed') {
                $data = $query;
            }
        }
        if (($data['transactionStatus'] ?? null) === 'Completed' && ! empty($data['trxID'])) {
            return GatewayResult::completed($data['trxID'], (float) $data['amount'], $data);
        }
        return GatewayResult::failed('bKash: ' . ($data['statusMessage'] ?? $data['transactionStatus'] ?? 'not completed'), $data);
    }
}
