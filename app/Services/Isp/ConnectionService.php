<?php

namespace App\Services\Isp;

use App\Jobs\SyncConnectionToNetwork;
use App\Models\Connection;
use App\Models\ConnectionHistory;
use App\Models\Customer;
use App\Models\Package;
use App\Models\PackageHistory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

// Connection lifecycle: create -> activate -> suspend <-> reactivate -> terminate,
// plus package / credential / box changes. Every step leaves a connection_histories
// row and an audit log entry, then asks the network driver to apply the new state.
class ConnectionService
{
    // Fields whose changes get their own history action so troubleshooting can filter them.
    private const TRACKED = [
        'pppoe_username' => 'username_changed',
        'pppoe_password' => 'password_changed',
        'static_ip' => 'ip_changed',
        'mac_address' => 'mac_changed',
        'box_id' => 'box_changed',
        'connection_type' => 'type_changed',
        'router_id' => 'router_changed',
        'discount' => 'discount_changed',
    ];

    public static function create(array $data, int $branchId): Connection
    {
        return DB::transaction(function () use ($data, $branchId) {
            $customer = Customer::where('branch_id', $branchId)->findOrFail($data['customer_id']);
            $package = Package::where('branch_id', $branchId)->findOrFail($data['package_id']);
            if (! $package->usableFor($customer)) {
                throw new RuntimeException($package->unusableReason($customer));
            }
            self::assertBoxHasRoom($data['box_id'] ?? null);
            $boxId = self::resolveArea($customer, $data, $branchId);

            $connection = Connection::create([
                'code' => SequenceService::next($branchId, 'connection', IspSettings::get($branchId, 'connection_prefix')),
                'customer_id' => $customer->id,
                'package_id' => $package->id,
                'box_id' => $boxId,
                'router_id' => $data['router_id'] ?? null,
                'connection_type' => $data['connection_type'] ?? 'pppoe',
                'pppoe_username' => $data['pppoe_username'] ?? null,
                'pppoe_password' => $data['pppoe_password'] ?? null,
                'static_ip' => $data['static_ip'] ?? null,
                'mac_address' => $data['mac_address'] ?? null,
                'discount' => $data['discount'] ?? 0,
                'installation_date' => $data['installation_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'pending',
                'branch_id' => $branchId,
                'created_by' => Auth::guard('web')->id(),
                'ipAddress' => request()->ip(),
            ]);

            self::history($connection, 'created', null, [
                'package' => $package->name, 'price' => (float) $package->price, 'type' => $connection->connection_type,
            ]);
            PackageHistory::create([
                'connection_id' => $connection->id,
                'old_package_id' => null,
                'new_package_id' => $package->id,
                'old_price' => null,
                'new_price' => $package->price,
                'effective_date' => $data['installation_date'] ?? now()->toDateString(),
                'reason' => 'Initial package',
                'changed_by' => Auth::guard('web')->id(),
                'created_at' => now(),
            ]);
            AuditLogger::log('connection.created', $connection, null, $connection->only(['code', 'customer_id', 'package_id', 'pppoe_username', 'static_ip']));

            if (! empty($data['activate_now'])) {
                self::activate($connection, $data['activation_date'] ?? now()->toDateString());
            }

            return $connection->fresh();
        });
    }

    public static function update(Connection $connection, array $data): Connection
    {
        return DB::transaction(function () use ($connection, $data) {
            $connection = Connection::lockForUpdate()->findOrFail($connection->id);
            if ($connection->status === 'terminated') {
                throw new RuntimeException('A terminated connection cannot be edited.');
            }
            if (array_key_exists('box_id', $data) && $data['box_id'] != $connection->box_id) {
                self::assertBoxHasRoom($data['box_id']);
            }

            $allowed = ['connection_type', 'pppoe_username', 'static_ip', 'mac_address', 'box_id', 'router_id', 'discount', 'installation_date', 'notes'];
            $changes = array_intersect_key($data, array_flip($allowed));
            if (! empty($data['pppoe_password'])) {
                $changes['pppoe_password'] = $data['pppoe_password'];
            }

            $old = [];
            foreach ($changes as $key => $value) {
                $current = $connection->{$key} instanceof Carbon ? $connection->{$key}->toDateString() : $connection->{$key};
                if ((string) $current === (string) $value) {
                    unset($changes[$key]);
                    continue;
                }
                $old[$key] = $current;
            }
            if (empty($changes)) {
                return $connection;
            }

            $connection->fill($changes);
            $connection->updated_by = Auth::guard('web')->id();
            $connection->ipAddress = request()->ip();
            $connection->save();

            foreach ($changes as $key => $value) {
                if (isset(self::TRACKED[$key])) {
                    // never write the secret itself into history/audit
                    $isSecret = $key === 'pppoe_password';
                    self::history($connection, self::TRACKED[$key], $isSecret ? null : [$key => $old[$key]], $isSecret ? null : [$key => $value], $data['reason'] ?? null);
                }
            }
            $safeOld = array_diff_key($old, ['pppoe_password' => 1]);
            $safeNew = array_diff_key($changes, ['pppoe_password' => 1]);
            AuditLogger::log('connection.updated', $connection, $safeOld, $safeNew + (isset($changes['pppoe_password']) ? ['pppoe_password' => '(changed)'] : []), $data['reason'] ?? null);

            self::syncNetwork($connection);
            return $connection;
        });
    }

    public static function activate(Connection $connection, $date = null): Connection
    {
        return DB::transaction(function () use ($connection, $date) {
            $connection = Connection::lockForUpdate()->findOrFail($connection->id);
            if ($connection->status !== 'pending' && $connection->status !== 'inactive') {
                throw new RuntimeException("Only a pending or inactive connection can be activated (current: {$connection->status}).");
            }
            $date = Carbon::parse($date ?? now())->startOfDay();

            $connection->status = 'active';
            $connection->activation_date = $connection->activation_date ?? $date;
            if (empty($connection->next_billing_date)) {
                $connection->next_billing_date = self::firstBillingDate($connection->branch_id, $date);
            }
            $connection->save();

            self::history($connection, 'activated', null, ['activation_date' => $date->toDateString(), 'next_billing_date' => $connection->next_billing_date?->toDateString()]);
            AuditLogger::log('connection.activated', $connection, null, ['activation_date' => $date->toDateString()]);
            self::syncNetwork($connection);
            return $connection;
        });
    }

    // $userId null = suspended by the system (overdue automation).
    public static function suspend(Connection $connection, string $reason, bool $bySystem = false): Connection
    {
        return DB::transaction(function () use ($connection, $reason, $bySystem) {
            $connection = Connection::lockForUpdate()->findOrFail($connection->id);
            if ($connection->status !== 'active') {
                throw new RuntimeException("Only an active connection can be suspended (current: {$connection->status}).");
            }
            $connection->status = 'suspended';
            $connection->suspension_reason = $reason;
            $connection->suspended_at = now();
            $connection->suspended_by = $bySystem ? null : Auth::guard('web')->id();
            $connection->save();

            self::history($connection, 'suspended', ['status' => 'active'], ['status' => 'suspended', 'by' => $bySystem ? 'System' : 'User'], $reason);
            AuditLogger::log('connection.suspended', $connection, ['status' => 'active'], ['status' => 'suspended'], $reason, $connection->branch_id);
            self::syncNetwork($connection);
            return $connection;
        });
    }

    public static function reactivate(Connection $connection, ?string $reason = null, bool $bySystem = false): Connection
    {
        return DB::transaction(function () use ($connection, $reason, $bySystem) {
            $connection = Connection::lockForUpdate()->findOrFail($connection->id);
            if ($connection->status !== 'suspended') {
                throw new RuntimeException("Only a suspended connection can be reactivated (current: {$connection->status}).");
            }
            $old = ['status' => 'suspended', 'suspension_reason' => $connection->suspension_reason];
            $connection->status = 'active';
            $connection->suspension_reason = null;
            $connection->suspended_at = null;
            $connection->suspended_by = null;
            $connection->save();

            self::history($connection, 'reactivated', $old, ['status' => 'active', 'by' => $bySystem ? 'System' : 'User'], $reason);
            AuditLogger::log('connection.reactivated', $connection, $old, ['status' => 'active'], $reason, $connection->branch_id);
            self::syncNetwork($connection);
            return $connection;
        });
    }

    public static function deactivate(Connection $connection, string $reason): Connection
    {
        return self::simpleTransition($connection, ['active', 'suspended'], 'inactive', 'deactivated', $reason);
    }

    public static function terminate(Connection $connection, string $reason): Connection
    {
        return DB::transaction(function () use ($connection, $reason) {
            $connection = self::simpleTransition($connection, ['pending', 'active', 'suspended', 'inactive'], 'terminated', 'terminated', $reason);
            $connection->terminated_at = now();
            $connection->save();
            return $connection;
        });
    }

    // Basic version: the new price applies from the next invoice. Prorated mid-period
    // change billing can be added on top of the package_histories rows later.
    public static function changePackage(Connection $connection, int $packageId, $effectiveDate, ?string $reason): Connection
    {
        return DB::transaction(function () use ($connection, $packageId, $effectiveDate, $reason) {
            $connection = Connection::with('package')->lockForUpdate()->findOrFail($connection->id);
            if (in_array($connection->status, ['terminated'], true)) {
                throw new RuntimeException('A terminated connection cannot change package.');
            }
            if ($connection->package_id == $packageId) {
                throw new RuntimeException('The connection is already on this package.');
            }
            $new = Package::where('branch_id', $connection->branch_id)->where('is_active', true)->findOrFail($packageId);
            $customer = Customer::withTrashed()->findOrFail($connection->customer_id);
            if (! $new->usableFor($customer)) {
                throw new RuntimeException($new->unusableReason($customer));
            }
            $old = $connection->package;

            PackageHistory::create([
                'connection_id' => $connection->id,
                'old_package_id' => $old?->id,
                'new_package_id' => $new->id,
                'old_price' => $old?->price,
                'new_price' => $new->price,
                'effective_date' => Carbon::parse($effectiveDate ?? now())->toDateString(),
                'reason' => $reason,
                'changed_by' => Auth::guard('web')->id(),
                'created_at' => now(),
            ]);

            $connection->package_id = $new->id;
            $connection->updated_by = Auth::guard('web')->id();
            $connection->save();

            $oldValues = ['package' => $old?->name, 'price' => (float) ($old?->price ?? 0)];
            $newValues = ['package' => $new->name, 'price' => (float) $new->price];
            self::history($connection, 'package_changed', $oldValues, $newValues, $reason);
            AuditLogger::log('connection.package_changed', $connection, $oldValues, $newValues, $reason);
            self::syncNetwork($connection);
            return $connection->fresh('package');
        });
    }

    public static function firstBillingDate(int $branchId, Carbon $activation): Carbon
    {
        return match (IspSettings::get($branchId, 'first_month_billing')) {
            'full' => $activation->copy()->startOfMonth(),
            'next_month' => $activation->copy()->addMonthNoOverflow()->startOfMonth(),
            default => $activation->copy(),
        };
    }

    private static function simpleTransition(Connection $connection, array $from, string $to, string $action, string $reason): Connection
    {
        return DB::transaction(function () use ($connection, $from, $to, $action, $reason) {
            $connection = Connection::lockForUpdate()->findOrFail($connection->id);
            if (! in_array($connection->status, $from, true)) {
                throw new RuntimeException("Cannot move a {$connection->status} connection to {$to}.");
            }
            $old = ['status' => $connection->status];
            $connection->status = $to;
            $connection->updated_by = Auth::guard('web')->id();
            $connection->save();

            self::history($connection, $action, $old, ['status' => $to], $reason);
            AuditLogger::log('connection.' . $action, $connection, $old, ['status' => $to], $reason);
            self::syncNetwork($connection);
            return $connection;
        });
    }

    // Every new connection belongs to an area: the chosen one, else the box's, else the
    // customer's. The box must sit in that area. A customer without a location takes the
    // connection's area/zone (and box). Returns the box id to use.
    private static function resolveArea(Customer $customer, array $data, int $branchId): ?int
    {
        $box = ! empty($data['box_id']) ? \App\Models\Box::where('branch_id', $branchId)->findOrFail($data['box_id']) : null;
        $areaId = $data['area_id'] ?? $box?->area_id ?? $customer->area_id;
        if (! $areaId) {
            throw new RuntimeException('Select the area of this connection.');
        }
        $area = \App\Models\Area::where('branch_id', $branchId)->findOrFail($areaId);
        if ($box && (int) $box->area_id !== (int) $area->id) {
            throw new RuntimeException("Box {$box->name} is not in area {$area->name}.");
        }
        if (! $box && $customer->box_id && (int) \App\Models\Box::whereKey($customer->box_id)->value('area_id') === (int) $area->id) {
            $box = \App\Models\Box::find($customer->box_id);
        }

        if (! $customer->area_id) {
            $customer->area_id = $area->id;
            $customer->zone_id = $customer->zone_id ?: $area->zone_id;
            $customer->box_id = $customer->box_id ?: $box?->id;
            $customer->save();
        }
        return $box?->id;
    }

    private static function assertBoxHasRoom($boxId): void
    {
        if (empty($boxId)) {
            return;
        }
        $box = \App\Models\Box::lockForUpdate()->find($boxId);
        if ($box && $box->capacity > 0 && $box->usedPortsCount() >= $box->capacity) {
            throw new RuntimeException("Box {$box->name} is full ({$box->capacity} ports).");
        }
    }

    private static function history(Connection $connection, string $action, ?array $old, ?array $new, ?string $reason = null): void
    {
        ConnectionHistory::create([
            'connection_id' => $connection->id,
            'action' => $action,
            'old_values' => $old,
            'new_values' => $new,
            'reason' => $reason ? mb_substr($reason, 0, 255) : null,
            'created_by' => Auth::guard('web')->id(),
            'created_at' => now(),
        ]);
    }

    private static function syncNetwork(Connection $connection): void
    {
        Connection::whereKey($connection->id)->update(['network_sync_status' => 'pending']);
        SyncConnectionToNetwork::dispatch($connection->id)->afterCommit();
    }
}
