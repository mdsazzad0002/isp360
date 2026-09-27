<?php

namespace App\Http\Controllers\Isp;

use App\Models\Customer;
use App\Models\Invoice;
use App\Services\Isp\BillingService;
use App\Services\Isp\LedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class InvoiceController extends IspController
{
    public function create()
    {
        return $this->page('invoice', 'Isp/Invoice', [
            'can' => [
                'create' => checkAccess('invoiceCreate'),
                'generate' => checkAccess('invoiceGenerate'),
                'void' => checkAccess('invoiceVoid'),
                'note' => checkAccess('billingNote'),
                'payment' => checkAccess('ispPayment'),
            ],
        ]);
    }

    public function index(Request $request)
    {
        $query = Invoice::with(['customer:id,code,name,phone,area_id', 'customer.area:id,name', 'connection:id,code'])
            ->where('branch_id', $this->branchId)
            ->when($request->status, function ($q, $status) {
                $status === 'open' ? $q->whereIn('status', Invoice::OPEN_STATUSES) : $q->where('status', $status);
            })
            ->when($request->customerId, fn ($q, $id) => $q->where('customer_id', $id))
            ->when($request->areaId, fn ($q, $id) => $q->whereHas('customer', fn ($c) => $c->where('area_id', $id)))
            ->when(sqlDate($request->dateFrom), fn ($q, $d) => $q->where('invoice_date', '>=', $d))
            ->when(sqlDate($request->dateTo), fn ($q, $d) => $q->where('invoice_date', '<=', $d))
            ->when($request->search, function ($q, $term) {
                $q->where(fn ($w) => $w->where('invoice_no', 'like', "%{$term}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%")));
            });

        $totals = (clone $query)->whereNotIn('status', ['void', 'cancelled'])
            ->selectRaw('count(*) as count, coalesce(sum(total),0) as total, coalesce(sum(paid),0) as paid, coalesce(sum(due),0) as due')->first();

        $page = $query->latest('invoice_date')->latest('id')->paginate(min(100, (int) ($request->per_page ?: 20)));
        return response()->json(['page' => $page, 'totals' => $totals]);
    }

    public function show(Request $request)
    {
        $invoice = Invoice::with([
            'customer:id,code,name,phone,email,address,billing_address,area_id', 'customer.area:id,name',
            'connection:id,code,pppoe_username,package_id', 'connection.package:id,name',
            'items', 'allocations' => fn ($q) => $q->with('payment:id,receipt_no,payment_date,method,transaction_id')->latest('id'),
            'notes.createdBy', 'createdBy',
        ])->where('branch_id', $this->branchId)->findOrFail($request->id);

        return response()->json([
            'invoice' => $invoice,
            'customer_balance' => LedgerService::balance($invoice->customer_id),
        ]);
    }

    public function store(Request $request)
    {
        if ($r = $this->deny('invoiceCreate')) return $r;
        if ($r = $this->validateOrFail($request->all(), [
            'id' => 'nullable|integer',
            'customer_id' => 'required|integer',
            'connection_id' => 'nullable|integer',
            'invoice_date' => 'required|date',
            'due_date' => 'required|date|after_or_equal:invoice_date',
            'discount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|max:1000',
            'as_draft' => 'boolean',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|max:255',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.quantity' => 'required|numeric|gt:0',
            'items.*.discount' => 'nullable|numeric|min:0',
        ])) return $r;

        try {
            $customer = Customer::where('branch_id', $this->branchId)->findOrFail($request->customer_id);
            $data = $request->only(['connection_id', 'invoice_date', 'due_date', 'discount', 'notes']);
            if ($data['connection_id'] ?? null) {
                $customer->connections()->findOrFail($data['connection_id']);
            }
            if ($request->id) {
                $invoice = Invoice::where('branch_id', $this->branchId)->where('customer_id', $customer->id)->findOrFail($request->id);
                $invoice = BillingService::updateDraft($invoice, $data, $request->items);
                if (! $request->boolean('as_draft')) {
                    $invoice = BillingService::issue($invoice);
                }
            } else {
                $invoice = BillingService::createManual($customer, $data, $request->items, $request->boolean('as_draft'));
            }
            return $this->ok("Invoice {$invoice->invoice_no} saved" . ($invoice->status === 'draft' ? ' as draft' : ''), ['id' => $invoice->id]);
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
    }

    public function issue(Request $request)
    {
        if ($r = $this->deny('invoiceCreate')) return $r;
        try {
            $invoice = BillingService::issue(Invoice::where('branch_id', $this->branchId)->findOrFail($request->id));
            return $this->ok("Invoice {$invoice->invoice_no} issued");
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
    }

    public function void(Request $request)
    {
        if ($r = $this->deny('invoiceVoid')) return $r;
        if ($r = $this->validateOrFail($request->all(), ['id' => 'required|integer', 'reason' => 'required|min:3|max:255'])) return $r;
        try {
            $invoice = BillingService::void(Invoice::where('branch_id', $this->branchId)->findOrFail($request->id), $request->reason);
            return $this->ok("Invoice {$invoice->invoice_no} voided. Any money on it is now advance credit.");
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
    }

    public function note(Request $request)
    {
        if ($r = $this->deny('billingNote')) return $r;
        if ($r = $this->validateOrFail($request->all(), [
            'id' => 'required|integer',
            'type' => 'required|in:credit,debit',
            'amount' => 'required|numeric|gt:0',
            'reason' => 'required|min:3|max:255',
            'date' => 'nullable|date',
        ])) return $r;
        try {
            $note = BillingService::addNote(Invoice::where('branch_id', $this->branchId)->findOrFail($request->id), $request->type, (float) $request->amount, $request->reason, $request->date);
            return $this->ok(ucfirst($request->type) . " note {$note->note_no} saved");
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
    }

    // Runs the billing engine as of a date (same code path as the scheduler). Which month
    // that bills depends on the "billing_month" setting (current vs previous month).
    public function generate(Request $request)
    {
        if ($r = $this->deny('invoiceGenerate')) return $r;
        if ($r = $this->validateOrFail($request->all(), ['date' => 'required|date|before_or_equal:today'])) return $r;
        try {
            $stats = BillingService::generateForBranch($this->branchId, Carbon::parse($request->date)->startOfDay());
            $message = "{$stats['created']} invoice(s) generated";
            if ($stats['failed']) {
                $message .= ", {$stats['failed']} failed";
            }
            return $this->ok($message, ['stats' => $stats]);
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
    }
}
