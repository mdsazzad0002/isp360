<?php

namespace App\Services\Isp;

use App\Models\Connection;
use App\Models\PaymentPromise;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

// Promise to pay: holds the suspension for non-payment until the promised date (and brings a line
// suspended for it back), then follows up by itself: paid = kept, date passed unpaid = broken and the
// line goes off as usual. Limits per branch: promise_max_days ahead, promise_max_per_30 per connection.
class PromiseService
{
    public static function create(Connection $connection, string $date, ?float $amount, ?string $note, ?int $userId): PaymentPromise
    {
        $settings = IspSettings::all($connection->branch_id);
        $promised = \Illuminate\Support\Carbon::parse($date)->startOfDay();
        $max = (int) $settings['promise_max_days'];
        if ($promised->lt(today()) || $promised->gt(today()->addDays($max))) {
            throw new RuntimeException("The promised date must be from today to {$max} day(s) ahead.");
        }
        return DB::transaction(function () use ($connection, $promised, $amount, $note, $userId, $settings) {
            $connection = Connection::with(['customer', 'package'])->lockForUpdate()->findOrFail($connection->id);
            if (in_array($connection->status, ['terminated', 'inactive'], true)) {
                throw new RuntimeException("{$connection->code} is {$connection->status}.");
            }
            $suspendedForDue = $connection->status === 'suspended' && in_array($connection->suspension_reason, OverdueService::SUSPEND_REASONS, true);
            if ($connection->status === 'suspended' && ! $suspendedForDue) {
                throw new RuntimeException("{$connection->code} is suspended for another reason ({$connection->suspension_reason}); a promise only covers unpaid bills.");
            }
            if (! $suspendedForDue && ! ConnectionService::needsPayment($connection) && ! self::expiresBefore($connection, $promised)) {
                throw new RuntimeException("{$connection->code} has paid time until " . ($connection->expire_at?->format('d M Y') ?? '—') . ': nothing to hold.');
            }
            if (PaymentPromise::where('connection_id', $connection->id)->where('status', 'open')->exists()) {
                throw new RuntimeException('There is already an open promise for this connection.');
            }
            $recent = PaymentPromise::where('connection_id', $connection->id)->where('created_at', '>=', now()->subDays(30))->whereIn('status', ['open', 'kept', 'broken'])->count();
            if ($recent >= (int) $settings['promise_max_per_30']) {
                throw new RuntimeException("{$connection->code} already had {$recent} promise(s) in the last 30 days.");
            }

            $promise = PaymentPromise::create([
                'branch_id' => $connection->branch_id, 'customer_id' => $connection->customer_id, 'connection_id' => $connection->id,
                'promised_date' => $promised->toDateString(), 'amount' => $amount, 'note' => $note, 'status' => 'open',
                'reactivated' => $suspendedForDue, 'created_by' => $userId,
            ]);
            $text = 'Promise to pay by ' . $promised->format('d M Y') . ($amount ? ' (' . Money::format($amount) . ')' : '');
            ConnectionService::logHistory($connection, 'promise', null, ['promised_date' => $promise->promised_date->toDateString(), 'amount' => $amount], $text);
            AuditLogger::log('promise.created', $promise, null, $promise->only(['connection_id', 'promised_date', 'amount']), $note, $connection->branch_id);
            if ($suspendedForDue) {
                ConnectionService::reactivate($connection, $text, true);
            }
            return $promise;
        });
    }

    // the line's paid time runs out before the promised date (a promise made ahead of expiry)
    private static function expiresBefore(Connection $connection, $date): bool
    {
        return $connection->expire_at && $connection->expire_at->lt($date->copy()->endOfDay());
    }

    // An open promise covers today: no suspension for non-payment.
    public static function holds(Connection $connection): bool
    {
        return PaymentPromise::where('connection_id', $connection->id)->where('status', 'open')
            ->where('promised_date', '>=', today()->toDateString())->exists();
    }

    public static function cancel(PaymentPromise $promise, ?string $reason, ?int $userId): PaymentPromise
    {
        if ($promise->status !== 'open') {
            throw new RuntimeException("This promise is already {$promise->status}.");
        }
        $promise->update(['status' => 'cancelled', 'resolved_at' => now()]);
        AuditLogger::log('promise.cancelled', $promise, null, null, $reason, $promise->branch_id);
        self::followUp($promise);
        return $promise;
    }

    /**
     * Scheduled (with the overdue run): open promises become kept once nothing is unpaid, broken
     * after their date; the day before, the customer gets a reminder. Returns [kept, broken, reminded].
     */
    public static function resolve(int $branchId): array
    {
        $kept = $broken = $reminded = 0;
        $settings = IspSettings::all($branchId);
        foreach (PaymentPromise::with(['connection.package', 'customer'])->where('branch_id', $branchId)->where('status', 'open')->get() as $promise) {
            try {
                $connection = Connection::with('package')->find($promise->connection_id);
                if (! $connection || in_array($connection->status, ['terminated', 'inactive'], true)) {
                    $promise->update(['status' => 'cancelled', 'resolved_at' => now()]);
                    continue;
                }
                if (self::paid($connection, $promise)) {
                    $promise->update(['status' => 'kept', 'resolved_at' => now()]);
                    AuditLogger::log('promise.kept', $promise, null, null, null, $branchId);
                    $kept++;
                    continue;
                }
                if ($promise->promised_date->lt(today())) {
                    $promise->update(['status' => 'broken', 'resolved_at' => now()]);
                    ConnectionService::logHistory($connection, 'promise_broken', null, null, 'Promise to pay by ' . $promise->promised_date->format('d M Y') . ' not kept');
                    AuditLogger::log('promise.broken', $promise, null, null, null, $branchId);
                    self::followUp($promise);
                    $broken++;
                    continue;
                }
                if (! $promise->reminder_sent_at && $promise->promised_date->lte(today()->addDay()) && $promise->customer && $settings['sms_promise']) {
                    $promise->update(['reminder_sent_at' => now()]);
                    IspNotifier::send($branchId, $promise->customer, 'promise', [
                        'connection' => $connection->code,
                        'promise_date' => $promise->promised_date->format('d M Y'),
                        'amount' => $promise->amount !== null ? Money::number($promise->amount) : Money::number(max(0, LedgerService::balance($promise->customer_id))),
                    ]);
                    $reminded++;
                }
            } catch (\Throwable $e) {
                Log::warning('Promise follow-up failed', ['promise' => $promise->id, 'error' => $e->getMessage()]);
            }
        }
        return [$kept, $broken, $reminded];
    }

    // kept: the line no longer needs a payment (paid time again, or no overdue bill)
    private static function paid(Connection $connection, PaymentPromise $promise): bool
    {
        if (ConnectionService::needsPayment($connection)) {
            return false;
        }
        // made ahead of expiry: kept once the paid time reaches past the promised date
        return ! $connection->expire_at || $connection->expire_at->gt($promise->promised_date->copy()->endOfDay())
            || OverdueService::isPostpaid($connection);
    }

    // the hold is gone: the usual rules decide at once whether the line goes off
    private static function followUp(PaymentPromise $promise): void
    {
        $connection = Connection::with(['package', 'customer'])->find($promise->connection_id);
        if ($connection) {
            ConnectionService::applyExpiry($connection);
        }
    }
}
