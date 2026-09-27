<?php

namespace App\Http\Controllers\Isp;

use App\Models\Branch;
use App\Models\Region;
use App\Models\User;
use App\Services\Isp\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

// Regions: groups of branches with their regional managers (roadmap 3.2). Head office only.
class RegionController extends IspController
{
    public function create()
    {
        return $this->page('region', 'Isp/Region');
    }

    public function index()
    {
        if ($r = $this->deny('region')) return $r;
        return response()->json([
            'regions' => Region::withCount('branches')->orderBy('name')->get()->map(fn ($r) => $r->toArray() + [
                'branch_ids' => Branch::where('region_id', $r->id)->pluck('id'),
                'managers' => User::where('region_id', $r->id)->pluck('name'),
            ]),
            'branches' => Branch::orderBy('name')->get(['id', 'name', 'region_id']),
        ]);
    }

    public function store(Request $request)
    {
        if ($r = $this->deny('region')) return $r;
        if ($r = $this->headOfficeOnly()) return $r;
        if ($r = $this->validateOrFail($request->all(), [
            'id' => 'nullable|integer|exists:regions,id',
            'name' => ['required', 'string', 'max:120', Rule::unique('regions')->ignore($request->id)],
            'code' => 'nullable|string|max:20',
            'notes' => 'nullable|string|max:500',
            'branch_ids' => 'array',
            'branch_ids.*' => 'integer|exists:branches,id',
        ])) return $r;

        $region = DB::transaction(function () use ($request) {
            $region = $request->id ? Region::findOrFail($request->id) : new Region(['created_by' => $this->userId]);
            $old = $region->exists ? $region->only('name', 'code') + ['branch_ids' => Branch::where('region_id', $region->id)->pluck('id')->all()] : null;
            $region->fill($request->only('name', 'code', 'notes'))->save();
            if ($request->has('branch_ids')) {
                // a branch belongs to one region: listing it here moves it
                Branch::where('region_id', $region->id)->whereNotIn('id', $request->branch_ids ?: [0])->update(['region_id' => null]);
                Branch::whereIn('id', $request->branch_ids ?: [0])->update(['region_id' => $region->id]);
            }
            AuditLogger::log($old ? 'region.update' : 'region.create', $region, $old, $region->only('name', 'code') + ['branch_ids' => array_map('intval', $request->branch_ids ?? [])]);
            return $region;
        });
        return $this->ok('Region saved', ['id' => $region->id]);
    }

    public function destroy(Request $request)
    {
        if ($r = $this->deny('region')) return $r;
        if ($r = $this->headOfficeOnly()) return $r;
        $region = Region::findOrFail($request->id);
        DB::transaction(function () use ($region) {
            Branch::where('region_id', $region->id)->update(['region_id' => null]);
            User::where('region_id', $region->id)->update(['region_id' => null]);
            AuditLogger::log('region.delete', $region, $region->only('name', 'code'));
            $region->delete();
        });
        return $this->ok('Region deleted');
    }

    // A regional manager must not redraw regions (and so widen their own reach).
    private function headOfficeOnly()
    {
        return auth()->user()->seesAllBranches() ? null : send_error('Only head-office users can change regions', null, 403);
    }
}
