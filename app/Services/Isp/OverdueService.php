<?php

namespace App\Services\Isp;

use App\Models\Connection;
use App\Models\Invoice;
use Illuminate\Support\Facades\Log;

// Invoice -> overdue status, and connection expiry: once a connection's paid time (expire_at)
// runs out it is suspended at once (no grace period); payment that extends it brings it back.
class OverdueService
{
    public const SUSPEND_REASON = 'Expired';
    // 'Overdue' = suspensions made before the expire-date rule; they come back the same way.
    public const SUSPEND_REASONS = ['Expired', 'Overdue'];

    public static function markOverdue(int $branchId): int
    {
        return Invoice::where('branch_id', $branchId)
            ->whereIn('status', ['issued', 'partially_paid'])
            ->where('due', '>', 0)
            ->where('due_date', '<', now()->toDateString())
            ->update(['status' => 'overdue', 'updated_at' => now()]);
    }

    /**
     * No grace period: an active line is suspended the moment its paid time is up (or when it
     * was billed but never paid). Runs every minute; payments and reversals apply it at once.
     *
     * @return int number of connections suspended
     */
    public static function autoSuspend(int $branchId): int
    {
        if (! IspSettings::get($branchId, 'auto_suspend')) {
            return 0;
        }
        $count = 0;

        Connection::where('branch_id', $branchId)
            ->where('status', 'active')
            ->where(fn ($q) => $q->where('expire_at', '<=', now())
                ->orWhere(fn ($w) => $w->whereNull('expire_at')->whereExists(fn ($sub) => $sub->selectRaw(1)->from('invoices')
                    ->whereColumn('invoices.connection_id', 'connections.id')
                    ->whereNotNull('invoices.service_months')
                    ->whereNotIn('invoices.status', ['draft', 'void', 'cancelled']))))
            ->chunkById(200, function ($connections) use (&$count) {
                foreach ($connections as $connection) {
                    try {
                        ConnectionService::applyExpiry($connection);
                        $count++;
                    } catch (\Throwable $e) {
                        Log::warning('Auto suspend failed', ['connection' => $connection->id, 'error' => $e->getMessage()]);
                    }
                }
            });

        return $count;
    }

    // Safety net after a payment / credit note (the expire time normally switches the line
    // back on already): brings back lines suspended for expiry that have paid time again.
    public static function reactivateIfClear(int $customerId): int
    {
        $count = 0;
        $connections = Connection::where('customer_id', $customerId)
            ->where('status', 'suspended')
            ->whereIn('suspension_reason', self::SUSPEND_REASONS)
            ->where('expire_at', '>', now())
            ->get();
        foreach ($connections as $connection) {
            try {
                ConnectionService::applyExpiry($connection);
                $count++;
            } catch (\Throwable $e) {
                Log::warning('Auto reactivate failed', ['connection' => $connection->id, 'error' => $e->getMessage()]);
            }
        }
        return $count;
    }
}
