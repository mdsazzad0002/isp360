<?php

namespace App\Http\Controllers;

use App\Models\GatewayEvent;
use App\Models\PaymentGateway;
use App\Services\Isp\OnlinePaymentService;
use App\Services\Isp\Payments\StripeDriver;
use App\Services\Isp\Payments\PaypalDriver;
use App\Models\OnlinePayment;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

// Where the gateways send the customer back (and SSLCommerz its IPN). These routes live in
// routes/api.php: no session or CSRF, so a cross-site POST from the gateway can't replace
// the customer's session cookie. Every result is verified with the gateway server to server.
class PaymentCallbackController extends Controller
{
    public function callback(Request $request, string $gateway, string $ref)
    {
        try {
            OnlinePaymentService::handleCallback($gateway, $ref, $request->all());
        } catch (ModelNotFoundException $e) {
            abort(404);
        }
        return redirect('/customer-portal/pay?ref=' . urlencode($ref));
    }

    public function sslcommerzIpn(Request $request)
    {
        $ref = (string) $request->input('tran_id');
        try {
            // an IPN seen before (same val_id) is acknowledged without touching the payment again
            if ($request->filled('val_id') && GatewayEvent::where('gateway', 'sslcommerz')->where('event_id', $request->val_id)->whereNotNull('processed_at')->exists()) {
                return response()->json(['status' => 'duplicate']);
            }
            $payment = OnlinePaymentService::handleCallback('sslcommerz', $ref, $request->all());
            if ($request->filled('val_id')) {
                GatewayEvent::updateOrCreate(['gateway' => 'sslcommerz', 'event_id' => (string) $request->val_id],
                    ['type' => 'ipn', 'online_payment_id' => $payment->id, 'processed_at' => now(), 'result' => $payment->status]);
            }
            return response()->json(['status' => $payment->status]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'unknown'], 404);
        }
    }

    // PayPal webhook, one URL per configured gateway (its webhook id lives on that row). PayPal is
    // asked whether the event is genuine; the payment's state is then read from PayPal as usual.
    public function paypalWebhook(Request $request, int $id)
    {
        $gateway = PaymentGateway::where('id', $id)->where('gateway', 'paypal')->first();
        if (! $gateway) {
            return response()->json(['error' => 'unknown webhook'], 404);
        }
        $event = json_decode($request->getContent(), true);
        if (! is_array($event) || empty($event['id']) || empty($event['event_type'])) {
            return response()->json(['error' => 'invalid event'], 400);
        }
        $headers = collect($request->headers->all())->map(fn ($v) => $v[0] ?? null)->all();
        try {
            if (! (new PaypalDriver($gateway))->verifyWebhook($headers, $event)) {
                Log::warning('PayPal webhook failed verification', ['gateway' => $id, 'ip' => $request->ip()]);
                return response()->json(['error' => 'invalid signature'], 400);
            }
        } catch (\Throwable $e) {
            Log::error($e);
            return response()->json(['error' => 'could not verify, retry'], 500);
        }
        $type = $event['event_type'];
        if (! str_starts_with($type, 'CHECKOUT.ORDER.') && ! str_starts_with($type, 'PAYMENT.CAPTURE.')) {
            return response()->json(['received' => true, 'ignored' => $type]);
        }
        // orders carry our ref on the purchase unit, captures in custom_id or via their order id
        $resource = $event['resource'] ?? [];
        $ref = $resource['custom_id'] ?? data_get($resource, 'purchase_units.0.custom_id') ?? data_get($resource, 'purchase_units.0.reference_id');
        if (! $ref && ($orderId = data_get($resource, 'supplementary_data.related_ids.order_id') ?? (str_starts_with($type, 'CHECKOUT.ORDER.') ? ($resource['id'] ?? null) : null))) {
            $ref = OnlinePayment::where('gateway', 'paypal')->where('gateway_payment_id', $orderId)->value('ref');
        }
        try {
            $done = OnlinePaymentService::handleEvent('paypal', $event['id'], $type, $ref ? (string) $ref : null,
                ['type' => $type, 'resource' => ['id' => $resource['id'] ?? null, 'status' => $resource['status'] ?? null]]);
        } catch (\Throwable $e) {
            Log::error($e);
            $done = false;
        }
        return $done ? response()->json(['received' => true]) : response()->json(['error' => 'not confirmed yet, retry'], 500);
    }

    // Stripe webhook, one URL per configured gateway (its signing secret lives on that row).
    // Answers 400 to anything not signed by Stripe, 200 once the event is handled (or was
    // before), and 500 when the payment could not be confirmed yet, so Stripe sends it again.
    public function stripeWebhook(Request $request, int $id)
    {
        $gateway = PaymentGateway::where('id', $id)->where('gateway', 'stripe')->first();
        if (! $gateway) {
            return response()->json(['error' => 'unknown webhook'], 404);
        }
        $event = StripeDriver::verifyWebhook($request->getContent(), $request->header('Stripe-Signature'), $gateway->credential('webhook_secret'));
        if (! $event) {
            Log::warning('Stripe webhook with a bad signature', ['gateway' => $id, 'ip' => $request->ip()]);
            return response()->json(['error' => 'invalid signature'], 400);
        }
        if (! str_starts_with($event['type'], 'checkout.session.')) {
            return response()->json(['received' => true, 'ignored' => $event['type']]);
        }

        $ref = data_get($event, 'data.object.client_reference_id') ?? data_get($event, 'data.object.metadata.ref');
        try {
            $done = OnlinePaymentService::handleEvent('stripe', $event['id'], $event['type'], $ref ? (string) $ref : null,
                ['type' => $event['type'], 'data' => ['object' => array_intersect_key((array) data_get($event, 'data.object'), array_flip(['id', 'status', 'payment_status', 'client_reference_id', 'amount_total', 'currency']))]]);
        } catch (\Throwable $e) {
            Log::error($e);
            $done = false;
        }
        return $done ? response()->json(['received' => true]) : response()->json(['error' => 'not confirmed yet, retry'], 500);
    }
}
