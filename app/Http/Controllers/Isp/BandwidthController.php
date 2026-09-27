<?php

namespace App\Http\Controllers\Isp;

use App\Models\BandwidthPurchase;
use App\Services\Isp\AuditLogger;
use App\Services\Isp\BandwidthService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class BandwidthController extends IspController
{
    public function create()
    {
        return $this->page('bandwidth', 'Isp/Bandwidth');
    }

    public function index()
    {
        if ($r = $this->deny('bandwidth')) return $r;
        $today = now()->toDateString();
        return response()->json(BandwidthPurchase::where('branch_id', $this->branchId)->latest('start_date')->latest('id')->get()
            ->map(fn ($p) => $p->toArray() + ['running' => $p->start_date->toDateString() <= $today && (! $p->end_date || $p->end_date->toDateString() >= $today)]));
    }

    public function store(Request $request)
    {
        if ($r = $this->deny('bandwidth')) return $r;
        if ($r = $this->validateOrFail($request->all(), [
            'id' => 'nullable|integer',
            'provider' => 'required|max:150',
            'type' => 'required|in:' . implode(',', BandwidthPurchase::TYPES),
            'bandwidth_mbps' => 'required|numeric|gt:0|max:10000000',
            'monthly_cost' => 'required|numeric|min:0|max:999999999',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'notes' => 'nullable|max:500',
        ])) return $r;
        try {
            $data = $request->only(['provider', 'type', 'bandwidth_mbps', 'monthly_cost', 'start_date', 'end_date', 'notes']);
            if ($request->id) {
                $purchase = BandwidthPurchase::where('branch_id', $this->branchId)->findOrFail($request->id);
                $old = $purchase->only(array_keys($data));
                $purchase->update($data + ['updated_by' => $this->userId]);
                AuditLogger::log('bandwidth.updated', $purchase, $old, $purchase->only(array_keys($data)));
                return $this->ok('Bandwidth purchase updated');
            }
            $purchase = BandwidthPurchase::create($data + ['branch_id' => $this->branchId, 'created_by' => $this->userId]);
            AuditLogger::log('bandwidth.created', $purchase, null, $purchase->only(array_keys($data)));
            return $this->ok('Bandwidth purchase saved', ['id' => $purchase->id]);
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
    }

    public function destroy(Request $request)
    {
        if ($r = $this->deny('bandwidth')) return $r;
        $purchase = BandwidthPurchase::where('branch_id', $this->branchId)->findOrFail($request->id);
        AuditLogger::log('bandwidth.deleted', $purchase, $purchase->only(['provider', 'bandwidth_mbps', 'monthly_cost', 'start_date', 'end_date']), null);
        $purchase->delete();
        return $this->ok('Bandwidth purchase deleted');
    }

    public function usagePage()
    {
        return $this->page('ispReport', 'Isp/BandwidthUsage');
    }

    public function usage()
    {
        if ($r = $this->deny('ispReport')) return $r;
        return response()->json(BandwidthService::usage($this->branchId));
    }

    public function profitPage()
    {
        return $this->page('ispReport', 'Isp/BandwidthProfit');
    }

    public function profit(Request $request)
    {
        if ($r = $this->deny('ispReport')) return $r;
        if ($r = $this->validateOrFail($request->all(), ['from' => 'nullable|date_format:Y-m', 'to' => 'nullable|date_format:Y-m|after_or_equal:from'])) return $r;
        $to = $request->to ? Carbon::createFromFormat('Y-m-d', $request->to . '-01') : now();
        $from = $request->from ? Carbon::createFromFormat('Y-m-d', $request->from . '-01') : $to->copy()->subMonthsNoOverflow(11);
        if ($from->diffInMonths($to) > 35) {
            return send_error('Choose at most 36 months', null, 422);
        }
        return response()->json(BandwidthService::profit($this->branchId, $from, $to));
    }
}
