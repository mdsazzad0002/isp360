<?php

namespace App\Http\Controllers\Isp;

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventorySerial;
use App\Models\User;
use App\Services\Isp\AuditLogger;
use App\Services\Isp\InventoryService;
use Illuminate\Http\Request;

// Network → Inventory: items, stock in the store / with technicians / at customers, and every movement.
class InventoryController extends IspController
{
    public function create()
    {
        return $this->page('inventory', 'Isp/Inventory', [
            'categories' => InventoryItem::CATEGORIES,
            'types' => InventoryMovement::TYPES,
            'staff' => User::where('status', 'a')->orderBy('name')->get(['id', 'name', 'username']),
        ]);
    }

    public function index()
    {
        if ($r = $this->deny('inventory')) return $r;
        $summary = InventoryService::summary($this->branchId);
        return response()->json(InventoryItem::where('branch_id', $this->branchId)->orderByDesc('is_active')->orderBy('category')->orderBy('name')->get()
            ->map(function (InventoryItem $i) use ($summary) {
                $s = $summary[$i->id] ?? [];
                $store = $s['store'] ?? 0;
                return $i->toArray() + ['store' => $store, 'techs' => $s['techs'] ?? 0, 'installed' => $s['installed'] ?? 0, 'faulty' => $s['faulty'] ?? 0,
                    'low' => $i->is_active && $i->low_stock > 0 && $store <= $i->low_stock];
            }));
    }

    public function store(Request $request)
    {
        if ($r = $this->deny('inventory')) return $r;
        if ($r = $this->validateOrFail($request->all(), [
            'id' => 'nullable|integer',
            'name' => 'required|max:150',
            'category' => 'required|in:' . implode(',', array_keys(InventoryItem::CATEGORIES)),
            'unit' => 'required|in:pcs,m',
            'sku' => 'nullable|max:60',
            'unit_cost' => 'nullable|numeric|min:0',
            'low_stock' => 'nullable|numeric|min:0',
        ])) return $r;
        $item = $request->id ? InventoryItem::where('branch_id', $this->branchId)->findOrFail($request->id) : new InventoryItem(['branch_id' => $this->branchId, 'created_by' => $this->userId]);
        // serial tracking can't change once there is stock: the counts would no longer add up
        if ($item->exists && $item->serialized !== $request->boolean('serialized') && InventoryMovement::where('item_id', $item->id)->exists()) {
            return send_error('Serial tracking can\'t be switched once the item has stock movements.', null, 422);
        }
        $item->fill($request->only(['name', 'category', 'unit', 'sku']) + [
            'serialized' => $request->boolean('serialized') && $request->unit === 'pcs',
            'unit_cost' => $request->unit_cost ?: 0,
            'low_stock' => $request->low_stock ?: 0,
            'is_active' => $request->boolean('is_active', true),
        ])->save();
        AuditLogger::log($request->id ? 'inventory_item.updated' : 'inventory_item.created', $item, null, $item->only(['name', 'category', 'unit', 'serialized']));
        return $this->ok('Item saved', ['id' => $item->id]);
    }

