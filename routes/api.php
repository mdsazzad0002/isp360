<?php

use App\Http\Controllers\PaymentCallbackController;
use Illuminate\Support\Facades\Route;

// online payment gateways return here (see PaymentCallbackController for why this is not in web.php)
Route::match(['get', 'post'], '/payment/callback/{gateway}/{ref}', [PaymentCallbackController::class, 'callback'])
    ->where(['gateway' => 'bkash|nagad|sslcommerz|stripe|paypal', 'ref' => '[A-Z0-9]{6,30}'])->name('payment.callback');
Route::post('/payment/ipn/sslcommerz', [PaymentCallbackController::class, 'sslcommerzIpn'])->name('payment.ipn.sslcommerz');
Route::post('/payment/webhook/paypal/{id}', [PaymentCallbackController::class, 'paypalWebhook'])->whereNumber('id')->name('payment.webhook.paypal');
Route::post('/payment/webhook/stripe/{id}', [PaymentCallbackController::class, 'stripeWebhook'])->whereNumber('id')->name('payment.webhook.stripe');
