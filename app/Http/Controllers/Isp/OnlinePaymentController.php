<?php

namespace App\Http\Controllers\Isp;

use App\Models\OnlinePayment;
use App\Services\Isp\OnlinePaymentService;
use Illuminate\Http\Request;

// Admin list of customer online payments, and the review of manual (TrxID) ones.
class OnlinePaymentController extends IspController
{
    public function create()
    {
        return $this->page('onlinePayment', 'Isp/OnlinePayment', [
            'canReview' => checkAccess('onlinePaymentReview'),
        ]);
    }

    public function index(Request $request)
    {
        if ($r = $this->deny('onlinePayment')) return $r;
        $query = OnlinePayment::with(['customer', 'customerPayment', 'reviewedBy'])
            ->where('branch_id', $this->branchId)
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->gateway, fn ($q, $g) => $q->where('gateway', $g))
            ->when(sqlDate($request->dateFrom), fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when(sqlDate($request->dateTo), fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->when($request->search, function ($q, $term) {
                $q->where(fn ($w) => $w->where('ref', 'like', "%{$term}%")->orWhere('trx_id', 'like', "%{$term}%")->orWhere('sender_number', 'like', "%{$term}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%")));
            });

        $pending = OnlinePayment::where('branch_id', $this->branchId)->where('status', 'pending_review')
            ->selectRaw('count(*) as count, coalesce(sum(amount),0) as amount')->first();

        return response()->json([
            'page' => $query->latest('id')->paginate(min(100, (int) ($request->per_page ?: 20))),
            'pending' => $pending,
        ]);
    }

    public function approve(Request $request)
    {
        if ($r = $this->deny('onlinePaymentReview')) return $r;
        if ($r = $this->validateOrFail($request->all(), [
            'id' => 'required|integer',
            'amount' => 'nullable|numeric|min:1',
            'note' => 'nullable|max:200',
        ])) return $r;
        try {
            $payment = OnlinePayment::where('branch_id', $this->branchId)->findOrFail($request->id);
            $payment = OnlinePaymentService::approve($payment, $request->amount ? (float) $request->amount : null, $request->note);
            return $this->ok('Payment approved and added to the customer account (receipt ' . $payment->customerPayment?->receipt_no . ').');
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
    }

    public function reject(Request $request)
    {
        if ($r = $this->deny('onlinePaymentReview')) return $r;
        if ($r = $this->validateOrFail($request->all(), ['id' => 'required|integer', 'reason' => 'required|max:200'])) return $r;
        try {
            $payment = OnlinePayment::where('branch_id', $this->branchId)->findOrFail($request->id);
            OnlinePaymentService::reject($payment, $request->reason);
            return $this->ok('Payment rejected.');
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
    }
}
