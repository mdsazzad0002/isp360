<?php

namespace App\Services\Isp;

use App\Models\Connection;
use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Support\Facades\Log;

// Invoice -> due -> overdue -> grace period -> auto suspension, and the way back:
// once nothing is past grace any more, overdue-suspended connections reactivate.
class OverdueService
{
    public const SUSPEND_REASON = 'Overdue';

    public static function markOverdue(int $branchId): int
    {
        return Invoice::where('branch_id', $branchId)
            ->whereIn('status', ['issued', 'partially_paid'])
            ->where('due', '>', 0)
            ->where('due_date', '<', now()->toDateString())
            ->update(['status' => 'overdue', 'updated_at' => now()]);
    }

    /** @return int number of connections suspended */
    public static function autoSuspend(int $branchId): int
    {
        if (! IspSettings::get($branchId, 'auto_suspend')) {
            return 0;
        }
        $cutoff = now()->subDays(IspSettings::get($branchId, 'grace_days'))->toDateString();
        $count = 0;

        Connection::where('branch_id', $branchId)
            ->where('status', 'active')
            ->where(function ($q) use ($cutoff) {
                // an overdue invoice of this connection, or a customer-level invoice (no connection)
                $q->whereExists(function ($sub) use ($cutoff) {
                    $sub->selectRaw(1)->from('invoices')
                        ->whereColumn('invoices.customer_id', 'connections.customer_id')
                        ->where(fn ($w) => $w->whereColumn('invoices.connection_id', 'connections.id')->orWhereNull('invoices.connection_id'))
                        ->where('invoices.status', 'overdue')
                        ->where('invoices.due', '>', 0)
                        ->where('invoices.due_date', '<', $cutoff);
                });
            })
            ->with('customer')
            ->chunkById(200, function ($connections) use (&$count) {
                foreach ($connections as $connection) {
                    try {
                        ConnectionService::suspend($connection, self::SUSPEND_REASON, true);
                        $count++;
                        IspNotifier::send($connection->branch_id, $connection->customer, 'suspend', ['connection' => $connection->code]);
                    } catch (\Throwable $e) {
                        Log::warning('Auto suspend failed', ['connection' => $connection->id, 'error' => $e->getMessage()]);
                    }
                }
            });

        return $count;
    }

    // Called after a payment / credit note: brings back connections that were suspended
    // for non-payment when the customer no longer has anything past the grace period.
    public static function reactivateIfClear(int $customerId): int
    {
        $customer = Customer::find($customerId);
        if (! $customer || ! IspSettings::get($customer->branch_id, 'auto_reactivate')) {
            return 0;
        }
        $cutoff = now()->subDays(IspSettings::get($customer->branch_id, 'grace_days'))->toDateString();
        $count = 0;

        $connections = Connection::where('customer_id', $customerId)
            ->where('status', 'suspended')
            ->where('suspension_reason', self::SUSPEND_REASON)
            ->get();

        foreach ($connections as $connection) {
            $stillOverdue = Invoice::where('customer_id', $customerId)
                ->where(fn ($w) => $w->where('connection_id', $connection->id)->orWhereNull('connection_id'))
                ->where('status', 'overdue')
                ->where('due', '>', 0)
                ->where('due_date', '<', $cutoff)
                ->exists();
            if ($stillOverdue) {
                continue;
            }
            try {
                ConnectionService::reactivate($connection, 'Dues cleared', true);
                $count++;
                IspNotifier::send($customer->branch_id, $customer, 'reactivate', ['connection' => $connection->code]);
            } catch (\Throwable $e) {
                Log::warning('Auto reactivate failed', ['connection' => $connection->id, 'error' => $e->getMessage()]);
            }
        }
        return $count;
    }
}
