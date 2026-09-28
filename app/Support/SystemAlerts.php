<?php

namespace App\Support;

use App\Services\Isp\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

// Problems a scheduled check found (ledger out of balance, ...). An open alert is shown to admins as a
// banner on every page until the next clean run clears it; raising one is audited and, when
// ISP_ALERT_EMAIL is set, e-mailed once per state change (not every night the problem persists).
class SystemAlerts
{
    private const KEY = 'isp.system_alerts';

    public static function all(): array
    {
        return array_values(Cache::get(self::KEY, []));
    }

    public static function raise(string $key, string $message, array $details = []): void
    {
        $alerts = Cache::get(self::KEY, []);
        $isNew = ! isset($alerts[$key]) || $alerts[$key]['message'] !== $message;
        $alerts[$key] = ['key' => $key, 'message' => $message, 'since' => $alerts[$key]['since'] ?? now()->toDateTimeString(), 'checked_at' => now()->toDateTimeString()];
        Cache::forever(self::KEY, $alerts);

        if ($isNew) {
            AuditLogger::log("alert.{$key}", null, null, ['message' => $message] + array_slice($details, 0, 50));
            self::mail("[{$key}] {$message}", $message . ($details ? "\n\n" . implode("\n", array_slice($details, 0, 50)) : ''));
        }
    }

    public static function clear(string $key): void
    {
        $alerts = Cache::get(self::KEY, []);
        if (isset($alerts[$key])) {
            unset($alerts[$key]);
            Cache::forever(self::KEY, $alerts);
            AuditLogger::log("alert.{$key}_recovered");
            self::mail("[{$key}] recovered", "The {$key} check passes again.");
        }
    }

    private static function mail(string $subject, string $body): void
    {
        $to = config('isp.alert_email');
        if (! $to) {
            return;
        }
        try {
            // without MAIL_FROM_ADDRESS the alert still goes out, sent from the alert address itself
            Mail::raw($body, fn ($m) => $m->from(config('mail.from.address') ?: $to)->to($to)->subject(config('app.name') . ' alert: ' . $subject));
        } catch (\Throwable $e) {
            // the banner and the audit log still carry the alert
            Log::warning('System alert e-mail failed: ' . $e->getMessage());
        }
    }
}
