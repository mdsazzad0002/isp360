<?php

namespace App\Http\Controllers\Isp;

use App\Models\Connection;
use App\Models\PaymentPromise;
use App\Services\Isp\PromiseService;
use Illuminate\Http\Request;

// Promise to pay: recorded from the connection panel, followed on Due report → Promises.
class PromiseController extends IspController
{
    public function index(Request $request)
    {
        if ($r = $this->deny('promiseToPay')) return $r;
        $base = PaymentPromise::where('branch_id', $this->branchId);
        $page = (clone $base)->with(['customer', 'connection', 'createdBy'])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->search, fn ($q, $s) => $q->whereHas('customer', fn ($c) => $c->where('name', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%")))
            ->orderByRaw("field(status, 'open', 'broken', 'kept', 'cancelled')")->orderBy('promised_date')->latest('id')
            ->paginate(min(100, (int) ($request->per_page ?: 25)));
        return response()->json([
            'page' => $page,
            'counts' => (clone $base)->where('created_at', '>=', now()->subDays(90))->groupBy('status')->selectRaw('status, count(*) as total')->pluck('total', 'status'),
        ]);
    }

    public function store(Request $request)
    {
        if ($r = $this->deny('promiseToPay')) return $r;
        if ($r = $this->validateOrFail($request->all(), [
            'connection_id' => 'required|integer',
            'promised_date' => 'required|date',
            'amount' => 'nullable|numeric|min:0',
            'note' => 'nullable|max:255',
        ])) return $r;
        try {
            $connection = Connection::where('branch_id', $this->branchId)->findOrFail($request->connection_id);
            $promise = PromiseService::create($connection, $request->promised_date, $request->filled('amount') ? (float) $request->amount : null, $request->note, $this->userId);
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
        return $this->ok('Promise recorded: the line stays on until ' . $promise->promised_date->format('d M Y') . ($promise->reactivated ? ' (switched back on)' : ''), ['id' => $promise->id]);
    }

    public function cancel(Request $request)
    {
        if ($r = $this->deny('promiseToPay')) return $r;
        if ($r = $this->validateOrFail($request->all(), ['id' => 'required|integer', 'reason' => 'required|max:255'])) return $r;
        $promise = PaymentPromise::where('branch_id', $this->branchId)->findOrFail($request->id);
        try {
            PromiseService::cancel($promise, $request->reason, $this->userId);
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
        return $this->ok('Promise cancelled');
    }
}
