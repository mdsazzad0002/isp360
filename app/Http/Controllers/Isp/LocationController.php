<?php

namespace App\Http\Controllers\Isp;

use App\Models\Area;
use App\Models\Box;
use App\Models\Zone;
use App\Services\Isp\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// Zone -> Area -> Box. Areas keep using the existing /area endpoints; this adds zones and boxes.
class LocationController extends IspController
{
    public function zonePage()
    {
        return $this->page('zone', 'Isp/Zone');
    }

    public function areaPage()
    {
        return $this->page('area', 'Isp/Area');
    }

    public function boxPage()
    {
        return $this->page('box', 'Isp/Box');
    }

    public function zones()
    {
        return response()->json(
            Zone::where('branch_id', $this->branchId)->withCount(['areas', 'customers'])->orderBy('name')->get()
        );
    }

    public function storeZone(Request $request)
    {
        if ($r = $this->deny('zone')) return $r;
        $branchId = $this->branchId;
        if ($r = $this->validateOrFail($request->all(), [
            'id' => 'nullable|integer',
            'name' => ['required', 'max:100', Rule::unique('zones')->ignore($request->id)->where('branch_id', $branchId)->whereNull('deleted_at')],
            'code' => 'nullable|max:50',
        ])) return $r;

        try {
            $zone = $request->id ? Zone::where('branch_id', $branchId)->findOrFail($request->id) : new Zone(['branch_id' => $branchId, 'created_by' => $this->userId]);
            $old = $zone->exists ? $zone->only(['name', 'code', 'is_active']) : null;
            $zone->fill($request->only(['name', 'code', 'description']) + ['is_active' => $request->boolean('is_active', true)]);
            $zone->updated_by = $this->userId;
            $zone->ipAddress = $request->ip();
            $zone->save();
            AuditLogger::log($old ? 'zone.updated' : 'zone.created', $zone, $old, $zone->only(['name', 'code', 'is_active']));
            return $this->ok('Zone saved successfully', ['id' => $zone->id]);
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
    }

    public function destroyZone(Request $request)
    {
        if ($r = $this->deny('zone')) return $r;
        $zone = Zone::where('branch_id', $this->branchId)->findOrFail($request->id);
        if ($zone->areas()->exists() || $zone->customers()->exists()) {
            return send_error('This zone still has areas or customers. Move them first.', null, 422);
        }
        $zone->update(['deleted_by' => $this->userId, 'status' => 'd']);
        $zone->delete();
        AuditLogger::log('zone.deleted', $zone, $zone->only(['name']));
        return $this->ok('Zone deleted successfully');
    }

    public function boxes(Request $request)
    {
        $boxes = Box::with('area:id,name,zone_id', 'area.zone:id,name')
            ->withCount('customers')
            ->where('branch_id', $this->branchId)
            ->when($request->areaId, fn ($q, $id) => $q->where('area_id', $id))
            ->withCount(['connections as used_ports' => fn ($q) => $q->where('status', '!=', 'terminated')])
            ->orderBy('name')
            ->get()
            ->map(function ($box) {
                $box->available_ports = $box->capacity > 0 ? max(0, $box->capacity - $box->used_ports) : null;
                $box->display_name = $box->name . ($box->code ? " ({$box->code})" : '');
                return $box;
            });
        return response()->json($boxes);
    }

    public function storeBox(Request $request)
    {
        if ($r = $this->deny('box')) return $r;
        $branchId = $this->branchId;
        if ($r = $this->validateOrFail($request->all(), [
            'id' => 'nullable|integer',
            'area_id' => 'required|integer|exists:areas,id',
            'name' => ['required', 'max:100', Rule::unique('boxes')->ignore($request->id)->where('branch_id', $branchId)->whereNull('deleted_at')],
            'code' => 'nullable|max:50',
            'capacity' => 'nullable|integer|min:0|max:1024',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
        ])) return $r;

        try {
            $box = $request->id ? Box::where('branch_id', $branchId)->findOrFail($request->id) : new Box(['branch_id' => $branchId, 'created_by' => $this->userId]);
            if ($box->exists && (int) $request->capacity > 0 && $box->usedPortsCount() > (int) $request->capacity) {
                return send_error('Capacity cannot be lower than the ports already in use (' . $box->usedPortsCount() . ').', null, 422);
            }
            $old = $box->exists ? $box->only(['name', 'area_id', 'capacity']) : null;
            $box->fill($request->only(['area_id', 'name', 'code', 'location', 'latitude', 'longitude', 'description']) + [
                'capacity' => (int) $request->capacity,
                'is_active' => $request->boolean('is_active', true),
            ]);
            $box->updated_by = $this->userId;
            $box->ipAddress = $request->ip();
            $box->save();
            AuditLogger::log($old ? 'box.updated' : 'box.created', $box, $old, $box->only(['name', 'area_id', 'capacity']));
            return $this->ok('Box saved successfully', ['id' => $box->id]);
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
    }

    public function destroyBox(Request $request)
    {
        if ($r = $this->deny('box')) return $r;
        $box = Box::where('branch_id', $this->branchId)->findOrFail($request->id);
        if ($box->usedPortsCount() > 0 || $box->customers()->exists()) {
            return send_error('This box still has connections or customers. Move them to another box first.', null, 422);
        }
        $box->update(['deleted_by' => $this->userId, 'status' => 'd']);
        $box->delete();
        AuditLogger::log('box.deleted', $box, $box->only(['name']));
        return $this->ok('Box deleted successfully');
    }
}