    public function receive(Request $request)
    {
        if ($r = $this->deny('inventory')) return $r;
        if ($r = $this->validateOrFail($request->all(), [
            'item_id' => 'required|integer',
            'qty' => 'nullable|numeric|min:0|max:1000000',
            'serials' => 'nullable|string|max:100000',
            'unit_cost' => 'nullable|numeric|min:0',
            'note' => 'nullable|max:255',
        ])) return $r;
        $item = InventoryItem::where('branch_id', $this->branchId)->findOrFail($request->item_id);
        try {
            $moves = InventoryService::receive($item, (float) $request->qty, preg_split('/[\s,;]+/', (string) $request->serials), $request->filled('unit_cost') ? (float) $request->unit_cost : null, $request->note, $this->userId);
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
        $qty = $item->serialized ? count($moves) : (float) $request->qty;
        return $this->ok("{$qty} {$item->unit} of {$item->name} received into the store");
    }

    // issue to / return from a technician, mark faulty, write off
    public function move(Request $request)
    {
        if ($r = $this->deny('inventory')) return $r;
        if ($r = $this->validateOrFail($request->all(), [
            'item_id' => 'required|integer',
            'type' => 'required|in:issue,return,faulty,write_off',
            'tech_id' => 'required_if:type,issue,return|nullable|integer',
            'from' => 'nullable|max:30',
            'qty' => 'nullable|numeric|min:0',
            'serials' => 'nullable|array',
            'serials.*' => 'string|max:64',
            'note' => 'nullable|max:255',
        ])) return $r;
        $item = InventoryItem::where('branch_id', $this->branchId)->findOrFail($request->item_id);
        if ($request->tech_id && ! User::whereKey($request->tech_id)->exists()) {
            return send_error('Technician not found', null, 422);
        }
        $tech = $request->tech_id ? InventoryService::tech((int) $request->tech_id) : null;
        // where faulty / written-off stock comes from: the store or a technician, never a customer from here
        $from = $request->from && ($request->from === 'store' || preg_match('/^tech:\d+$/', $request->from)) ? $request->from : 'store';
        [$src, $dst] = match ($request->type) {
            'issue' => ['store', $tech],
            'return' => [$tech, 'store'],
            'faulty' => [$from, 'store'],
            'write_off' => [$from, 'out'],
        };
        if ($request->type === 'faulty' && ! $item->serialized) {
            return send_error('Bulk items that broke are written off.', null, 422);
        }
        try {
            $moves = InventoryService::move($item, $request->type, $src, $dst, (float) $request->qty, (array) $request->serials, ['note' => $request->note], $this->userId);
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
        return $this->ok(InventoryMovement::TYPES[$request->type] . ': ' . ($item->serialized ? count($moves) : (float) $request->qty) . " {$item->unit} of {$item->name}");
    }

    public function adjust(Request $request)
    {
        if ($r = $this->deny('inventory')) return $r;
        if ($r = $this->validateOrFail($request->all(), ['item_id' => 'required|integer', 'counted' => 'required|numeric|min:0', 'note' => 'nullable|max:255'])) return $r;
        $item = InventoryItem::where('branch_id', $this->branchId)->findOrFail($request->item_id);
        try {
            $move = InventoryService::adjust($item, (float) $request->counted, $request->note, $this->userId);
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
        return $this->ok($move ? 'Store count corrected (' . ($move->to === 'store' ? '+' : '−') . "{$move->qty})" : 'The count matches: nothing to correct');
    }

    public function serials(Request $request)
    {
        if ($r = $this->deny('inventory')) return $r;
        $page = InventorySerial::with(['item', 'connection.customer:id,code,name'])->where('branch_id', $this->branchId)
            ->when($request->item_id, fn ($q, $id) => $q->where('item_id', $id))
            ->when($request->location, fn ($q, $l) => $l === 'techs' ? $q->where('location', 'like', 'tech:%') : ($l === 'installed' ? $q->where('location', 'like', 'conn:%') : $q->where('location', $l)))
            ->when($request->condition, fn ($q, $c) => $q->where('condition', $c))
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w->where('serial', 'like', "%{$s}%")->orWhere('mac', 'like', "%{$s}%")))
            ->orderBy('item_id')->orderBy('serial')
            ->paginate(min(200, (int) ($request->per_page ?: 50)));
        $page->getCollection()->transform(fn ($u) => $u->toArray() + ['place' => InventoryService::placeName($u->location)]);
        return response()->json($page);
    }

    public function movements(Request $request)
    {
        if ($r = $this->deny('inventory')) return $r;
        $page = InventoryMovement::with(['item', 'serial', 'createdBy'])->where('branch_id', $this->branchId)
            ->when($request->item_id, fn ($q, $id) => $q->where('item_id', $id))
            ->when($request->type, fn ($q, $t) => $q->where('type', $t))
            ->when($request->tech_id, fn ($q, $id) => $q->where(fn ($w) => $w->where('from', "tech:{$id}")->orWhere('to', "tech:{$id}")))
            ->latest('id')->paginate(min(200, (int) ($request->per_page ?: 30)));
        $page->getCollection()->transform(fn ($m) => $m->toArray() + ['from_name' => InventoryService::placeName($m->from), 'to_name' => InventoryService::placeName($m->to)]);
        return response()->json($page);
    }

    // what a technician holds: their own (My Jobs), or anyone's for the inventory staff
    public function held(Request $request)
    {
        $userId = (int) ($request->user_id ?: $this->userId);
        if ($userId !== $this->userId && ($r = $this->deny('inventory'))) return $r;
        if ($userId === $this->userId && ! checkAccess('inventory') && ! checkAccess('workOrderTech') && ($r = $this->deny('workOrder'))) return $r;
        $held = InventoryService::heldBy($this->branchId, $userId);
        $items = InventoryItem::whereIn('id', array_keys($held))->get()->keyBy('id');
        $serials = InventorySerial::whereIn('item_id', array_keys($held))->where('location', InventoryService::tech($userId))->where('condition', 'good')->get()->groupBy('item_id');
        return response()->json(collect($held)->map(fn ($qty, $id) => ['item' => $items[$id]->only(['id', 'name', 'unit', 'serialized', 'category']), 'qty' => $qty,
            'serials' => ($serials[$id] ?? collect())->pluck('serial')->values()])->values());
    }
}
