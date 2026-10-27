<?php

namespace App\Http\Controllers\Isp;

use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderPhoto;
use App\Services\Isp\WorkOrderService;
use App\Support\Upload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

// Work orders: the office plans and assigns (workOrder); a technician works their own jobs from
// My Jobs (workOrderTech): start, photos, materials, ONU serial, signature, done.
class WorkOrderController extends IspController
{
    public function create(Request $request)
    {
        return $this->page('workOrder', 'Isp/WorkOrder', [
            'types' => WorkOrder::TYPES,
            'staff' => User::where('status', 'a')->orderBy('name')->get(['id', 'name', 'username']),
            'prefill' => $request->only(['connection_id', 'ticket_id', 'customer_id', 'type', 'open']),
        ]);
    }

    public function myJobs()
    {
        if (! checkAccess('workOrderTech') && ! checkAccess('workOrder')) {
            return \Inertia\Inertia::render('Error/Forbidden');
        }
        return \Inertia\Inertia::render('Isp/MyJobs', ['types' => WorkOrder::TYPES]);
    }

    public function index(Request $request)
    {
        $mine = $request->boolean('mine') || ! checkAccess('workOrder');
        if ($mine && ! checkAccess('workOrderTech') && ($r = $this->deny('workOrder'))) return $r;
        $base = WorkOrder::where('branch_id', $this->branchId)->when($mine, fn ($q) => $q->where('assigned_to', $this->userId));
        $query = (clone $base)->with(['customer', 'connection', 'assignee', 'ticket'])
            ->when($request->status === 'open', fn ($q) => $q->whereIn('status', WorkOrder::OPEN))
            ->when($request->status && $request->status !== 'open', fn ($q) => $q->where('status', $request->status))
            ->when($request->type, fn ($q, $t) => $q->where('type', $t))
            ->when($request->assigned_to === 'none', fn ($q) => $q->whereNull('assigned_to'))
            ->when($request->assigned_to && $request->assigned_to !== 'none', fn ($q) => $q->where('assigned_to', $request->assigned_to))
            ->when($request->date, fn ($q, $d) => $q->whereDate('scheduled_at', $d))
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w->where('wo_no', 'like', "%{$s}%")->orWhere('address', 'like', "%{$s}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%"))))
            ->orderByRaw("field(status, 'in_progress', 'assigned', 'new', 'done', 'cancelled')")
            ->orderByRaw('scheduled_at is null')->orderBy('scheduled_at')->latest('id');
        return response()->json([
            'page' => $query->paginate(min(100, (int) ($request->per_page ?: 30))),
            'counts' => (clone $base)->groupBy('status')->selectRaw('status, count(*) as total')->pluck('total', 'status'),
        ]);
    }

    public function show(Request $request)
    {
        $wo = WorkOrder::with(['customer', 'connection', 'assignee', 'ticket', 'photos', 'materials.createdBy'])->where('branch_id', $this->branchId)->findOrFail($request->id);
        if ($r = $this->denyJob($wo)) return $r;
        // equipment now at the customer's line, for a disconnection / pickup to bring back
        $installed = $wo->connection_id
            ? \App\Models\InventorySerial::with('item')->where('location', \App\Services\Isp\InventoryService::conn($wo->connection_id))->get(['id', 'item_id', 'serial'])
            : [];
        return response()->json($wo->toArray() + ['has_signature' => (bool) $wo->signature, 'installed' => $installed]);
    }

    public function store(Request $request)
    {
        if ($r = $this->deny('workOrder')) return $r;
        if ($r = $this->validateOrFail($request->all(), [
            'type' => 'required|in:' . implode(',', array_keys(WorkOrder::TYPES)),
            'priority' => 'nullable|in:' . implode(',', WorkOrder::PRIORITIES),
            'customer_id' => 'nullable|integer',
            'connection_id' => 'nullable|integer',
            'ticket_id' => 'nullable|integer',
            'assigned_to' => 'nullable|integer|exists:users,id',
            'scheduled_at' => 'nullable|date',
            'address' => 'nullable|max:255',
            'notes' => 'nullable|max:5000',
        ])) return $r;
        try {
            $wo = WorkOrderService::create($request->all(), $this->branchId, $this->userId);
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
        return $this->ok("Work order {$wo->wo_no} created", ['id' => $wo->id]);
    }

    public function assign(Request $request)
    {
        if ($r = $this->deny('workOrder')) return $r;
        if ($r = $this->validateOrFail($request->all(), ['id' => 'required|integer', 'assigned_to' => 'nullable|integer|exists:users,id', 'scheduled_at' => 'nullable|date'])) return $r;
        $wo = WorkOrder::where('branch_id', $this->branchId)->findOrFail($request->id);
        try {
            WorkOrderService::assign($wo, $request->assigned_to ? (int) $request->assigned_to : null, $request->scheduled_at, $this->userId);
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
        return $this->ok($wo->assigned_to ? 'Assigned' : 'Unassigned');
    }

    public function start(Request $request)
    {
        $wo = WorkOrder::where('branch_id', $this->branchId)->findOrFail($request->id);
        if ($r = $this->denyJob($wo)) return $r;
        try {
            WorkOrderService::start($wo, $this->userId);
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
        return $this->ok("Started {$wo->wo_no}");
    }

    public function complete(Request $request)
    {
        $wo = WorkOrder::where('branch_id', $this->branchId)->findOrFail($request->id);
        if ($r = $this->denyJob($wo)) return $r;
        if ($r = $this->validateOrFail($request->all(), [
            'result' => 'required|max:5000',
            'onu_serial' => 'nullable|max:64',
            'signature' => 'nullable|string|max:400000',
            'materials' => 'nullable|array|max:30',
            'materials.*.item_id' => 'required|integer',
            'materials.*.qty' => 'nullable|numeric|min:0',
            'materials.*.serials' => 'nullable|array',
            'recovered' => 'nullable|array|max:30',
            'recovered.*.item_id' => 'required|integer',
            'recovered.*.qty' => 'nullable|numeric|min:0',
            'recovered.*.serials' => 'nullable|array',
        ])) return $r;
        try {
            WorkOrderService::complete($wo, $request->only(['result', 'onu_serial', 'signature', 'materials', 'recovered']), $this->userId);
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
        return $this->ok("{$wo->wo_no} done");
    }

    public function cancel(Request $request)
    {
        if ($r = $this->deny('workOrder')) return $r;
        if ($r = $this->validateOrFail($request->all(), ['id' => 'required|integer', 'reason' => 'required|max:500'])) return $r;
        $wo = WorkOrder::where('branch_id', $this->branchId)->findOrFail($request->id);
        try {
            WorkOrderService::cancel($wo, $request->reason, $this->userId);
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
        return $this->ok("{$wo->wo_no} cancelled");
    }

    public function photo(Request $request)
    {
        $wo = WorkOrder::where('branch_id', $this->branchId)->findOrFail($request->id);
        if ($r = $this->denyJob($wo)) return $r;
        if ($r = $this->validateOrFail($request->all(), ['photo' => 'required|' . substr(Upload::rule(Upload::IMAGES, 6144), strlen('nullable|'))])) return $r;
        try {
            $photo = WorkOrderService::addPhoto($wo, $request->file('photo'), $this->userId);
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
        return $this->ok('Photo added', ['id' => $photo->id]);
    }

    // private files: a photo, or the customer's signature (type=signature, id = work order)
    public function file(Request $request, int $id)
    {
        if ($request->type === 'signature') {
            $wo = WorkOrder::where('branch_id', $this->branchId)->findOrFail($id);
            $path = $wo->signature;
        } else {
            $photo = WorkOrderPhoto::findOrFail($id);
            $wo = WorkOrder::where('branch_id', $this->branchId)->findOrFail($photo->work_order_id);
            $path = $photo->path;
        }
        if ($this->denyJob($wo) || ! Upload::privateExists($path)) {
            abort(404);
        }
        return Storage::disk(Upload::PRIVATE_DISK)->response($path, basename($path), ['Cache-Control' => 'private, max-age=3600']);
    }

    // the office sees every job; a technician only their own
    private function denyJob(WorkOrder $wo)
    {
        if (checkAccess('workOrder') || (checkAccess('workOrderTech') && (int) $wo->assigned_to === (int) $this->userId)) {
            return null;
        }
        return send_error('You are not authorized for this job', null, 403);
    }
}
