<?php

namespace App\Http\Controllers\Isp;

use App\Models\TaxRate;
use App\Services\Isp\AuditLogger;
use App\Services\Isp\TaxService;
use Illuminate\Http\Request;

// Company-wide sales tax rates, edited on the ISP settings page. A rate is switched off, never
// deleted: issued invoices keep their own snapshot, and a change only reaches new invoices.
class TaxRateController extends IspController
{
    // Active rates, for the package form.
    public function index()
    {
        return response()->json(TaxService::all()->where('is_active', true)->values());
    }

    public function store(Request $request)
    {
        if ($r = $this->deny('ispSettings')) return $r;
        if ($r = $this->validateOrFail($request->all(), [
            'id' => 'nullable|integer|exists:tax_rates,id',
            'name' => 'required|string|max:60',
            'rate' => 'required|numeric|min:0|max:100',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'sort' => 'nullable|integer|min:0|max:999',
        ])) return $r;

        try {
            $rate = $request->id ? TaxRate::findOrFail($request->id) : new TaxRate(['created_by' => $this->userId]);
            $old = $rate->exists ? $rate->only(['name', 'rate', 'is_default', 'is_active']) : null;
            $rate->fill([
                'name' => $request->name,
                'rate' => $request->rate,
                'is_default' => $request->boolean('is_default'),
                'is_active' => $request->has('is_active') ? $request->boolean('is_active') : true,
                'sort' => (int) $request->sort,
                'updated_by' => $this->userId,
            ])->save();
            TaxService::flush();
            AuditLogger::log($old ? 'tax_rate.updated' : 'tax_rate.created', null, $old, $rate->only(['id', 'name', 'rate', 'is_default', 'is_active']), null, $this->branchId);
            return $this->ok('Tax rate saved. It applies to new invoices only.', ['id' => $rate->id]);
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
    }
}
