<?php

namespace App\Http\Controllers\Isp;

use App\Support\Money;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Services\Isp\CollectionService;
use App\Services\Isp\LedgerService;
use Illuminate\Http\Request;

// Customer 360: profile, connections, invoices, payments, ledger and history in one place.
class CustomerProfileController extends IspController
{
    public function show($id)
    {
        if (! checkAccess('customer')) {
            return \Inertia\Inertia::render('Error/Forbidden');
        }
        Customer::where('branch_id', $this->branchId)->findOrFail($id);
        return \Inertia\Inertia::render('Isp/CustomerProfile', [
            'customerId' => (int) $id,
            'can' => [
                'connection' => checkAccess('connection'),
                'connectionAction' => checkAccess('connectionAction'),
                'connectionSecret' => checkAccess('connectionSecret'),
                'connectionCredit' => checkAccess('connectionCredit'),
                'invoiceCreate' => checkAccess('invoiceCreate'),
                'invoiceVoid' => checkAccess('invoiceVoid'),
                'billingNote' => checkAccess('billingNote'),
                'payment' => checkAccess('ispPayment'),
                'paymentReverse' => checkAccess('ispPaymentReverse'),
                'refund' => checkAccess('ispRefund'),
            ],
        ]);
    }

    public function data(Request $request)
    {
        $customer = Customer::with(['area:id,name', 'zone:id,name', 'box:id,name,code', 'reseller'])
            ->where('branch_id', $this->branchId)->findOrFail($request->id);

        $connections = $customer->connections()->with(['package:id,name,price,download_mbps,upload_mbps,billing_cycle,network_profile', 'box:id,name,code'])->latest('id')->get();
        $invoices = Invoice::where('customer_id', $customer->id)->latest('invoice_date')->latest('id')->limit(200)->get();
        $payments = CustomerPayment::with('bank:id,name,bank_name', 'receivedBy')->where('customer_id', $customer->id)->latest('payment_date')->latest('id')->limit(200)->get()
            ->map(function ($p) {
                $p->unallocated = $p->unallocated();
                return $p;
            });

        return response()->json([
            'customer' => $customer,
            'connections' => $connections,
            'invoices' => $invoices,
            'payments' => $payments,
            'deposits' => \App\Models\CustomerDeposit::with('connection')->where('customer_id', $customer->id)->latest('id')->get(),
            'summary' => [
                'deposit_held' => \App\Services\Isp\DepositService::held($customer->id),
                'balance' => LedgerService::balance($customer->id),
                'advance' => CollectionService::advanceCredit($customer->id),
                'open_due' => Money::round((float) $invoices->whereIn('status', Invoice::OPEN_STATUSES)->sum('due')),
                'overdue' => Money::round((float) $invoices->where('status', 'overdue')->sum('due')),
                'total_billed' => Money::round((float) Invoice::where('customer_id', $customer->id)->whereNotIn('status', ['void', 'cancelled', 'draft'])->sum('total')),
                'total_paid' => Money::round((float) CustomerPayment::where('customer_id', $customer->id)->whereIn('status', ['completed', 'partially_refunded', 'refunded'])->sum('amount')),
            ],
        ]);
    }

    public function ledger(Request $request)
    {
        if (! checkAccess('customer') && ! checkAccess('ispReport')) {
            return send_error('You are not authorized', null, 403);
        }
        $customer = Customer::where('branch_id', $this->branchId)->findOrFail($request->id);
        return response()->json(LedgerService::statement($customer->id, sqlDate($request->dateFrom), sqlDate($request->dateTo)));
    }
}
