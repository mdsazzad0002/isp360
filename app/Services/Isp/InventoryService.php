<?php

namespace App\Services\Isp;

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventorySerial;
use App\Models\Onu;
use Illuminate\Support\Facades\DB;
use RuntimeException;

// Stock moves only through here. Places: supplier (in) | store | tech:<user id> | conn:<connection id> |
// out (written off). Quantity at a place = movements into it - movements out of it; serial-tracked
// units also keep their current place on inventory_serials, moved in the same transaction.
class InventoryService
{
    public static function tech(int $userId): string
    {
        return "tech:{$userId}";
    }

    public static function conn(int $connectionId): string
    {
        return "conn:{$connectionId}";
    }

    // quantity of an item at a place (good units only for serial-tracked items in the store)
    public static function stock(InventoryItem $item, string $place): float
    {
        if ($item->serialized) {
            return (float) InventorySerial::where('item_id', $item->id)->where('location', $place)->where('condition', 'good')->count();
        }
        $in = (float) InventoryMovement::where('item_id', $item->id)->where('to', $place)->sum('qty');
        $out = (float) InventoryMovement::where('item_id', $item->id)->where('from', $place)->sum('qty');
        return round($in - $out, 2);
    }

    // [item id => ['store' => q, 'techs' => q, 'installed' => q, 'faulty' => q]] for a branch
    public static function summary(int $branchId): array
    {
        $out = [];
        $add = function ($itemId, $place, $qty) use (&$out) {
            $key = $place === 'store' ? 'store' : (str_starts_with($place, 'tech:') ? 'techs' : (str_starts_with($place, 'conn:') ? 'installed' : null));
            if ($key) {
                $out[$itemId][$key] = round(($out[$itemId][$key] ?? 0) + $qty, 2);
            }
        };
        foreach (InventoryMovement::where('branch_id', $branchId)->whereHas('item', fn ($q) => $q->where('serialized', false))
            ->groupBy('item_id', 'to')->selectRaw('item_id, `to` as place, sum(qty) as q')->get() as $r) {
            $add($r->item_id, $r->place, (float) $r->q);
        }
        foreach (InventoryMovement::where('branch_id', $branchId)->whereHas('item', fn ($q) => $q->where('serialized', false))
            ->groupBy('item_id', 'from')->selectRaw('item_id, `from` as place, sum(qty) as q')->get() as $r) {
            $add($r->item_id, $r->place, -(float) $r->q);
        }
        foreach (InventorySerial::where('branch_id', $branchId)->groupBy('item_id', 'location', 'condition')->selectRaw('item_id, location, `condition`, count(*) as q')->get() as $r) {
            if ($r->condition === 'faulty') {
                $out[$r->item_id]['faulty'] = ($out[$r->item_id]['faulty'] ?? 0) + (int) $r->q;
            } else {
                $add($r->item_id, $r->location, (int) $r->q);
            }
        }
        return $out;
    }

    // [item id => quantity] a technician holds (good units)
    public static function heldBy(int $branchId, int $userId): array
    {
        $place = self::tech($userId);
        $out = [];
        foreach (InventoryItem::where('branch_id', $branchId)->get() as $item) {
            $q = self::stock($item, $place);
            if ($q > 0) {
                $out[$item->id] = $q;
            }
        }
        return $out;
    }

    /**
     * New stock from a supplier. Serial-tracked: $serials (one unit each, must be new);
     * otherwise $qty. Returns the movements.
     */
    public static function receive(InventoryItem $item, float $qty, array $serials, ?float $unitCost, ?string $note, ?int $userId): array
    {
        return DB::transaction(function () use ($item, $qty, $serials, $unitCost, $note, $userId) {
            InventoryItem::whereKey($item->id)->lockForUpdate()->first();
            $moves = [];
            if ($item->serialized) {
                $serials = self::cleanSerials($serials);
                if (! $serials) {
                    throw new RuntimeException('Enter the serial numbers, one per line.');
                }
                $taken = InventorySerial::where('branch_id', $item->branch_id)->whereIn('serial', $serials)->pluck('serial')->all();
                if ($taken) {
                    throw new RuntimeException('Already in stock records: ' . implode(', ', array_slice($taken, 0, 10)));
                }
                foreach ($serials as $s) {
                    $unit = InventorySerial::create(['branch_id' => $item->branch_id, 'item_id' => $item->id, 'serial' => $s, 'location' => 'store']);
                    $moves[] = self::record($item, 'receive', 1, 'supplier', 'store', ['serial_id' => $unit->id, 'unit_cost' => $unitCost, 'note' => $note], $userId);
                }
            } else {
                if ($qty <= 0) {
                    throw new RuntimeException('Enter the quantity received.');
                }
                $moves[] = self::record($item, 'receive', $qty, 'supplier', 'store', ['unit_cost' => $unitCost, 'note' => $note], $userId);
            }
            if ($unitCost !== null && $unitCost > 0) {
                $item->update(['unit_cost' => $unitCost]);
            }
            return $moves;
        });
    }

