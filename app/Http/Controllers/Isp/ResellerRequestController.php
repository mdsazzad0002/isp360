<?php

namespace App\Http\Controllers\Isp;

use App\Models\Package;
use App\Models\Reseller;
use App\Models\ResellerTransaction;
use App\Services\Isp\ResellerLedgerService;
use App\Services\Isp\ResellerPackageService;
use App\Services\Isp\ResellerWalletService;
use Illuminate\Http\Request;

// Company side of the reseller portal: see reseller packages (and which still wait for the
// reseller to review a company change), pay or reject withdrawal requests, and record cash
// a reseller hands over.
class ResellerRequestController extends IspController
{
    public function create()
    {
        return $this->page('resellerRequest', 'Isp/ResellerRequest', [
            'can' => [
                'settle' => checkAccess('resellerSettlement'),
            ],
        ]);
    }

    public function packagesPage()
    {
        return $this->page('resellerRequest', 'Isp/ResellerPackage', [
            'resellers' => Reseller::where('branch_id', $this->branchId)->orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }

    public function ledgerPage(Request $request)
    {
        return $this->page('resellerRequest', 'Isp/ResellerLedger', [
            'resellers' => Reseller::where('branch_id', $this->branchId)->orderBy('name')->get(['id', 'code', 'name', 'phone']),
            'resellerId' => $request->resellerId ? (int) $request->resellerId : null,
        ]);
    }

    // Also opened from the reseller list (side panel), so 'reseller' access is enough too.
    public function ledger(Request $request)
    {
        if (! checkAccess('resellerRequest') && ! checkAccess('reseller')) {
            return send_error('You are not authorized for this action', null, 403);
        }
        $reseller = Reseller::where('branch_id', $this->branchId)->findOrFail($request->resellerId);
        return response()->json(ResellerLedgerService::statement($reseller->id, sqlDate($request->dateFrom), sqlDate($request->dateTo))
            + ['wallet' => ResellerWalletService::summary($reseller->id)]);
    }

    public function packages(Request $request)
    {
        if ($r = $this->deny('resellerRequest')) return $r;
        $packages = Package::with(['reseller', 'basePackage'])
            ->where('branch_id', $this->branchId)
            ->whereNotNull('reseller_id')
            ->when($request->resellerId, fn ($q, $id) => $q->where('reseller_id', $id))
            ->withCount(['connections as active_connections' => fn ($q) => $q->whereIn('status', ['active', 'suspended'])])
            ->latest('updated_at')
            ->get()
            ->each(fn ($p) => $p->base_changes = ResellerPackageService::baseChanges($p) ?: null);
        if ($request->status === 'waiting') {
            $packages = $packages->filter(fn ($p) => $p->base_changes)->values();
        }
        return response()->json($packages);
    }

    // Balance of every reseller in the branch.
    public function wallets()
    {
        if ($r = $this->deny('resellerRequest')) return $r;
        // the whole tree in order (a parent above its sub-resellers); only top-level resellers
        // settle with the company, the others with their parent in the reseller portal
        $rows = Reseller::where('branch_id', $this->branchId)->orderBy('path')->get(['id', 'code', 'name', 'phone', 'parent_id', 'depth', 'path'])
            ->map(fn ($r) => $r->only(['id', 'code', 'name', 'phone', 'parent_id', 'depth']) + ResellerWalletService::summary($r->id));
        return response()->json($rows);
    }

    public function transactions(Request $request)
    {
        if ($r = $this->deny('resellerRequest')) return $r;
        $query = ResellerTransaction::with(['reseller', 'parentReseller', 'bank:id,name,bank_name', 'processedBy'])
            ->where('branch_id', $this->branchId)
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->type, fn ($q, $t) => $q->where('type', $t))
            ->when($request->resellerId, fn ($q, $id) => $q->where('reseller_id', $id));
        return response()->json($query->latest('id')->paginate(min(100, (int) ($request->per_page ?: 20))));
    }

    public function pay(Request $request)
    {
        if ($r = $this->deny('resellerSettlement')) return $r;
        if ($r = $this->validateOrFail($request->all(), [
            'id' => 'required|integer',
            'method' => 'required|in:cash,bank,bkash,nagad,rocket,other',
            'bank_id' => 'required_unless:method,cash|nullable|integer|exists:banks,id',
            'transaction_id' => 'nullable|max:100',
            'note' => 'nullable|max:255',
        ])) return $r;
        try {
            $tx = ResellerTransaction::where('branch_id', $this->branchId)->findOrFail($request->id);
            ResellerWalletService::pay($tx, $request->only(['method', 'bank_id', 'transaction_id', 'note']));
            return $this->ok("Withdrawal {$tx->ref_no} marked as paid.");
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
    }

    public function reject(Request $request)
    {
        if ($r = $this->deny('resellerSettlement')) return $r;
        if ($r = $this->validateOrFail($request->all(), ['id' => 'required|integer', 'note' => 'required|min:3|max:255'])) return $r;
        try {
            $tx = ResellerTransaction::where('branch_id', $this->branchId)->findOrFail($request->id);
            ResellerWalletService::reject($tx, $request->note);
            return $this->ok("Withdrawal {$tx->ref_no} rejected.");
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
    }

    public function deposit(Request $request)
    {
        if ($r = $this->deny('resellerSettlement')) return $r;
        if ($r = $this->validateOrFail($request->all(), [
            'reseller_id' => 'required|integer',
            'amount' => 'required|numeric|gt:0|max:9999999',
            'method' => 'required|in:cash,bank,bkash,nagad,rocket,other',
            'bank_id' => 'required_unless:method,cash|nullable|integer|exists:banks,id',
            'transaction_id' => 'nullable|max:100',
            'note' => 'nullable|max:255',
        ])) return $r;
        try {
            $reseller = Reseller::where('branch_id', $this->branchId)->findOrFail($request->reseller_id);
            $tx = ResellerWalletService::deposit($reseller, $request->only(['amount', 'method', 'bank_id', 'transaction_id', 'note']));
            return $this->ok("Deposit {$tx->ref_no} recorded.");
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
    }
}
