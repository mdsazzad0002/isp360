<?php

namespace App\Services\Isp\Payments;

use App\Models\Customer;
use App\Models\OnlinePayment;
use App\Models\PaymentGateway;

interface GatewayDriver
{
    public function __construct(PaymentGateway $gateway);

    // Starts a checkout at the gateway and returns the URL to send the customer to.
    public function initiate(OnlinePayment $payment, Customer $customer, string $callbackUrl): string;

    // Confirms the payment with the gateway (server to server) using what the gateway
    // sent back to the callback URL. Never trust the callback parameters alone.
    public function verify(OnlinePayment $payment, array $input): GatewayResult;
}
