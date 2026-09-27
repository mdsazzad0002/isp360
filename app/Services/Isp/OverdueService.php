<?php

namespace App\Services\Isp;

use App\Models\Connection;
use App\Models\Invoice;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Invoice -> overdue status, and connection expiry: once a connection's paid time (expire_at)
// runs out it is suspended; payment that extends it brings it back.
//
// Per-branch rules (IspSettings), all off by default so a line goes off the moment its time ends:
//   grace_days       the line stays on this many days after its paid time
//   notice_days      an SMS this many days before the suspension; with notice_required the line
//                    is never suspended sooner than notice_days after that notice was sent
//   late_fee_*       a debit note on an invoice left unpaid after its due date (applyLateFees)
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

    // When the line's paid time ends plus the grace period: expire_at, or for a line billed but
    // never paid, its activation. Null for a line that has nothing to end.
    public static function graceEnd(Connection $connection, ?array $settings = null): ?Carbon
    {
        $settings ??= IspSettings::all($connection->branch_id);
        $base = $connection->expire_at ?? $connection->activated_at ?? $connection->created_at;
        return $base ? Carbon::parse($base)->addDays((int) $settings['grace_days']) : null;
    }

    // The moment the line is suspended: the grace end, pushed back while a required notice is
    // missing or too recent. Null = not yet known (a required notice hasn't gone out).
    public static function suspendAt(Connection $connection, ?array $settings = null): ?Carbon
    {
        $settings ??= IspSettings::all($connection->branch_id);
        $at = self::graceEnd($connection, $settings);
        if ($at && $settings['notice_required'] && $settings['notice_days'] > 0 && $connection->expire_at) {
            if (! self::noticeSentFor($connection)) {
                return null;
            }
            $at = $at->max($connection->expiry_notice_at->copy()->addDays((int) $settings['notice_days']));
        }
        return $at;
    }

    // Time's up for an active line: paid time over, grace over, notice rule met.
    public static function isDueForSuspension(Connection $connection, ?array $settings = null): bool
    {
        if ($connection->expire_at && $connection->expire_at->isFuture()) {
            return false;
        }
        $at = self::suspendAt($connection, $settings);
        return $at !== null && ! $at->isFuture();
    }

    private static function noticeSentFor(Connection $connection): bool
    {
        return $connection->expiry_notice_at && $connection->expiry_notice_for
            && $connection->expire_at && $connection->expiry_notice_for->equalTo($connection->expire_at);
    }

    /**
     * Suspends active lines whose paid time (plus grace, and notice when required) is up, or that
     * were billed but never paid. Runs every minute; payments and reversals apply it at once.
     *
     * @return int number of connections suspended
     */
    public static function autoSuspend(int $branchId): int
    {
        $settings = IspSettings::all($branchId);
        if (! $settings['auto_suspend']) {
            return 0;
        }
        $count = 0;
        // candidates only: the exact moment (notice rule) is checked per line in applyExpiry
        $cutoff = now()->subDays((int) $settings['grace_days']);

        Connection::where('branch_id', $branchId)
            ->where('status', 'active')
            ->where(fn ($q) => $q->where('expire_at', '<=', $cutoff)
                ->orWhere(fn ($w) => $w->whereNull('expire_at')->where('activated_at', '<=', $cutoff)->whereExists(fn ($sub) => $sub->selectRaw(1)->from('invoices')
                    ->whereColumn('invoices.connection_id', 'connections.id')
                    ->whereNotNull('invoices.service_months')
                    ->whereNotIn('invoices.status', ['draft', 'void', 'cancelled']))))
            ->chunkById(200, function ($connections) use (&$count) {
                foreach ($connections as $connection) {
                    try {
                        if (ConnectionService::applyExpiry($connection)) {
                            $count++;
                        }
                    } catch (\Throwable $e) {
                        Log::warning('Auto suspend failed', ['connection' => $connection->id, 'error' => $e->getMessage()]);
                    }
                }
            });

        return $count;
    }

    /**
     * Notice before suspension: one SMS per paid time, notice_days before the line would go off.
     * Recorded on the connection even when no SMS could be sent (no phone, SMS off), since the
     * notice rule counts from it.
     *
     * @return int notices sent
     */
    public static function sendNotices(int $branchId): int
    {
        $settings = IspSettings::all($branchId);
        $days = (int) $settings['notice_days'];
        if ($days <= 0 || ! $settings['auto_suspend']) {
            return 0;
        }
        $count = 0;
        // lines whose grace ends within the notice window and whose current paid time has no notice yet
        Connection::with('customer')->where('branch_id', $branchId)
            ->where('status', 'active')
            ->whereNotNull('expire_at')
            ->where('expire_at', '<=', now()->addDays($days)->subDays((int) $settings['grace_days']))
            ->where(fn ($q) => $q->whereNull('expiry_notice_for')->orWhereColumn('expiry_notice_for', '!=', 'expire_at'))
            ->chunkById(200, function ($connections) use (&$count, $settings) {
                foreach ($connections as $connection) {
                    $claimed = Connection::whereKey($connection->id)->where('expire_at', $connection->expire_at)
                        ->where(fn ($q) => $q->whereNull('expiry_notice_for')->orWhereColumn('expiry_notice_for', '!=', 'expire_at'))
                        ->update(['expiry_notice_for' => $connection->expire_at, 'expiry_notice_at' => now()]);
                    if (! $claimed) {
                        continue; // a parallel run got it, or the paid time just changed
                    }
                    $connection->expiry_notice_for = $connection->expire_at;
                    $connection->expiry_notice_at = now();
                    $suspendAt = self::suspendAt($connection, $settings);
                    if ($connection->customer) {
                        IspNotifier::send($connection->branch_id, $connection->customer, 'notice', [
                            'connection' => $connection->code,
                            'expire_date' => $connection->expire_at->format('d M Y h:i A'),
                            'suspend_date' => $suspendAt?->format('d M Y h:i A') ?? '',
                        ]);
                    }
                    ConnectionService::logHistory($connection, 'expiry_notice', null, ['suspend_at' => $suspendAt?->toDateTimeString()],
                        'Notice sent: suspension on ' . ($suspendAt?->format('d M Y h:i A') ?? '-'));
                    $count++;
                }
            });
        return $count;
    }

    /**
     * Late fees: a debit note on each invoice still unpaid late_fee_after_days after its due date,
     * once or every 30 days up to late_fee_max. Percent fees are on the unpaid amount before any
     * late fee (no fee on a fee). Late fees are not taxed.
     *
     * @return int late fees charged
     */
    public static function applyLateFees(int $branchId): int
    {
        $settings = IspSettings::all($branchId);
        $type = $settings['late_fee_type'];
        $value = (float) $settings['late_fee_amount'];
        $max = max(1, (int) $settings['late_fee_max']);
        if (! in_array($type, ['fixed', 'percent'], true) || $value <= 0) {
            return 0;
        }
        $monthly = $settings['late_fee_repeat'] === 'monthly';
        $count = 0;

        Invoice::where('branch_id', $branchId)
            ->whereIn('status', Invoice::OPEN_STATUSES)
            ->where('due', '>', 0)
            ->where('due_date', '<=', now()->subDays((int) $settings['late_fee_after_days'] + 1)->toDateString())
            ->where('late_fee_count', '<', $monthly ? $max : 1)
            ->where(fn ($q) => $q->whereNull('last_late_fee_at')->orWhere('last_late_fee_at', '<=', now()->subDays(30)))
            ->chunkById(200, function ($invoices) use (&$count, $type, $value, $monthly, $max) {
                foreach ($invoices as $invoice) {
                    try {
                        if (self::chargeLateFee($invoice, $type, $value, $monthly ? $max : 1)) {
                            $count++;
                        }
                    } catch (\Throwable $e) {
                        Log::warning('Late fee failed', ['invoice' => $invoice->id, 'error' => $e->getMessage()]);
                    }
                }
            });
        return $count;
    }

    private static function chargeLateFee(Invoice $invoice, string $type, float $value, int $max): bool
    {
        return DB::transaction(function () use ($invoice, $type, $value, $max) {
            $invoice = Invoice::lockForUpdate()->find($invoice->id);
            // checked again under the lock, so two runs can't charge the same period twice
            if (! $invoice || ! in_array($invoice->status, Invoice::OPEN_STATUSES, true) || (float) $invoice->due <= 0
                || $invoice->late_fee_count >= $max || ($invoice->last_late_fee_at && $invoice->last_late_fee_at->gt(now()->subDays(30)))) {
                return false;
            }
            $base = max(0, (float) $invoice->due - (float) $invoice->late_fee_total);
            $fee = Money::round($type === 'percent' ? $base * $value / 100 : $value);
            if ($fee <= 0) {
                return false;
            }
            $n = $invoice->late_fee_count + 1;
            BillingService::addNote($invoice, 'debit', $fee, "Late fee" . ($max > 1 ? " {$n}" : '') . ' (' . ($type === 'percent' ? rtrim(rtrim(number_format($value, 2), '0'), '.') . '% of ' . Money::format($base) : 'fixed') . ')', null, false);
            Invoice::whereKey($invoice->id)->update([
                'late_fee_count' => $n,
                'late_fee_total' => Money::round((float) $invoice->late_fee_total + $fee),
                'last_late_fee_at' => now(),
            ]);
            return true;
        });
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
