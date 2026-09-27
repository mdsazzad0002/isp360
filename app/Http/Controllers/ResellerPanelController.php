<?php

namespace App\Http\Controllers;

use App\Support\Money;
use App\Models\Connection;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\ResellerTransaction;
use App\Services\Isp\AuditLogger;
use App\Services\Isp\CollectionService;
use App\Services\Isp\ResellerLedgerService;
use App\Services\Isp\ResellerPackageService;
use App\Services\Isp\ResellerWalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ResellerPanelController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:reseller');
    }

    public function dashboard()
    {
        $reseller = Auth::guard('reseller')->user();
        $customers = Customer::where('reseller_id', $reseller->id)->latest()->get();

        return \Inertia\Inertia::render('Reseller/Dashboard', [
            'customers' => $customers,
            'customerCount' => $customers->count(),
            'totalDue' => Money::round((float) $customers->where('ledger_balance', '>', 0)->sum('ledger_balance')),
            'connectionCount' => Connection::whereIn('customer_id', $customers->pluck('id'))->where('status', 'active')->count(),
            'wallet' => ResellerWalletService::summary($reseller->id),
        ]);
    }

    public function profile()
    {
        return \Inertia\Inertia::render('Reseller/Profile', [
            'reseller' => Auth::guard('reseller')->user(),
        ]);
    }

    public function updateProfile(Request $request)
    {
        $reseller = Auth::guard('reseller')->user();

        normalizePhone($request);
        $validator = Validator::make($request->all(), [
            'name'  => 'required',
            'phone' => ['required', new \App\Rules\PhoneNumber],
        ]);
        if ($validator->fails()) return send_error("Validation Error", $validator->errors(), 422);

        try {
            $reseller->name = $request->name;
            $reseller->email = $request->email;
            $reseller->phone = $request->phone;
            $reseller->address = $request->address;
            if (!empty($request->password)) {
                $reseller->password = Hash::make($request->password);
            }
            $reseller->update();

            return response()->json(['status' => true, 'message' => "Profile has updated successfully"]);
        } catch (\Throwable $th) {
            return send_error('Something went wrong', $th->getMessage());
        }
    }

    public function packages()
    {
        $reseller = Auth::guard('reseller')->user();

        return \Inertia\Inertia::render('Reseller/Package', [
            'reseller' => $reseller->only(['id', 'name']),
            'basePackages' => ResellerPackageService::basePackages($reseller),
        ]);
    }

    public function getPackages()
    {
        $reseller = Auth::guard('reseller')->user();

        $packages = Package::where('reseller_id', $reseller->id)
            ->with('basePackage')
            ->withCount(['connections as active_connections' => fn ($q) => $q->whereIn('status', ['active', 'suspended'])])
            ->orderBy('price')
            ->get()
            ->each(fn ($p) => $p->base_changes = ResellerPackageService::baseChanges($p) ?: null);

        return response()->json($packages);
    }

    // A reseller customizes a company package (name + their own price, never below the
    // company price). Saving also accepts the company's latest changes to the base package.
    public function storePackage(Request $request)
    {
        $reseller = Auth::guard('reseller')->user();

        $validator = Validator::make($request->all(), [
            'id' => 'nullable|integer',
            'base_package_id' => 'required|integer',
            'name' => ['required', 'max:100', Rule::unique('packages')->ignore($request->id)->where('reseller_id', $reseller->id)->whereNull('deleted_at')],
            'code' => 'nullable|max:50',
            'price' => 'required|numeric|min:0|max:9999999',
            'description' => 'nullable|max:500',
            'price_change_reason' => 'nullable|max:255',
        ]);
        if ($validator->fails()) return send_error("Validation Error", $validator->errors(), 422);

        try {
            ResellerPackageService::save($reseller, $request->only(['id', 'base_package_id', 'name', 'code', 'price', 'description', 'price_change_reason']) + [
                'is_active' => $request->boolean('is_active', true),
            ]);
            return response()->json(['status' => true, 'message' => 'Package saved. Changes apply from the next invoice.']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return send_error('Package not found', null, 404);
        } catch (\RuntimeException $e) {
            return send_error($e->getMessage(), null, 422);
        } catch (\Throwable $th) {
            return send_error('Something went wrong', $th->getMessage());
        }
    }

    public function destroyPackage(Request $request)
    {
        $reseller = Auth::guard('reseller')->user();
        $package = Package::where('reseller_id', $reseller->id)->find($request->id);
        if (! $package) return send_error('Package not found', null, 404);

        if (Connection::where('package_id', $package->id)->where('status', '!=', 'terminated')->exists()) {
            return send_error('Connections still use this package. Deactivate it instead.', null, 422);
        }
        $package->update(['status' => 'd']);
        $package->delete();
        AuditLogger::log('package.deleted', $package, $package->only(['name', 'price']));

        return response()->json(['status' => true, 'message' => 'Package deleted successfully']);
    }

    // ── My Connections ──
    public function connections()
    {
        $reseller = Auth::guard('reseller')->user();
        $connections = Connection::with(['customer:id,code,name,phone,address,ledger_balance', 'package:id,name,price,download_mbps,upload_mbps,billing_cycle'])
            ->whereHas('customer', fn ($q) => $q->where('reseller_id', $reseller->id))
            ->latest('id')
            ->get(['id', 'code', 'customer_id', 'package_id', 'connection_type', 'pppoe_username', 'status', 'discount', 'installation_date', 'activation_date', 'expire_at', 'created_at']);

        return \Inertia\Inertia::render('Reseller/Connections', [
            'reseller' => $reseller->only(['id', 'name']),
            'connections' => $connections,
        ]);
    }

    // ── Payment: collect from my customers + history ──
    public function payments()
    {
        $reseller = Auth::guard('reseller')->user();

        return \Inertia\Inertia::render('Reseller/Payment', [
            'reseller' => $reseller->only(['id', 'name']),
            'customers' => Customer::where('reseller_id', $reseller->id)->orderBy('name')->get(['id', 'code', 'name', 'phone', 'ledger_balance']),
        ]);
    }

    public function getPayments(Request $request)
    {
        $reseller = Auth::guard('reseller')->user();
        $query = CustomerPayment::with('customer:id,code,name,phone')
            ->whereIn('customer_id', Customer::where('reseller_id', $reseller->id)->select('id'))
            ->when($request->mine, fn ($q) => $q->where('collected_by_reseller_id', $reseller->id))
            ->when($request->customerId, fn ($q, $id) => $q->where('customer_id', $id))
            ->when(sqlDate($request->dateFrom), fn ($q, $d) => $q->where('payment_date', '>=', $d))
            ->when(sqlDate($request->dateTo), fn ($q, $d) => $q->where('payment_date', '<=', $d))
            ->when($request->search, fn ($q, $term) => $q->where(fn ($w) => $w->where('receipt_no', 'like', "%{$term}%")->orWhere('transaction_id', 'like', "%{$term}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%"))));

        $totals = (clone $query)->whereNotIn('status', ['reversed', 'failed', 'pending'])
            ->selectRaw('count(*) as count, coalesce(sum(amount),0) as amount')->first();

        return response()->json([
            'page' => $query->latest('payment_date')->latest('id')->paginate(min(100, (int) ($request->per_page ?: 20)),
                ['id', 'receipt_no', 'customer_id', 'payment_date', 'amount', 'method', 'transaction_id', 'source', 'status', 'collected_by_reseller_id', 'notes']),
            'totals' => $totals,
        ]);
    }

    public function customerDues(Request $request)
    {
        $reseller = Auth::guard('reseller')->user();
        $customer = Customer::where('reseller_id', $reseller->id)->findOrFail($request->customerId);

        return response()->json([
            'balance' => Money::round((float) $customer->ledger_balance),
            'advance' => CollectionService::advanceCredit($customer->id),
            'wallet' => ResellerWalletService::summary($reseller->id)['available'],
            'invoices' => Invoice::where('customer_id', $customer->id)->whereIn('status', Invoice::OPEN_STATUSES)
                ->orderBy('due_date')->orderBy('id')
                ->get(['id', 'invoice_no', 'invoice_date', 'due_date', 'period_start', 'period_end', 'total', 'paid', 'due', 'status']),
        ]);
    }

    // The reseller takes the money (cash or into their own bKash/Nagad): it is recorded on
    // the customer's account at once and counted in the reseller's wallet as money in hand.
    public function storePayment(Request $request)
    {
        $reseller = Auth::guard('reseller')->user();
        $validator = Validator::make($request->all(), [
            'customer_id' => 'required|integer',
            'amount' => 'required|numeric|gt:0|max:9999999',
            'method' => 'required|in:cash,bkash,nagad,rocket,other,wallet',
            'transaction_id' => 'required_unless:method,cash,wallet|nullable|max:100',
            'notes' => 'nullable|max:500',
        ]);
        if ($validator->fails()) return send_error("Validation Error", $validator->errors(), 422);

        try {
            $customer = Customer::where('reseller_id', $reseller->id)->findOrFail($request->customer_id);
            if ($request->input('method') === 'wallet') {
                $payment = ResellerWalletService::payFromWallet($reseller, $customer, (float) $request->amount, $request->notes);
                return response()->json(['status' => true, 'message' => "Payment {$payment->receipt_no} paid from your wallet", 'id' => $payment->id]);
            }
            $payment = CollectionService::receive($customer, $request->only(['amount', 'method', 'transaction_id', 'notes']) + [
                'payment_date' => now()->toDateString(),
                'source' => 'reseller',
                'reference' => 'Reseller ' . $reseller->code,
                'collected_by_reseller_id' => $reseller->id,
            ]);
            return response()->json(['status' => true, 'message' => "Payment {$payment->receipt_no} received", 'id' => $payment->id]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return send_error('Customer not found', null, 404);
        } catch (\RuntimeException $e) {
            return send_error($e->getMessage(), null, 422);
        } catch (\Throwable $th) {
            return send_error('Something went wrong', $th->getMessage());
        }
    }

    // ── Wallet & withdrawal requests ──
    public function withdrawals()
    {
        $reseller = Auth::guard('reseller')->user();

        return \Inertia\Inertia::render('Reseller/Withdrawal', [
            'reseller' => $reseller->only(['id', 'name', 'phone']),
        ]);
    }

    public function getWithdrawals()
    {
        $reseller = Auth::guard('reseller')->user();

        return response()->json([
            'wallet' => ResellerWalletService::summary($reseller->id),
            'transactions' => ResellerTransaction::where('reseller_id', $reseller->id)->latest('id')->limit(200)
                ->get(['id', 'ref_no', 'type', 'amount', 'status', 'method', 'account_details', 'transaction_id', 'reseller_note', 'admin_note', 'processed_at', 'created_at']),
        ]);
    }

    public function storeWithdrawal(Request $request)
    {
        $reseller = Auth::guard('reseller')->user();
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|gt:0|max:9999999',
            'method' => 'required|in:cash,bank,bkash,nagad,rocket',
            'account_details' => 'required_unless:method,cash|nullable|max:255',
            'note' => 'nullable|max:255',
        ]);
        if ($validator->fails()) return send_error("Validation Error", $validator->errors(), 422);

        try {
            $tx = ResellerWalletService::requestWithdrawal($reseller, $request->only(['amount', 'method', 'account_details', 'note']));
            return response()->json(['status' => true, 'message' => "Withdrawal request {$tx->ref_no} sent to the company."]);
        } catch (\RuntimeException $e) {
            return send_error($e->getMessage(), null, 422);
        } catch (\Throwable $th) {
            return send_error('Something went wrong', $th->getMessage());
        }
    }

    public function cancelWithdrawal(Request $request)
    {
        $reseller = Auth::guard('reseller')->user();
        try {
            $tx = ResellerTransaction::where('reseller_id', $reseller->id)->findOrFail($request->id);
            ResellerWalletService::cancel($tx);
            return response()->json(['status' => true, 'message' => "Request {$tx->ref_no} cancelled."]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return send_error('Request not found', null, 404);
        } catch (\RuntimeException $e) {
            return send_error($e->getMessage(), null, 422);
        }
    }

    // ── Ledger (statement of the wallet) ──
    public function ledger()
    {
        return \Inertia\Inertia::render('Reseller/Ledger', [
            'reseller' => Auth::guard('reseller')->user()->only(['id', 'code', 'name', 'phone']),
        ]);
    }

    public function getLedger(Request $request)
    {
        $reseller = Auth::guard('reseller')->user();
        return response()->json(ResellerLedgerService::statement($reseller->id, sqlDate($request->dateFrom), sqlDate($request->dateTo))
            + ['wallet' => ResellerWalletService::summary($reseller->id)]);
    }

    public function logout()
    {
        try {
            Auth::guard('reseller')->logout();
            Session::forget('portal');
            if (Session::pull('reseller_impersonator_id') && Auth::guard('web')->check()) {
                Session::flash('success', 'Left the reseller portal');
                return redirect('/reseller');
            }
            Session::flash('success', 'Logout successfully');
            return redirect('/?portal=reseller');
        } catch (\Throwable $e) {
            return send_error('Something went wrong', $e->getMessage());
        }
    }
}
