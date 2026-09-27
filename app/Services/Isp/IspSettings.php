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
        // days after the invoice date the invoice falls due
        'due_days' => 10,
        // days after the due date before an overdue connection is auto-suspended
        'grace_days' => 5,
        'auto_invoice' => true,
        // day of month the scheduler generates invoices
        'invoice_generate_day' => 1,
        // 'current' = bill the running month (prepaid style), 'previous' = bill the month just ended (postpaid)
        'billing_month' => 'current',
        // first period when a connection is activated mid-month: prorate | full | next_month
        'first_month_billing' => 'prorate',
        'bill_suspended' => false,
        'auto_suspend' => true,
        'auto_reactivate' => true,
        'sms_invoice' => false,
        'sms_payment' => true,
        'sms_suspend' => true,
        'sms_reactivate' => true,
        'sms_tpl_invoice' => 'Dear {name}, your internet bill {invoice} of Tk {amount} is due on {due_date}. Total due: Tk {balance}.',
        'sms_tpl_payment' => 'Dear {name}, we received Tk {amount} (receipt {receipt}). Current due: Tk {balance}. Thank you.',
        'sms_tpl_suspend' => 'Dear {name}, your internet connection {connection} is suspended for unpaid bills. Due: Tk {balance}.',
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
