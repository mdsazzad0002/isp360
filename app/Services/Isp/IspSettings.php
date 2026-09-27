<?php

namespace App\Services\Isp;

use App\Models\IspSetting;

// Per-branch ISP billing settings stored as key/value rows, with typed defaults.
class IspSettings
{
    public const DEFAULTS = [
        'invoice_prefix' => 'INV',
        'receipt_prefix' => 'RCP',
        'credit_note_prefix' => 'CN',
        'debit_note_prefix' => 'DN',
        'refund_prefix' => 'RF',
        'deposit_prefix' => 'DP',
        'connection_prefix' => 'CON',
        // manual invoices: days after the invoice date they fall due
        'due_days' => 10,
        // days before the paid time ends that the renewal invoice is issued
        'renewal_invoice_days' => 3,
        'auto_invoice' => true,
        // free days added once to a new connection's first paid time (editable per connection)
        'init_bonus_days' => 0,
        // referral commission to the existing customer who referred a new one, as wallet credit
        'referral_enabled' => false,
        'referral_commission_type' => 'fixed', // fixed amount | percent of the first bill
        'referral_commission' => '0',
        'auto_suspend' => true,
        'auto_reactivate' => true,
        // proration: 1-28 = a new line's first bill also covers the days up to this day of the month,
        // so lines renew on the same day (0 = each line renews on its own start date)
        'bill_day' => 0,
        // termination gives the unused paid days back to the customer's balance (day-wise)
        'terminate_credit_unused' => false,
        // KYC: a connection can't be switched on until an identity document of the customer is verified
        'kyc_required' => false,
        // session log: poll this branch's MikroTik API routers for sessions every 5 minutes (RADIUS NAS
        // sessions come from accounting either way)
        'session_log_mikrotik' => false,
        // postpaid packages: a bill falls due this many days after its service period starts
        'postpaid_due_days' => 15,
        // grace: an expired line stays on this many days before it is suspended (0 = at once)
        'grace_days' => 0,
        // notice: an SMS this many days before the line is suspended (0 = none); with
        // notice_required a line is never suspended sooner than notice_days after its notice
        'notice_days' => 0,
        'notice_required' => false,
        // late fee on an invoice unpaid this many days after its due date, posted as a debit note
        'late_fee_type' => 'none', // none | fixed | percent (of the unpaid amount before late fees)
        'late_fee_amount' => '0',
        'late_fee_after_days' => 0,
        'late_fee_repeat' => 'once', // once | monthly (every 30 days while unpaid)
        'late_fee_max' => 1, // most late fees on one invoice
        'sms_invoice' => false,
        'sms_payment' => true,
        'sms_suspend' => true,
        'sms_reactivate' => true,
        'sms_notice' => true,
        // renewal reminder this many days before a prepaid line's paid time ends (0 = none)
        'reminder_days' => 0,
        'sms_reminder' => true,
        'sms_tpl_invoice' => 'Dear {name}, your internet bill {invoice} of {currency} {amount} is due on {due_date}. Total due: {currency} {balance}.',
        'sms_tpl_payment' => 'Dear {name}, we received {currency} {amount} (receipt {receipt}). Current due: {currency} {balance}. Thank you.',
        'sms_tpl_suspend' => 'Dear {name}, your internet connection {connection} is suspended for unpaid bills. Due: {currency} {balance}.',
        'sms_tpl_reactivate' => 'Dear {name}, your internet connection {connection} is active again. Thank you.',
        // the same templates in other languages, for customers with a language set: {"bn": {"invoice": "..."}}
        'sms_tpl_translations' => '',
        'sms_tpl_reminder' => 'Dear {name}, your internet {connection} expires on {expire_date}. Renew now to stay connected. Due: {currency} {balance}.',
        'sms_tpl_notice' => 'Dear {name}, your internet connection {connection} will be suspended on {suspend_date} unless paid. Due: {currency} {balance}.',
    ];

    private static array $cache = [];

    public static function all(int $branchId): array
    {
        if (! isset(self::$cache[$branchId])) {
            $stored = IspSetting::where('branch_id', $branchId)->pluck('value', 'key')->all();
            $values = [];
            foreach (self::DEFAULTS as $key => $default) {
                $values[$key] = array_key_exists($key, $stored) ? self::cast($stored[$key], $default) : $default;
            }
            self::$cache[$branchId] = $values;
        }
        return self::$cache[$branchId];
    }

    public static function get(int $branchId, string $key)
    {
        return self::all($branchId)[$key] ?? null;
    }

    public static function save(int $branchId, array $values): void
    {
        foreach ($values as $key => $value) {
            if (! array_key_exists($key, self::DEFAULTS)) {
                continue;
            }
            $default = self::DEFAULTS[$key];
            $stored = is_bool($default) ? (filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0') : (string) $value;
            IspSetting::updateOrCreate(['branch_id' => $branchId, 'key' => $key], ['value' => $stored]);
        }
        unset(self::$cache[$branchId]);
    }

    // Drops the per-request cache (tests that change settings inside a rolled-back transaction).
    public static function flush(): void
    {
        self::$cache = [];
    }

    private static function cast($value, $default)
    {
        if (is_bool($default)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }
        if (is_int($default)) {
            return (int) $value;
        }
        return (string) $value;
    }
}
