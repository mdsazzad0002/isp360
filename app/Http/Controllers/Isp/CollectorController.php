<?php

namespace App\Http\Controllers\Isp;

use App\Models\Area;
use App\Models\CashHandover;
use App\Models\CollectionVisit;
use App\Models\Customer;
use App\Models\User;
use App\Services\Isp\CollectorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Collect (collector's phone: route, take cash, log visits, hand over cash) and Collectors (office:
// areas per collector, cash in hand, confirm handovers, visit log).
class CollectorController extends IspController
{
    public function create()
    {
        return $this->page('collector', 'Isp/Collect', ['outcomes' => CollectionVisit::OUTCOMES]);
    }

    public function route(Request $request)
    {
        if ($r = $this->deny('collector')) return $r;
        $areaIds = CollectorService::areaIds($this->userId);
        return response()->json([
            'customers' => CollectorService::route($this->branchId, $this->userId, $request->area_id ? (int) $request->area_id : null, $request->search),
            'areas' => Area::whereIn('id', $areaIds)->orderBy('name')->get(['id', 'name']),
            'cash' => CollectorService::cash($this->branchId, $this->userId),
            'today' => CollectionVisit::with('customer')->where('user_id', $this->userId)->whereDate('created_at', now()->toDateString())->latest('id')->limit(100)->get(),
        ]);
    }

    public function pay(Request $request)
    {
        if ($r = $this->deny('collector')) return $r;
        if ($r = $this->validateOrFail($request->all(), [
            'customer_id' => 'required|integer',
            'amount' => 'required|numeric|gt:0|max:9999999',
            'note' => 'nullable|max:255',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
        ])) return $r;
        try {
            $customer = Customer::where('branch_id', $this->branchId)->findOrFail($request->customer_id);
            $payment = CollectorService::collect($customer, $this->userId, (float) $request->amount, $request->note, $request->lat, $request->lng);
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
        return $this->ok("Received {$payment->amount} from {$customer->name} — receipt {$payment->receipt_no}", ['id' => $payment->id, 'receipt_no' => $payment->receipt_no]);
    }

    public function visit(Request $request)
    {
        if ($r = $this->deny('collector')) return $r;
        if ($r = $this->validateOrFail($request->all(), [
            'customer_id' => 'required|integer',
            'outcome' => 'required|in:not_home,promised,refused,other',
            'note' => 'nullable|max:255',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
        ])) return $r;
        try {
            $customer = Customer::where('branch_id', $this->branchId)->findOrFail($request->customer_id);
            CollectorService::visit($customer, $this->userId, $request->outcome, $request->note, $request->lat, $request->lng);
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
        return $this->ok('Visit noted');
    }

    public function handover(Request $request)
    {
        if ($r = $this->deny('collector')) return $r;
        if ($r = $this->validateOrFail($request->all(), ['amount' => 'required|numeric|gt:0', 'note' => 'nullable|max:255'])) return $r;
        try {
            $h = CollectorService::handover($this->branchId, $this->userId, (float) $request->amount, $request->note);
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
        return $this->ok("Handover of {$h->amount} recorded: the office confirms it when they count the cash");
    }

    // ---- office ----

    public function manage()
    {
        return $this->page('collectorManage', 'Isp/Collectors', [
            'staff' => User::where('status', 'a')->orderBy('name')->get(['id', 'name', 'username']),
            'areas' => Area::where('branch_id', $this->branchId)->orderBy('name')->get(['id', 'name']),
            'outcomes' => CollectionVisit::OUTCOMES,
        ]);
    }

    public function collectors()
    {
        if ($r = $this->deny('collectorManage')) return $r;
        $areas = DB::table('collector_areas')->where('branch_id', $this->branchId)->get()->groupBy('user_id');
        $handoverUsers = CashHandover::where('branch_id', $this->branchId)->distinct()->pluck('collector_id')->all();
        $ids = array_unique(array_merge($areas->keys()->map(fn ($k) => (int) $k)->all(), array_map('intval', $handoverUsers)));
        return response()->json(User::whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])->map(fn ($u) => [
            'id' => $u->id, 'name' => $u->name,
            'area_ids' => ($areas[$u->id] ?? collect())->pluck('area_id')->map(fn ($a) => (int) $a)->values(),
            'cash' => CollectorService::cash($this->branchId, $u->id),
            'visits_today' => CollectionVisit::where('user_id', $u->id)->whereDate('created_at', now()->toDateString())->count(),
        ]));
    }

    public function setAreas(Request $request)
    {
        if ($r = $this->deny('collectorManage')) return $r;
        if ($r = $this->validateOrFail($request->all(), ['user_id' => 'required|integer|exists:users,id', 'area_ids' => 'nullable|array', 'area_ids.*' => 'integer'])) return $r;
        $valid = Area::where('branch_id', $this->branchId)->whereIn('id', (array) $request->area_ids)->pluck('id')->all();
        CollectorService::setAreas($this->branchId, (int) $request->user_id, $valid);
        return $this->ok(count($valid) . ' area(s) set');
    }

    public function handovers(Request $request)
    {
        if ($r = $this->deny('collectorManage')) return $r;
        return response()->json(CashHandover::with(['collector', 'confirmedBy'])->where('branch_id', $this->branchId)
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->latest('id')->paginate(min(100, (int) ($request->per_page ?: 30))));
    }

    public function decide(Request $request)
    {
        if ($r = $this->deny('collectorManage')) return $r;
        if ($r = $this->validateOrFail($request->all(), ['id' => 'required|integer', 'confirm' => 'required|boolean', 'reason' => 'required_if:confirm,false|nullable|max:255'])) return $r;
        $h = CashHandover::where('branch_id', $this->branchId)->findOrFail($request->id);
        try {
            CollectorService::decide($h, $request->boolean('confirm'), $request->reason, $this->userId);
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
        return $this->ok($request->boolean('confirm') ? 'Cash received from ' . $h->collector?->name : 'Handover rejected');
    }

    public function visits(Request $request)
    {
        if ($r = $this->deny('collectorManage')) return $r;
        return response()->json(CollectionVisit::with(['customer', 'user'])->where('branch_id', $this->branchId)
            ->when($request->user_id, fn ($q, $id) => $q->where('user_id', $id))
            ->whereDate('created_at', $request->date ?: now()->toDateString())
            ->latest('id')->paginate(min(200, (int) ($request->per_page ?: 50))));
    }
}
