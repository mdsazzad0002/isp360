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
        'sms_invoice' => false,
        'sms_payment' => true,
        'sms_suspend' => true,
        'sms_reactivate' => true,
        'sms_tpl_invoice' => 'Dear {name}, your internet bill {invoice} of {currency} {amount} is due on {due_date}. Total due: {currency} {balance}.',
        'sms_tpl_payment' => 'Dear {name}, we received {currency} {amount} (receipt {receipt}). Current due: {currency} {balance}. Thank you.',
        'sms_tpl_suspend' => 'Dear {name}, your internet connection {connection} is suspended for unpaid bills. Due: {currency} {balance}.',
        'sms_tpl_reactivate' => 'Dear {name}, your internet connection {connection} is active again. Thank you.',
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
