<?php

namespace App\Services\Isp;

use App\Models\Customer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

// Customer SMS for billing events, through the branch's configured SMS gateways.
// Sending is best-effort: a gateway failure never rolls back the financial action,
// which is why callers invoke this after their transaction has committed.
class IspNotifier
{
    public static function send(int $branchId, Customer $customer, string $event, array $vars = []): void
    {
        $settings = IspSettings::all($branchId);
        if (empty($settings['sms_' . $event]) || empty($customer->phone)) {
            return;
        }
        $vars = array_merge([
            'name' => $customer->name,
            'code' => $customer->code,
            'balance' => number_format(max(0, LedgerService::balance($customer->id)), 2),
        ], $vars);
        $message = preg_replace_callback('/\{(\w+)\}/', fn ($m) => $vars[$m[1]] ?? $m[0], $settings['sms_tpl_' . $event] ?? '');

        try {
            sendTransactionalSms($branchId, Auth::guard('web')->id(), $customer->phone, $message, $customer->id, 'isp_' . $event);
        } catch (\Throwable $e) {
            Log::warning('ISP SMS failed', ['event' => $event, 'customer' => $customer->id, 'error' => $e->getMessage()]);
        }
    }
}
