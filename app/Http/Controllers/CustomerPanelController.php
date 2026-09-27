<?php

namespace App\Http\Controllers;

use App\Support\Money;
use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Services\Isp\CollectionService;
use App\Services\Isp\LedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;

class CustomerPanelController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:customer');
    }

    public function dashboard()
    {
        $customer = Auth::guard('customer')->user();
        $customer->load('area:id,name', 'box:id,name');

        return \Inertia\Inertia::render('CustomerPortal/Dashboard', [
            'customer' => $customer->only(['id', 'code', 'name', 'phone', 'email', 'address', 'billing_address', 'account_status', 'area', 'box']),
            'summary' => [
                'balance' => LedgerService::balance($customer->id),
                'advance' => CollectionService::advanceCredit($customer->id),
                'overdue' => Money::round((float) Invoice::where('customer_id', $customer->id)->where('status', 'overdue')->sum('due')),
                'next_due_date' => Invoice::where('customer_id', $customer->id)->whereIn('status', Invoice::OPEN_STATUSES)->min('due_date'),
            ],
            'connections' => $customer->connections()->with('package:id,name,download_mbps,upload_mbps,price,billing_cycle')
                ->where('status', '!=', 'terminated')->latest('id')
                ->get(['id', 'code', 'package_id', 'connection_type', 'pppoe_username', 'status', 'suspension_reason', 'activation_date', 'expire_at', 'discount']),
            'invoices' => Invoice::where('customer_id', $customer->id)->whereNotIn('status', ['draft', 'cancelled'])
                ->latest('invoice_date')->latest('id')->limit(24)
                ->get(['id', 'invoice_no', 'invoice_date', 'due_date', 'period_start', 'period_end', 'total', 'paid', 'due', 'status']),
            'payments' => CustomerPayment::where('customer_id', $customer->id)->latest('payment_date')->latest('id')->limit(24)
                ->get(['id', 'receipt_no', 'payment_date', 'amount', 'method', 'transaction_id', 'status']),
        ]);
    }

    // My connections, split into active / inactive, each with its own billing and collection.
    // Same layout as the admin customer page, scoped to the logged-in customer.
    public function connections()
    {
        $customer = Auth::guard('customer')->user();
        $customer->load('area:id,name', 'zone:id,name', 'box:id,name');

        $connections = $customer->connections()
            ->with('package:id,name,download_mbps,upload_mbps,price,billing_cycle')
            ->latest('id')
            ->get(['id', 'code', 'package_id', 'connection_type', 'pppoe_username', 'static_ip', 'status', 'suspension_reason',
                'activation_date', 'suspended_at', 'terminated_at', 'expire_at', 'discount']);

        $invoices = Invoice::where('customer_id', $customer->id)->whereNotIn('status', ['draft', 'cancelled', 'void'])
            ->latest('invoice_date')->latest('id')->limit(300)
            ->get(['id', 'invoice_no', 'connection_id', 'invoice_date', 'due_date', 'period_start', 'period_end', 'total', 'paid', 'due', 'status']);

        // billed / collected / due per connection
        $byConnection = $invoices->groupBy('connection_id');
        $connections->each(function ($c) use ($byConnection) {
            $rows = $byConnection->get($c->id, collect());
            $c->billed = Money::round((float) $rows->sum('total'));
            $c->collected = Money::round((float) $rows->sum('paid'));
            $c->due = Money::round((float) $rows->whereIn('status', Invoice::OPEN_STATUSES)->sum('due'));
            $c->last_invoice_date = $rows->max('invoice_date');
        });

        return \Inertia\Inertia::render('CustomerPortal/Connections', [
            'customer' => $customer->only(['id', 'code', 'name', 'phone', 'email', 'address', 'image', 'account_status', 'area', 'zone', 'box']),
            'summary' => [
                'balance' => LedgerService::balance($customer->id),
                'advance' => CollectionService::advanceCredit($customer->id),
                'overdue' => Money::round((float) $invoices->where('status', 'overdue')->sum('due')),
                'total_billed' => Money::round((float) $invoices->sum('total')),
                'total_paid' => Money::round((float) CustomerPayment::where('customer_id', $customer->id)->whereIn('status', ['completed', 'partially_refunded', 'refunded'])->sum('amount')),
            ],
            'connections' => $connections,
            'invoices' => $invoices,
            'payments' => CustomerPayment::where('customer_id', $customer->id)->latest('payment_date')->latest('id')->limit(100)
                ->get(['id', 'receipt_no', 'payment_date', 'amount', 'method', 'transaction_id', 'status']),
        ]);
    }

    // Invoice detail for print; always scoped to the logged-in customer.
    public function invoice(Request $request)
    {
        $customer = Auth::guard('customer')->user();
        $invoice = Invoice::with(['items', 'connection:id,code,pppoe_username', 'customer:id,code,name,phone,address,billing_address'])
            ->where('customer_id', $customer->id)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->findOrFail($request->id);
        return response()->json(['invoice' => $invoice, 'customer_balance' => LedgerService::balance($customer->id)]);
    }

    public function statement(Request $request)
    {
        $customer = Auth::guard('customer')->user();
        return response()->json(LedgerService::statement($customer->id, sqlDate($request->dateFrom), sqlDate($request->dateTo)));
    }

    public function profile()
    {
        return \Inertia\Inertia::render('CustomerPortal/Profile', [
            'customer' => Auth::guard('customer')->user(),
        ]);
    }

    public function updateProfile(Request $request)
    {
        $customer = Auth::guard('customer')->user();

        normalizePhone($request);
        $validator = Validator::make($request->all(), [
            'name'  => 'required',
            'phone' => ['required', new \App\Rules\PhoneNumber],
        ]);
        if ($validator->fails()) return send_error("Validation Error", $validator->errors(), 422);

        try {
            $customer->name = $request->name;
            $customer->email = $request->email;
            $customer->phone = $request->phone;
            $customer->address = $request->address;
            if (!empty($request->password)) {
                $customer->password = Hash::make($request->password);
            }
            $customer->update();

            return response()->json(['status' => true, 'message' => "Profile has updated successfully"]);
        } catch (\Throwable $th) {
            return send_error('Something went wrong', $th->getMessage());
        }
    }

    public function logout()
    {
        try {
            Auth::guard('customer')->logout();
            Session::forget('portal');
            if (Session::pull('customer_impersonator_id') && Auth::guard('web')->check()) {
                Session::flash('success', 'Left the customer portal');
                return redirect('/customer');
            }
            Session::flash('success', 'Logout successfully');
            return redirect('/?portal=customer');
        } catch (\Throwable $e) {
            return send_error('Something went wrong', $e->getMessage());
        }
    }
}
