<?php

namespace App\Http\Controllers\Isp;

use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\PaymentAllocation;
use App\Services\Isp\CollectionService;
use App\Services\Isp\LedgerService;
use Illuminate\Http\Request;

class PaymentController extends IspController
{
    public function create(Request $request)
    {
        return $this->page('ispPayment', 'Isp/Payment', [
            'preselectCustomerId' => $request->customerId ? (int) $request->customerId : null,
            'can' => [
                'reverse' => checkAccess('ispPaymentReverse'),
                'refund' => checkAccess('ispRefund'),
            ],
        ]);
    }

    public function index(Request $request)
    {
        $query = CustomerPayment::with(['customer:id,code,name,phone', 'bank:id,name,bank_name', 'receivedBy'])
            ->where('branch_id', $this->branchId)
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->method, fn ($q, $m) => $q->where('method', $m))
            ->when($request->customerId, fn ($q, $id) => $q->where('customer_id', $id))
            ->when($request->receivedBy, fn ($q, $id) => $q->where('received_by', $id))
            ->when(sqlDate($request->dateFrom), fn ($q, $d) => $q->where('payment_date', '>=', $d))
            ->when(sqlDate($request->dateTo), fn ($q, $d) => $q->where('payment_date', '<=', $d))
            ->when($request->search, function ($q, $term) {
                $q->where(fn ($w) => $w->where('receipt_no', 'like', "%{$term}%")->orWhere('transaction_id', 'like', "%{$term}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%")));
            });

        $totals = (clone $query)->whereIn('status', ['completed', 'partially_refunded', 'refunded'])
            ->selectRaw('count(*) as count, coalesce(sum(amount),0) as amount, coalesce(sum(refunded_amount),0) as refunded')->first();

        $page = $query->latest('payment_date')->latest('id')->paginate(min(100, (int) ($request->per_page ?: 20)));
        $page->getCollection()->transform(function ($p) {
            $p->unallocated = $p->unallocated();
            return $p;
        });
        return response()->json(['page' => $page, 'totals' => $totals]);
    }

    public function show(Request $request)
    {
        $payment = CustomerPayment::with([
            'customer:id,code,name,phone,address', 'bank:id,name,bank_name', 'receivedBy',
            'allocations' => fn ($q) => $q->with('invoice:id,invoice_no,period_start,period_end,total,due,status')->latest('id'),
            'refunds.createdBy',
        ])->where('branch_id', $this->branchId)->findOrFail($request->id);
        $payment->unallocated = $payment->unallocated();

        return response()->json([
            'payment' => $payment,
            'customer_balance' => LedgerService::balance($payment->customer_id),
        ]);
    }

    // Open invoices + balance for the collection form.
    public function customerDues(Request $request)
    {
        $customer = Customer::where('branch_id', $this->branchId)->findOrFail($request->customerId);
        return response()->json([
            'balance' => LedgerService::balance($customer->id),
            'advance' => CollectionService::advanceCredit($customer->id),
            'invoices' => Invoice::where('customer_id', $customer->id)->whereIn('status', Invoice::OPEN_STATUSES)
                ->orderBy('due_date')->orderBy('id')
                ->get(['id', 'invoice_no', 'invoice_date', 'due_date', 'period_start', 'period_end', 'total', 'paid', 'due', 'status']),
        ]);
    }

    public function store(Request $request)
    {
        if ($r = $this->deny('ispPayment')) return $r;
        if ($r = $this->validateOrFail($request->all(), [
            'customer_id' => 'required|integer',
            'amount' => 'required|numeric|gt:0|max:9999999',
            'method' => 'required|in:' . implode(',', CustomerPayment::METHODS),
            'bank_id' => 'required_unless:method,cash|nullable|integer|exists:banks,id',
            'payment_date' => 'required|date|before_or_equal:today',
            'transaction_id' => 'nullable|max:100',
            'reference' => 'nullable|max:100',
            'notes' => 'nullable|max:500',
            'allocations' => 'nullable|array',
            'allocations.*' => 'nullable|numeric|min:0',
        ])) return $r;

        try {
            $customer = Customer::where('branch_id', $this->branchId)->findOrFail($request->customer_id);
            $allocations = array_filter((array) $request->allocations, fn ($v) => (float) $v > 0) ?: null;
            $payment = CollectionService::receive($customer, $request->only(['amount', 'method', 'bank_id', 'payment_date', 'transaction_id', 'reference', 'notes']) + ['source' => 'admin'], $allocations);
            return $this->ok("Payment {$payment->receipt_no} received", ['id' => $payment->id]);
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
    }

    public function reverse(Request $request)
    {
        if ($r = $this->deny('ispPaymentReverse')) return $r;
        if ($r = $this->validateOrFail($request->all(), ['id' => 'required|integer', 'reason' => 'required|min:3|max:255'])) return $r;
        try {
            $payment = CollectionService::reverse(CustomerPayment::where('branch_id', $this->branchId)->findOrFail($request->id), $request->reason);
            return $this->ok("Payment {$payment->receipt_no} reversed. Enter the correct payment if needed.");
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
    }

    // Move one allocation to another invoice of the same customer (payment put on the wrong invoice).
    public function reallocate(Request $request)
    {
        if ($r = $this->deny('ispPaymentReverse')) return $r;
        if ($r = $this->validateOrFail($request->all(), ['allocation_id' => 'required|integer', 'invoice_id' => 'nullable|integer', 'reason' => 'required|min:3|max:255'])) return $r;
        try {
            $allocation = PaymentAllocation::whereHas('payment', fn ($q) => $q->where('branch_id', $this->branchId))->findOrFail($request->allocation_id);
            if ($request->invoice_id) {
                $target = Invoice::where('customer_id', $allocation->payment->customer_id)->findOrFail($request->invoice_id);
                CollectionService::reallocate($allocation, $target, $request->reason);
                return $this->ok("Allocation moved to {$target->invoice_no}");
            }
            CollectionService::reverseAllocation($allocation, $request->reason);
            return $this->ok('Allocation removed; the amount is now advance credit');
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
    }

    public function refund(Request $request)
    {
        if ($r = $this->deny('ispRefund')) return $r;
        if ($r = $this->validateOrFail($request->all(), [
            'id' => 'required|integer',
            'amount' => 'required|numeric|gt:0',
            'method' => 'required|in:' . implode(',', CustomerPayment::METHODS),
            'bank_id' => 'required_unless:method,cash|nullable|integer|exists:banks,id',
            'refund_date' => 'nullable|date',
            'reason' => 'required|min:3|max:255',
        ])) return $r;
        try {
            $refund = CollectionService::refund(CustomerPayment::where('branch_id', $this->branchId)->findOrFail($request->id), $request->all());
            return $this->ok("Refund {$refund->refund_no} saved");
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
    }
}
