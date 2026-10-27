<?php

namespace App\Services\Isp;

use App\Models\CashHandover;
use App\Models\CollectionVisit;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use RuntimeException;

// Door-to-door collection: a collector's route (customers with dues in their areas), cash taken
// through CollectionService like any payment, the visit log, and cash handed over to the office.
class CollectorService
{
    public static function areaIds(int $userId): array
    {
        return DB::table('collector_areas')->where('user_id', $userId)->pluck('area_id')->map(fn ($id) => (int) $id)->all();
    }

    public static function setAreas(int $branchId, int $userId, array $areaIds): void
    {
        DB::transaction(function () use ($branchId, $userId, $areaIds) {
            DB::table('collector_areas')->where('user_id', $userId)->where('branch_id', $branchId)->delete();
            foreach (array_unique(array_map('intval', $areaIds)) as $areaId) {
                DB::table('collector_areas')->insert(['branch_id' => $branchId, 'user_id' => $userId, 'area_id' => $areaId]);
            }
        });
        AuditLogger::log('collector.areas', null, null, ['user_id' => $userId, 'areas' => $areaIds], null, $branchId);
    }

    // Customers with a due in the collector's areas, the oldest debt first, with today's visit.
    public static function route(int $branchId, int $userId, ?int $areaId, ?string $search)
    {
        $areas = self::areaIds($userId);
        $today = now()->toDateString();
        return Customer::query()
            ->leftJoin('areas', 'areas.id', '=', 'customers.area_id')
            ->leftJoinSub(DB::table('invoices')->selectRaw('customer_id, min(due_date) as oldest_due')->whereIn('status', \App\Models\Invoice::OPEN_STATUSES)->where('due', '>', 0)->groupBy('customer_id'), 'od', 'od.customer_id', '=', 'customers.id')
            ->where('customers.branch_id', $branchId)
            ->whereIn('customers.area_id', $areaId && in_array($areaId, $areas) ? [$areaId] : $areas)
            ->where('customers.ledger_balance', '>', 0)
            ->when($search, fn ($q, $s) => $q->where(fn ($w) => $w->where('customers.name', 'like', "%{$s}%")->orWhere('customers.phone', 'like', "%{$s}%")->orWhere('customers.code', 'like', "%{$s}%")->orWhere('customers.address', 'like', "%{$s}%")))
            ->select('customers.id', 'customers.code', 'customers.name', 'customers.phone', 'customers.address', 'customers.ledger_balance as due', 'areas.name as area', 'od.oldest_due',
                DB::raw("(select outcome from collection_visits v where v.customer_id = customers.id and v.user_id = {$userId} and date(v.created_at) = '{$today}' order by v.id desc limit 1) as visited_today"))
            ->orderByRaw('visited_today is not null')->orderBy('areas.name')->orderByRaw('od.oldest_due is null')->orderBy('od.oldest_due')->orderByDesc('customers.ledger_balance')
            ->limit(500)->get();
    }

    // Cash taken at the door: an ordinary payment by this collector, plus the visit.
    public static function collect(Customer $customer, int $userId, float $amount, ?string $note, ?float $lat, ?float $lng): CustomerPayment
    {
        self::inArea($customer, $userId);
        return DB::transaction(function () use ($customer, $userId, $amount, $note, $lat, $lng) {
            $payment = CollectionService::receive($customer, ['amount' => $amount, 'method' => 'cash', 'payment_date' => now()->toDateString(), 'notes' => $note ?: 'Collected at the door', 'source' => 'admin']);
            CollectionVisit::create(['branch_id' => $customer->branch_id, 'user_id' => $userId, 'customer_id' => $customer->id, 'payment_id' => $payment->id, 'outcome' => 'paid',
                'note' => $note, 'latitude' => $lat, 'longitude' => $lng]);
            return $payment;
        });
    }

    public static function visit(Customer $customer, int $userId, string $outcome, ?string $note, ?float $lat, ?float $lng): CollectionVisit
    {
        self::inArea($customer, $userId);
        if (! array_key_exists($outcome, CollectionVisit::OUTCOMES) || $outcome === 'paid') {
            throw new RuntimeException('Pick what happened at the door.');
        }
        return CollectionVisit::create(['branch_id' => $customer->branch_id, 'user_id' => $userId, 'customer_id' => $customer->id, 'outcome' => $outcome, 'note' => $note, 'latitude' => $lat, 'longitude' => $lng]);
    }

    // ['collected' => cash received, not reversed; 'handed_over' => confirmed; 'pending' => waiting for the office; 'in_hand']
    public static function cash(int $branchId, int $userId): array
    {
        $collected = (float) CustomerPayment::where('branch_id', $branchId)->where('received_by', $userId)->where('method', 'cash')
            ->whereNotIn('status', ['reversed', 'failed', 'pending'])->sum('amount');
        $handed = (float) CashHandover::where('branch_id', $branchId)->where('collector_id', $userId)->where('status', 'confirmed')->sum('amount');
        $pending = (float) CashHandover::where('branch_id', $branchId)->where('collector_id', $userId)->where('status', 'pending')->sum('amount');
        $today = (float) CustomerPayment::where('branch_id', $branchId)->where('received_by', $userId)->where('method', 'cash')
            ->whereNotIn('status', ['reversed', 'failed', 'pending'])->whereDate('created_at', now()->toDateString())->sum('amount');
        return ['collected' => Money::round($collected), 'handed_over' => Money::round($handed), 'pending' => Money::round($pending),
            'in_hand' => Money::round($collected - $handed), 'today' => Money::round($today)];
    }

    public static function handover(int $branchId, int $userId, float $amount, ?string $note): CashHandover
    {
        return DB::transaction(function () use ($branchId, $userId, $amount, $note) {
            // one at a time per collector, so two taps can't hand over the same cash twice
            DB::table('users')->where('id', $userId)->lockForUpdate()->first();
            $cash = self::cash($branchId, $userId);
            $free = Money::round($cash['in_hand'] - $cash['pending']);
            if ($amount <= 0 || $amount > $free + 0.001) {
                throw new RuntimeException('You can hand over up to ' . Money::format(max(0, $free)) . ' (cash in hand less what is already waiting for the office).');
            }
            $h = CashHandover::create(['branch_id' => $branchId, 'collector_id' => $userId, 'amount' => $amount, 'note' => $note]);
            AuditLogger::log('cash_handover.created', $h, null, ['amount' => $amount], $note, $branchId);
            return $h;
        });
    }

    public static function decide(CashHandover $h, bool $confirm, ?string $reason, int $by): CashHandover
    {
        if ($h->status !== 'pending') {
            throw new RuntimeException("This handover is already {$h->status}.");
        }
        if ((int) $h->collector_id === $by) {
            throw new RuntimeException('Someone else must confirm the cash you hand over.');
        }
        $h->update($confirm ? ['status' => 'confirmed', 'confirmed_by' => $by, 'confirmed_at' => now()] : ['status' => 'rejected', 'confirmed_by' => $by, 'confirmed_at' => now(), 'reject_reason' => $reason]);
        AuditLogger::log($confirm ? 'cash_handover.confirmed' : 'cash_handover.rejected', $h, null, ['amount' => (float) $h->amount], $reason, $h->branch_id);
        return $h;
    }

    private static function inArea(Customer $customer, int $userId): void
    {
        if (! in_array((int) $customer->area_id, self::areaIds($userId), true)) {
            throw new RuntimeException("{$customer->name} is not in your areas.");
        }
    }
}
