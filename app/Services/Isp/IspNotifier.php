<?php

namespace App\Services\Isp;

use App\Jobs\SendChannelMessage;
use App\Jobs\SendSms;
use App\Models\MessagingChannel;
use App\Support\Money;
use App\Models\Customer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

// Customer SMS for billing events, through the branch's configured SMS gateways.
// Sending is best-effort: a gateway failure never rolls back the financial action,
// which is why callers invoke this after their transaction has committed. The text is built
// now (balance at this moment); the gateway call runs on the "sms" queue (SendSms).
class IspNotifier
{
    // e-mail subject per event
    public const SUBJECTS = [
        'invoice' => 'Your internet bill', 'payment' => 'Payment received', 'suspend' => 'Your connection is suspended',
        'reactivate' => 'Your connection is active again', 'notice' => 'Your connection will be suspended', 'reminder' => 'Your internet expires soon',
    ];

    /**
     * Sends a billing event to the customer on each of their channels (customers.notify_channels:
     * SMS by default; e-mail and WhatsApp when the branch has them on). The event is switched on or
     * off with its sms_<event> setting; the text is the same template on every channel.
     */
    public static function send(int $branchId, Customer $customer, string $event, array $vars = []): void
    {
        $settings = IspSettings::all($branchId);
        if (empty($settings['sms_' . $event])) {
            return;
        }
        $vars = array_merge([
            'name' => $customer->name,
            'code' => $customer->code,
            'currency' => Money::currency()['symbol'],
            'balance' => Money::number(max(0, LedgerService::balance($customer->id))),
        ], $vars);
        $message = preg_replace_callback('/\{(\w+)\}/', fn ($m) => $vars[$m[1]] ?? $m[0], self::template($settings, $event, $customer->language));
        $channels = array_filter(array_map('trim', explode(',', (string) ($customer->notify_channels ?: 'sms'))));

        try {
            if (in_array('sms', $channels, true) && $customer->phone) {
                SendSms::dispatch($branchId, $customer->phone, $message, $customer->id, 'isp_' . $event, Auth::guard('web')->id(), request()->ip())->afterCommit();
            }
            if (in_array('email', $channels, true) && $customer->email && MessagingChannel::active($branchId, 'email')) {
                $subject = (company()?->title ? company()->title . ': ' : '') . (self::SUBJECTS[$event] ?? ucfirst($event));
                SendChannelMessage::dispatch($branchId, 'email', $customer->email, $message, $subject, $customer->id, $event)->afterCommit();
            }
            if (in_array('whatsapp', $channels, true) && $customer->phone && MessagingChannel::active($branchId, 'whatsapp')) {
                SendChannelMessage::dispatch($branchId, 'whatsapp', $customer->phone, $message, null, $customer->id, $event)->afterCommit();
            }
        } catch (\Throwable $e) {
            Log::warning('ISP notification failed', ['event' => $event, 'customer' => $customer->id, 'error' => $e->getMessage()]);
        }
    }

    // The event's template in the customer's language when one is set, else the default one.
    public static function template(array $settings, string $event, ?string $language): string
    {
        if ($language) {
            $translations = json_decode((string) ($settings['sms_tpl_translations'] ?? ''), true) ?: [];
            $text = trim((string) ($translations[$language][$event] ?? ''));
            if ($text !== '') {
                return $text;
            }
        }
        return (string) ($settings['sms_tpl_' . $event] ?? '');
    }
}
