<?php

namespace App\Services\Isp\Payments;

use App\Models\Customer;
use App\Models\OnlinePayment;
use App\Models\PaymentGateway;
use Illuminate\Support\Facades\Http;
use RuntimeException;

// SSLCommerz hosted checkout (cards, internet banking and mobile wallets).
class SslcommerzDriver implements GatewayDriver
{
    public function __construct(private PaymentGateway $gateway)
    {
    }

    private function host(): string
    {
        return $this->gateway->sandbox ? 'https://sandbox.sslcommerz.com' : 'https://securepay.sslcommerz.com';
    }

    public function initiate(OnlinePayment $payment, Customer $customer, string $callbackUrl): string
    {
        $res = Http::timeout(30)->asForm()->post($this->host() . '/gwprocess/v4/api.php', [
            'store_id' => $this->gateway->credential('store_id'),
            'store_passwd' => $this->gateway->credential('store_password'),
            'total_amount' => number_format((float) $payment->amount, 2, '.', ''),
            'currency' => 'BDT',
            'tran_id' => $payment->ref,
            'success_url' => $callbackUrl,
            'fail_url' => $callbackUrl,
            'cancel_url' => $callbackUrl,
            'ipn_url' => url('/api/payment/ipn/sslcommerz'),
            'cus_name' => $customer->name,
            'cus_email' => $customer->email ?: 'customer@example.com',
            'cus_add1' => $customer->address ?: 'N/A',
            'cus_city' => 'Dhaka',
            'cus_country' => 'Bangladesh',
            'cus_phone' => $customer->phone ?: '01700000000',
            'shipping_method' => 'NO',
            'product_name' => $payment->purpose === 'wallet' ? 'Wallet top-up' : 'Internet bill',
            'product_category' => 'Internet',
            'product_profile' => 'non-physical-goods',
            'value_a' => $payment->ref,
        ]);
        $data = $res->json() ?? [];
        $payment->logPayload('init', array_diff_key($data, array_flip(['storeBanner', 'storeLogo', 'desc', 'gw'])));
        if (($data['status'] ?? null) !== 'SUCCESS' || empty($data['GatewayPageURL'])) {
            throw new RuntimeException('SSLCommerz: ' . ($data['failedreason'] ?? 'could not start the payment'));
        }
        $payment->gateway_payment_id = $data['sessionkey'] ?? null;
        return $data['GatewayPageURL'];
    }

    public function verify(OnlinePayment $payment, array $input): GatewayResult
    {
        $status = strtoupper((string) ($input['status'] ?? ''));
        if (($input['tran_id'] ?? null) !== $payment->ref) {
            return GatewayResult::failed('Transaction ID mismatch', $input);
        }
        if ($status === 'CANCELLED') {
            return GatewayResult::failed('Cancelled by customer', $input, 'cancelled');
        }
        if (empty($input['val_id'])) {
            return GatewayResult::failed('Payment ' . strtolower($status ?: 'failed'), $input);
        }

        $res = Http::timeout(30)->get($this->host() . '/validator/api/validationserverAPI.php', [
            'val_id' => $input['val_id'],
            'store_id' => $this->gateway->credential('store_id'),
            'store_passwd' => $this->gateway->credential('store_password'),
            'format' => 'json',
        ]);
        $data = $res->json() ?? [];
        $valid = in_array($data['status'] ?? null, ['VALID', 'VALIDATED'], true)
            && ($data['tran_id'] ?? null) === $payment->ref
            && ($data['currency_type'] ?? $data['currency'] ?? 'BDT') === 'BDT';
        if ($valid) {
            $trx = $data['bank_tran_id'] ?? $data['val_id'];
            return GatewayResult::completed((string) $trx, (float) ($data['currency_amount'] ?? $data['amount']), $data);
        }
        return GatewayResult::failed('SSLCommerz validation: ' . ($data['status'] ?? 'failed'), $data);
    }
}
