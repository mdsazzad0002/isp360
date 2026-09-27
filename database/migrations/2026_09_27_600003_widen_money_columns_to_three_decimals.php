<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Money columns hold up to 3 decimals so 3-decimal currencies (KWD, BHD, OMR, JOD) fit, and
// 18 digits so large-unit currencies (VND, IDR) fit. The cash-book mirrors (receives, payments,
// bank_transactions) were decimal(8,2), capped at 999,999.99. Each column keeps its null/default.
// Values are rounded to the company currency in PHP (App\Support\Money), not by the column.
return new class extends Migration
{
    private const COLUMNS = [
        'bandwidth_purchases' => ['monthly_cost'],
        'bank_transactions' => ['amount', 'previous_balance'],
        'billing_notes' => ['amount'],
        'connections' => ['discount'],
        'customers' => ['ledger_balance', 'previous_due', 'credit_limit'],
        'customer_payments' => ['amount', 'allocated_amount', 'refunded_amount'],
        'invoices' => ['subtotal', 'discount', 'adjustment', 'total', 'paid', 'due', 'reseller_cost'],
        'invoice_items' => ['unit_price', 'discount', 'total'],
        'ledger_entries' => ['debit', 'credit'],
        'online_payments' => ['amount'],
        'packages' => ['price', 'installation_fee', 'activation_fee', 'base_price'],
        'package_histories' => ['old_price', 'new_price'],
        'package_price_histories' => ['old_price', 'new_price'],
        'payment_allocations' => ['amount'],
        'payment_gateways' => ['min_amount', 'max_amount'],
        'payments' => ['amount', 'previous_due'],
        'receives' => ['amount', 'previous_due'],
        'referral_rewards' => ['amount'],
        'refunds' => ['amount'],
        'reseller_transactions' => ['amount'],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $info = DB::selectOne(
                    'select is_nullable, column_default, numeric_precision, numeric_scale, column_comment from information_schema.columns
                     where table_schema = database() and table_name = ? and column_name = ?',
                    [$table, $column]
                );
                if (! $info || ((int) $info->numeric_precision >= 18 && (int) $info->numeric_scale >= 3)) {
                    continue;
                }
                $sql = "ALTER TABLE `$table` MODIFY `$column` DECIMAL(18,3) " . ($info->is_nullable === 'YES' ? 'NULL' : 'NOT NULL');
                $default = $info->column_default;
                if ($default !== null && strtoupper($default) !== 'NULL') {
                    $sql .= ' DEFAULT ' . (float) trim($default, "'");
                } elseif ($info->is_nullable === 'YES') {
                    $sql .= ' DEFAULT NULL';
                }
                if ($info->column_comment !== '') {
                    $sql .= ' COMMENT ' . DB::getPdo()->quote($info->column_comment);
                }
                DB::statement($sql);
            }
        }
    }

    // Narrowing back could cut stored values, so the wider columns stay.
    public function down(): void
    {
    }
};
