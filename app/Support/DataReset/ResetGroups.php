<?php

namespace App\Support\DataReset;

/**
 * Single source of truth for the Factory Reset checklist: which tables belong
 * to which selectable group, and the safe truncation order within each group
 * (children before parents). Tables never listed here can never be reset —
 * settings, license, users, roles, company_profiles, and the Data Import
 * tool's own state are intentionally excluded.
 */
class ResetGroups
{
    public static function all(): array
    {
        return [
            'sales' => [
                'label' => 'Sales & Sale Returns',
                'tables' => ['sale_banks', 'sale_details', 'sale_return_details', 'sale_returns', 'sales'],
            ],
            'purchases' => [
                'label' => 'Purchases & Purchase Returns',
                'tables' => ['purchase_banks', 'purchase_details', 'purchase_return_details', 'purchase_returns', 'purchases'],
            ],
            'stockAdjustment' => [
                'label' => 'Stock Adjustment & Damage',
                'tables' => ['stock_adjustment_details', 'stock_adjustments', 'damage_details', 'damages'],
            ],
            'production' => [
                'label' => 'Production',
                'tables' => ['production_items', 'production_outputs', 'production_damages', 'production_recipe_items', 'production_recipe_versions', 'production_recipes', 'productions'],
            ],
            'productsCatalog' => [
                'label' => 'Products & Categories',
                'tables' => ['products', 'categories', 'brands', 'units', 'companies', 'generics'],
            ],
            'partners' => [
                'label' => 'Customers & Suppliers',
                'tables' => ['customers', 'suppliers'],
            ],
            'investments' => [
                'label' => 'Investments',
                'tables' => ['invest_transactions', 'invest_accounts'],
            ],
            'accounts' => [
                'label' => 'Accounts & Bank',
                'tables' => ['bank_transactions', 'banks', 'transactions', 'payments', 'receives', 'account_heads', 'branch_fund_transfers'],
            ],
            'quotations' => [
                'label' => 'Quotations',
                'tables' => ['quotation_details', 'quotations'],
            ],
            'smsLogs' => [
                'label' => 'SMS Logs',
                'tables' => ['sms_logs'],
            ],
            'stockCheck' => [
                'label' => 'Stock Check',
                'tables' => ['stock_checks'],
            ],
            'payroll' => [
                'label' => 'Payroll',
                'tables' => ['salary_details', 'salary_masters', 'advance_salary_deductions', 'advance_salaries', 'leaves', 'leave_types'],
            ],
            'orgData' => [
                'label' => 'Areas / Departments / Designations',
                'tables' => ['areas', 'departments', 'designations'],
            ],
            'careflowTableOrders' => [
                'label' => 'CareFlow & Table Orders',
                'tables' => [
                    'careflow_order_item_photos', 'careflow_inspections', 'careflow_claims',
                    'careflow_deliveries', 'careflow_processing_assignments', 'careflow_order_items',
                    'careflow_orders', 'careflow_providers', 'careflow_referrals',
                    'table_order_messages', 'table_order_items', 'table_order_guests',
                    'table_orders', 'tables', 'floors',
                ],
            ],
            'stockTransfersAssets' => [
                'label' => 'Stock Transfers & Assets',
                'tables' => ['stock_transfer_details', 'stock_transfers', 'assets'],
            ],
            'packagesOpeningBalances' => [
                'label' => 'Packages & Opening Balances',
                'tables' => ['package_items', 'packages', 'customer_opening_balances', 'product_opening_stocks'],
            ],
            'payrollActivityLogs' => [
                'label' => 'Payroll Payments & Activity Logs',
                'tables' => ['salary_payments', 'update_histories', 'user_activities'],
            ],
            'branches' => [
                'label' => 'Branches (reset to a single default branch)',
                'tables' => ['branches'],
            ],
        ];
    }

    public static function tablesFor(array $groupKeys): array
    {
        $groups = self::all();
        $tables = [];
        foreach ($groupKeys as $key) {
            if (isset($groups[$key])) {
                $tables[$key] = $groups[$key]['tables'];
            }
        }
        return $tables;
    }
}
