<?php

namespace App\Http\Controllers;

use App\Services\Isp\OnlinePaymentService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

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
            $payment = OnlinePaymentService::handleCallback('sslcommerz', $ref, $request->all());
            return response()->json(['status' => $payment->status]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'unknown'], 404);
        }
    }
}
