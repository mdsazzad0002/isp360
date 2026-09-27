<?php

namespace App\Services\Isp;

use App\Models\Connection;
use App\Models\Customer;
use App\Models\CustomerDeposit;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

// Security deposits: money held for the customer, owed back to them (a liability), kept out of
// the customer ledger so it never pays a bill by itself.
//   receive  -> cash/bank book receive (money in)
//   refund   -> cash/bank book payment (money out)
//   apply    -> a normal customer payment (method 'deposit') that pays dues; no second cash entry
class DepositService
{
    public static function receive(Customer $customer, array $data): CustomerDeposit
    {
        return DB::transaction(function () use ($customer, $data) {
            $amount = Money::round((float) $data['amount']);
            $method = $data['method'] ?? 'cash';
            if ($amount <= 0) {
                throw new RuntimeException('Amount must be greater than zero.');
            }
            if ($method !== 'cash' && empty($data['bank_id'])) {
                throw new RuntimeException('Select the bank / mobile-banking account this deposit was received into.');
            }
            $connectionId = ! empty($data['connection_id'])
                ? Connection::where('customer_id', $customer->id)->findOrFail($data['connection_id'])->id : null;

            $deposit = CustomerDeposit::create([
                'deposit_no' => SequenceService::next($customer->branch_id, 'deposit', IspSettings::get($customer->branch_id, 'deposit_prefix')),
                'customer_id' => $customer->id,
                'connection_id' => $connectionId,
                'amount' => $amount,
                'method' => $method,
                'bank_id' => $method === 'cash' ? null : $data['bank_id'],
                'received_date' => Carbon::parse($data['received_date'] ?? now())->toDateString(),
                'status' => 'held',
                'notes' => isset($data['notes']) ? mb_substr($data['notes'], 0, 255) : null,
                'branch_id' => $customer->branch_id,
                'created_by' => Auth::guard('web')->id(),
                'ipAddress' => app()->runningInConsole() ? null : request()->ip(),
            ]);
            self::book('receives', $deposit, $deposit->deposit_no, $amount, $method, $deposit->bank_id, $deposit->received_date->toDateString(), 'Security deposit');
            AuditLogger::log('deposit.received', $deposit, null, $deposit->only(['deposit_no', 'customer_id', 'amount', 'method']), null, $customer->branch_id);
            return $deposit;
        });
    }

    // Pays the deposit (or part of it) back to the customer.
    public static function refund(CustomerDeposit $deposit, array $data): CustomerDeposit
    {
        return DB::transaction(function () use ($deposit, $data) {
            $deposit = CustomerDeposit::lockForUpdate()->findOrFail($deposit->id);
            $amount = self::checkAmount($deposit, $data['amount'] ?? $deposit->held);
            $method = $data['method'] ?? 'cash';
            if ($method !== 'cash' && empty($data['bank_id'])) {
                throw new RuntimeException('Select the bank / mobile-banking account the refund is paid from.');
            }
            $old = $deposit->only(['refunded_amount', 'status']);
            $deposit->refunded_amount = Money::round((float) $deposit->refunded_amount + $amount);
            self::settleStatus($deposit);
            $deposit->save();
            $date = Carbon::parse($data['date'] ?? now())->toDateString();
            self::book('payments', $deposit, $deposit->deposit_no . '-R', $amount, $method, $method === 'cash' ? null : $data['bank_id'], $date, 'Security deposit refund');
            AuditLogger::log('deposit.refunded', $deposit, $old, $deposit->only(['refunded_amount', 'status']), $data['reason'] ?? null, $deposit->branch_id);
            return $deposit;
        });
    }

    // Uses the deposit (or part of it) to pay the customer's dues, e.g. at termination.
    public static function apply(CustomerDeposit $deposit, ?float $amount = null, ?string $reason = null): CustomerDeposit
    {
        return DB::transaction(function () use ($deposit, $amount, $reason) {
            $deposit = CustomerDeposit::lockForUpdate()->findOrFail($deposit->id);
            $amount = self::checkAmount($deposit, $amount ?? $deposit->held);
            $old = $deposit->only(['applied_amount', 'status']);
            $deposit->applied_amount = Money::round((float) $deposit->applied_amount + $amount);
            self::settleStatus($deposit);
            $deposit->save();
            CollectionService::receive(Customer::withTrashed()->findOrFail($deposit->customer_id), [
                'amount' => $amount,
                'method' => 'deposit',
                'reference' => $deposit->deposit_no,
                'notes' => "Security deposit {$deposit->deposit_no} applied to dues" . ($reason ? ". {$reason}" : ''),
            ]);
            AuditLogger::log('deposit.applied', $deposit, $old, $deposit->only(['applied_amount', 'status']), $reason, $deposit->branch_id);
            return $deposit;
        });
    }

    // Deposits still held for a customer.
    public static function held(int $customerId): float
    {
        return Money::round((float) CustomerDeposit::where('customer_id', $customerId)
            ->sum(DB::raw('amount - refunded_amount - applied_amount')));
    }

    private static function checkAmount(CustomerDeposit $deposit, $amount): float
    {
        $amount = Money::round((float) $amount);
        if ($amount <= 0) {
            throw new RuntimeException('Amount must be greater than zero.');
        }
        if ($amount > $deposit->held + Money::unit() / 2) {
            throw new RuntimeException('Only ' . Money::format($deposit->held) . " of deposit {$deposit->deposit_no} is still held.");
        }
        return $amount;
    }

    private static function settleStatus(CustomerDeposit $deposit): void
    {
        $deposit->status = $deposit->held <= Money::unit() / 2 ? 'released' : 'partially_released';
    }

    // Cash/bank books: receives (money in) or payments (money out).
    private static function book(string $table, CustomerDeposit $deposit, string $no, float $amount, string $method, $bankId, string $date, string $what): void
    {
        DB::table($table)->insert([
            'invoice' => $no,
            'customer_id' => $deposit->customer_id,
            'date' => $date,
            'type' => 'customer',
            'payment_method' => $method === 'cash' ? 'cash' : 'bank',
            'bank_id' => $bankId,
            'amount' => $amount,
            'previous_due' => 0,
            'note' => "{$what} {$deposit->deposit_no}",
            'status' => 'a',
            'created_by' => Auth::guard('web')->id(),
            'created_at' => now(),
            'ipAddress' => request()->ip() ?? '127.0.0.1',
            'branch_id' => $deposit->branch_id,
        ]);
    }
}
