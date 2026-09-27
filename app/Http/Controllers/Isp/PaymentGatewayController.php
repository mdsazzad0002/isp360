<?php

namespace App\Http\Controllers\Isp;

use App\Support\Money;
use App\Models\Bank;
use App\Models\PaymentGateway;
use App\Services\Isp\AuditLogger;
use App\Services\Isp\OnlinePaymentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// Admin setup of the customer payment methods (bKash, Nagad, Rocket, SSLCommerz, Stripe).
class PaymentGatewayController extends IspController
{
    public function create()
    {
        return $this->page('paymentGateway', 'Isp/PaymentGateway', [
            'ipnUrl' => url('/api/payment/ipn/sslcommerz'),
        ]);
    }

    public function index()
    {
        $rows = PaymentGateway::where('branch_id', $this->branchId)->get()->keyBy('gateway');

        $currency = Money::code();
        $gateways = collect(PaymentGateway::GATEWAYS)->map(function ($meta, $key) use ($rows, $currency) {
            $row = $rows->get($key);
            // secrets are never sent back: the form shows whether one is saved
            $credentials = [];
            foreach ($meta['fields'] as $field => $label) {
                $value = $row?->credential($field);
                $credentials[$field] = in_array($field, $meta['secret'], true) ? '' : ($value ?? '');
                $credentials["has_{$field}"] = (bool) $value;
            }
            return [
                'gateway' => $key,
                'label' => $meta['label'],
                'modes' => $meta['modes'],
                'fields' => $meta['fields'],
                'secret' => $meta['secret'],
                'is_active' => (bool) $row?->is_active,
                'mode' => $row?->mode ?? $meta['modes'][0],
                'sandbox' => $row ? (bool) $row->sandbox : true,
                'credentials' => $credentials,
                'manual_number' => $row?->manual_number ?? '',
                'manual_account_type' => $row?->manual_account_type ?? 'personal',
                'instructions' => $row?->instructions ?? '',
                'bank_id' => $row?->bank_id,
                'min_amount' => $row ? (float) $row->min_amount : 10,
                'max_amount' => $row ? (float) $row->max_amount : 50000,
                'sort' => $row?->sort ?? 0,
                'usable' => $row ? OnlinePaymentService::isUsable($row) : false,
                'currencies' => $meta['currencies'],
                'currency_ok' => PaymentGateway::supportsCurrency($key, $currency),
                // Stripe: the endpoint to add in the Stripe dashboard (Developers > Webhooks), once saved
                'webhook_url' => $key === 'stripe' && $row ? url("/api/payment/webhook/stripe/{$row->id}") : null,
            ];
        })->values();

        return response()->json([
            'gateways' => $gateways,
            'banks' => Bank::where('branch_id', $this->branchId)->where('status', 'a')->get(['id', 'name', 'number', 'bank_name', 'type']),
        ]);
    }

    public function store(Request $request)
    {
        if ($r = $this->deny('paymentGateway')) return $r;
        $key = $request->gateway;
        $meta = PaymentGateway::GATEWAYS[$key] ?? null;
        if (! $meta) {
            return send_error('Unknown payment gateway', null, 422);
        }
        if ($r = $this->validateOrFail($request->all(), [
            'is_active' => 'boolean',
            'mode' => ['required', Rule::in($meta['modes'])],
            'sandbox' => 'boolean',
            'credentials' => 'nullable|array',
            'manual_number' => ['nullable', 'regex:/^01[3-9][0-9]{8,9}$/'],
            'manual_account_type' => 'nullable|in:personal,agent,merchant',
            'instructions' => 'nullable|max:1000',
            'bank_id' => ['nullable', 'integer', Rule::exists('banks', 'id')->where('branch_id', $this->branchId)],
            'min_amount' => 'required|numeric|min:1',
            'max_amount' => 'required|numeric|gt:min_amount|max:500000',
            'sort' => 'nullable|integer|min:0|max:99',
        ], ['manual_number.regex' => 'Enter a valid Bangladeshi mobile number, e.g. 01712345678'])) return $r;

        $gateway = PaymentGateway::firstOrNew(['branch_id' => $this->branchId, 'gateway' => $key]);

        // blank secret field = keep the saved value
        $credentials = $gateway->credentials ?? [];
        foreach (array_keys($meta['fields']) as $field) {
            $value = trim((string) data_get($request->credentials, $field, ''));
            if ($value !== '' || ! in_array($field, $meta['secret'], true)) {
                $credentials[$field] = $value;
            }
        }

        $gateway->fill([
            'is_active' => $request->boolean('is_active'),
            'mode' => $request->mode,
            'sandbox' => $request->boolean('sandbox'),
            'credentials' => $credentials,
            'manual_number' => $request->manual_number ?: null,
            'manual_account_type' => $request->manual_account_type ?: null,
            'instructions' => $request->instructions ?: null,
            'bank_id' => $request->bank_id ?: null,
            'min_amount' => $request->min_amount,
            'max_amount' => $request->max_amount,
            'sort' => (int) $request->sort,
            'updated_by' => $this->userId,
        ]);

        // Stripe test keys only in sandbox mode and live keys only outside it, so a test setup can't take real money unnoticed
        if ($key === 'stripe' && ($secretKey = $gateway->credential('secret_key'))
            && ! preg_match($gateway->sandbox ? '/^(sk|rk)_test_/' : '/^(sk|rk)_live_/', $secretKey)) {
            return send_error($gateway->sandbox ? 'Sandbox mode needs a Stripe test key (sk_test_… or rk_test_…).' : 'Live mode needs a Stripe live key (sk_live_… or rk_live_…).', null, 422);
        }
        if ($gateway->is_active && ! PaymentGateway::supportsCurrency($key, $currency = Money::code())) {
            return send_error("{$meta['label']} only takes " . implode(', ', $meta['currencies']) . "; the company bills in {$currency}.", null, 422);
        }
        if ($gateway->is_active) {
            $missing = [];
            if (! $gateway->bank_id) {
                $missing[] = 'receiving account';
            }
            if ($gateway->mode === 'manual' && ! $gateway->manual_number) {
                $missing[] = "{$meta['label']} number";
            }
            if ($gateway->mode === 'api') {
                foreach ($meta['fields'] as $field => $label) {
                    if (! $gateway->credential($field)) {
                        $missing[] = $label;
                    }
                }
            }
            if ($missing) {
                return send_error('To turn it on, fill in: ' . implode(', ', $missing), null, 422);
            }
        }

        $isNew = ! $gateway->exists;
        $gateway->save();
        AuditLogger::log($isNew ? 'payment_gateway.created' : 'payment_gateway.updated', $gateway, null,
            $gateway->only(['gateway', 'is_active', 'mode', 'sandbox', 'bank_id', 'manual_number', 'min_amount', 'max_amount']));

        return $this->ok("{$meta['label']} settings saved.");
    }
}