    /**
     * Moves stock from one place to another (issue, return, install, recover, write off).
     * $units: serial strings for serial-tracked items, else ignored in favour of $qty.
     * Refuses to move more than the source holds.
     */
    public static function move(InventoryItem $item, string $type, string $from, string $to, float $qty, array $units, array $extra, ?int $userId): array
    {
        if (! array_key_exists($type, InventoryMovement::TYPES) || in_array($type, ['receive', 'adjust'], true)) {
            throw new RuntimeException("Unknown stock movement {$type}");
        }
        return DB::transaction(function () use ($item, $type, $from, $to, $qty, $units, $extra, $userId) {
            InventoryItem::whereKey($item->id)->lockForUpdate()->first();
            $connectionId = str_starts_with($to, 'conn:') ? (int) substr($to, 5) : (str_starts_with($from, 'conn:') ? (int) substr($from, 5) : null);
            $extra += ['connection_id' => $connectionId];
            if (! $item->serialized) {
                if ($qty <= 0) {
                    throw new RuntimeException('Enter a quantity.');
                }
                $have = self::stock($item, $from);
                if ($from !== 'supplier' && $qty > $have + 0.0001) {
                    throw new RuntimeException("{$item->name}: only {$have} {$item->unit} at " . self::placeName($from) . '.');
                }
                return [self::record($item, $type, $qty, $from, $to, $extra, $userId)];
            }

            $serials = self::cleanSerials($units);
            if (! $serials) {
                throw new RuntimeException("{$item->name} is tracked by serial: pick the units.");
            }
            $rows = InventorySerial::where('item_id', $item->id)->whereIn('serial', $serials)->lockForUpdate()->get()->keyBy('serial');
            $moves = [];
            foreach ($serials as $s) {
                $unit = $rows->get($s);
                if (! $unit) {
                    throw new RuntimeException("Serial {$s} is not a {$item->name} in stock records.");
                }
                if ($unit->location !== $from) {
                    throw new RuntimeException("Serial {$s} is at " . self::placeName($unit->location) . ', not ' . self::placeName($from) . '.');
                }
                $unit->location = $to;
                $unit->connection_id = str_starts_with($to, 'conn:') ? (int) substr($to, 5) : null;
                if ($type === 'faulty') {
                    $unit->condition = 'faulty'; // stays faulty (and out of the usable stock) until written off
                }
                $unit->save();
                $moves[] = self::record($item, $type, 1, $from, $to, $extra + ['serial_id' => $unit->id], $userId);
            }
            return $moves;
        });
    }

    // Stock count in the store: records the difference so the store shows $counted (bulk items only).
    public static function adjust(InventoryItem $item, float $counted, ?string $note, ?int $userId): ?InventoryMovement
    {
        if ($item->serialized) {
            throw new RuntimeException('Serial-tracked items are corrected unit by unit (write off a missing serial, receive a found one).');
        }
        return DB::transaction(function () use ($item, $counted, $note, $userId) {
            InventoryItem::whereKey($item->id)->lockForUpdate()->first();
            $diff = round($counted - self::stock($item, 'store'), 2);
            if ($diff == 0) {
                return null;
            }
            return $diff > 0
                ? self::record($item, 'adjust', $diff, 'supplier', 'store', ['note' => $note ?: 'Stock count'], $userId)
                : self::record($item, 'adjust', -$diff, 'store', 'out', ['note' => $note ?: 'Stock count'], $userId);
        });
    }

    /**
     * An ONU now on a connection (linked or authorised on the OLT): its serial (or MAC) in stock moves
     * to that customer. Only from the store or a technician; a unit at another customer is left alone.
     */
    public static function onuInstalled(Onu $onu, ?int $userId = null, ?int $workOrderId = null): ?InventorySerial
    {
        if (! $onu->connection_id || (! $onu->serial && ! $onu->mac)) {
            return null;
        }
        // stock keeps serials upper case; an EPON ONU may be stocked under its MAC as the serial
        $keys = array_values(array_filter([$onu->serial ? strtoupper($onu->serial) : null, $onu->mac ? strtoupper($onu->mac) : null]));
        $unit = InventorySerial::where('branch_id', $onu->branch_id)
            ->where(fn ($q) => $q->whereIn('serial', $keys)->when($onu->mac, fn ($w) => $w->orWhere('mac', strtolower($onu->mac))))
            ->first();
        if (! $unit || ! ($unit->location === 'store' || str_starts_with($unit->location, 'tech:'))) {
            return null;
        }
        self::move(InventoryItem::findOrFail($unit->item_id), 'install', $unit->location, self::conn($onu->connection_id), 1, [$unit->serial],
            ['work_order_id' => $workOrderId, 'note' => 'ONU linked on the OLT page'], $userId);
        return $unit->fresh();
    }

    public static function placeName(string $place): string
    {
        return match (true) {
            $place === 'store' => 'the store',
            $place === 'supplier' => 'the supplier',
            $place === 'out' => 'written off',
            str_starts_with($place, 'tech:') => 'technician ' . (\App\Models\User::withTrashed()->find((int) substr($place, 5))?->name ?? substr($place, 5)),
            str_starts_with($place, 'conn:') => 'connection ' . (\App\Models\Connection::find((int) substr($place, 5))?->code ?? substr($place, 5)),
            default => $place,
        };
    }

    private static function cleanSerials(array $serials): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($s) => strtoupper(preg_replace('/[^A-Za-z0-9:\-]/', '', (string) $s)), $serials))));
    }

    private static function record(InventoryItem $item, string $type, float $qty, string $from, string $to, array $extra, ?int $userId): InventoryMovement
    {
        $move = InventoryMovement::create([
            'branch_id' => $item->branch_id, 'item_id' => $item->id, 'type' => $type, 'qty' => $qty, 'from' => $from, 'to' => $to,
            'serial_id' => $extra['serial_id'] ?? null, 'unit_cost' => $extra['unit_cost'] ?? null,
            'work_order_id' => $extra['work_order_id'] ?? null, 'connection_id' => $extra['connection_id'] ?? null,
            'note' => isset($extra['note']) ? mb_substr((string) $extra['note'], 0, 255) : null, 'created_by' => $userId,
        ]);
        AuditLogger::log("inventory.{$type}", $move, null, ['item' => $item->name, 'qty' => $qty, 'from' => $from, 'to' => $to, 'serial_id' => $move->serial_id], null, $item->branch_id);
        return $move;
    }
}
