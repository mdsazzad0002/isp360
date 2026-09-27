<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\OnlinePayment;
use App\Models\PaymentGateway;
use App\Services\Isp\OnlinePaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

// Customer portal: pay bills and top up the wallet with bKash / Nagad / Rocket / SSLCommerz.
class CustomerPortalPaymentController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:customer');
    }

    public function page(Request $request)
    {
        $customer = Auth::guard('customer')->user();

        $gateways = OnlinePaymentService::available($customer->branch_id)->map(fn (PaymentGateway $g) => [
            'gateway' => $g->gateway,
            'label' => $g->label(),
            'mode' => $g->mode,
            'manual_number' => $g->mode === 'manual' ? $g->manual_number : null,
            'manual_account_type' => $g->mode === 'manual' ? $g->manual_account_type : null,
            'instructions' => $g->instructions,
            'min_amount' => (float) $g->min_amount,
            'max_amount' => (float) $g->max_amount,
        ]);

        $result = $request->ref
            ? OnlinePayment::where('customer_id', $customer->id)->where('ref', $request->ref)->first(['ref', 'gateway', 'amount', 'status', 'trx_id', 'failure_reason', 'purpose'])
            : null;

        return \Inertia\Inertia::render('CustomerPortal/Pay', [
            'customer' => $customer->only(['id', 'code', 'name']),
            'summary' => OnlinePaymentService::summary($customer->id),
            'gateways' => $gateways,
            'invoices' => Invoice::where('customer_id', $customer->id)->whereIn('status', Invoice::OPEN_STATUSES)->where('due', '>', 0)
                ->orderBy('due_date')->get(['id', 'invoice_no', 'due_date', 'total', 'due']),
            'history' => OnlinePayment::where('customer_id', $customer->id)->latest('id')->limit(20)
                ->get(['id', 'ref', 'gateway', 'mode', 'purpose', 'amount', 'status', 'trx_id', 'failure_reason', 'created_at']),
            'result' => $result,
            'purpose' => $request->purpose === 'wallet' ? 'wallet' : 'bill',
        ]);
    }

    public function start(Request $request)
    {
        $customer = Auth::guard('customer')->user();
        $validator = Validator::make($request->all(), [
            'gateway' => 'required|in:' . implode(',', array_keys(OnlinePaymentService::DRIVERS)),
            'amount' => 'required|numeric|min:1',
            'purpose' => 'required|in:bill,wallet',
        ]);
        if ($validator->fails()) return send_error('Validation Error', $validator->errors(), 422);

        try {
            $url = OnlinePaymentService::start($customer, $request->gateway, (float) $request->amount, $request->purpose,
                fn (OnlinePayment $p) => url("/api/payment/callback/{$p->gateway}/{$p->ref}"));
            return response()->json(['status' => true, 'redirect' => $url]);
        } catch (\RuntimeException $e) {
            return send_error($e->getMessage(), null, 422);
        } catch (\Throwable $e) {
            Log::error($e);
            return send_error('Could not start the payment. Please try again.');
        }
    }

    public function manual(Request $request)
    {
        $customer = Auth::guard('customer')->user();
        $validator = Validator::make($request->all(), [
            'gateway' => 'required|in:' . implode(',', array_keys(PaymentGateway::GATEWAYS)),
            'amount' => 'required|numeric|min:1',
            'purpose' => 'required|in:bill,wallet',
            'trx_id' => ['required', 'regex:/^[A-Za-z0-9]{6,30}$/'],
            'sender_number' => ['required', 'regex:/^01[3-9][0-9]{8,9}$/'],
        ], [
            'trx_id.regex' => 'Enter the Transaction ID exactly as shown in your SMS / app (letters and numbers only).',
            'sender_number.regex' => 'Enter the mobile number you sent the money from, e.g. 01712345678',
        ]);
        if ($validator->fails()) return send_error('Validation Error', $validator->errors(), 422);

        try {
            $payment = OnlinePaymentService::submitManual($customer, $request->gateway, (float) $request->amount, $request->purpose, $request->trx_id, $request->sender_number);
            return response()->json(['status' => true, 'message' => 'Payment submitted. It will be added to your account after verification.', 'ref' => $payment->ref]);
        } catch (\RuntimeException $e) {
            return send_error($e->getMessage(), null, 422);
        }
    }
}
