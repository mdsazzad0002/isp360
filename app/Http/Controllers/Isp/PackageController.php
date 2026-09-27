<?php

namespace App\Http\Controllers\Isp;

use App\Support\Money;
use App\Models\Connection;
use App\Models\Package;
use App\Models\PackagePriceHistory;
use App\Services\Isp\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PackageController extends IspController
{
    public function create()
    {
        return $this->page('package', 'Isp/Package');
    }

    public function index(Request $request)
    {
        $packages = Package::where('branch_id', $this->branchId)
            ->with('reseller')
            ->when($request->activeOnly, fn ($q) => $q->where('is_active', true))
            ->when($request->owner === 'company', fn ($q) => $q->whereNull('reseller_id')->withCount('resellerCopies'))
            ->withCount(['connections as active_connections' => fn ($q) => $q->whereIn('status', ['active', 'suspended'])])
            ->orderBy('price')
            ->get()
            ->map(function ($p) {
                $tag = $p->reseller ? "[{$p->reseller->name}] " : ($p->visibility === 'hidden' ? '[Hidden] ' : '');
                $p->display_name = $tag . "{$p->name} — " . Money::format($p->price) . ' / ' . str_replace('_', '-', $p->billing_cycle);
                return $p;
            });
        return response()->json($packages);
    }

    public function history(Request $request)
    {
        $package = Package::where('branch_id', $this->branchId)->findOrFail($request->id);
        return response()->json($package->priceHistories()->with('changedBy')->get());
    }

    // Price edits only affect future invoices: existing invoices hold their own snapshot.
    public function store(Request $request)
    {
        if ($r = $this->deny('package')) return $r;
        $branchId = $this->branchId;
        // Names are unique per owner: the company's packages, or one reseller's.
        $resellerId = $request->id ? Package::where('branch_id', $branchId)->whereKey($request->id)->value('reseller_id') : null;
        if ($r = $this->validateOrFail($request->all(), [
            'id' => 'nullable|integer',
            'name' => ['required', 'max:100', Rule::unique('packages')->ignore($request->id)->where('branch_id', $branchId)->where('reseller_id', $resellerId)->whereNull('deleted_at')],
            'code' => 'nullable|max:50',
            'download_mbps' => 'required|integer|min:0',
            'upload_mbps' => 'required|integer|min:0',
            'price' => 'required|numeric|min:0|max:9999999',
            'billing_cycle' => 'required|in:monthly,quarterly,half_yearly,yearly',
            'validity_days' => 'nullable|integer|min:1|max:400',
            'installation_fee' => 'nullable|numeric|min:0',
            'activation_fee' => 'nullable|numeric|min:0',
            'network_profile' => 'nullable|max:100',
            'visibility' => 'nullable|in:universal,hidden',
            'price_change_reason' => 'nullable|max:255',
            'tax_mode' => 'nullable|in:default,custom,exempt',
            'tax_rate_ids' => 'nullable|array',
            'tax_rate_ids.*' => 'integer|exists:tax_rates,id',
        ])) return $r;

        try {
            return DB::transaction(function () use ($request, $branchId) {
                $package = $request->id ? Package::where('branch_id', $branchId)->lockForUpdate()->findOrFail($request->id) : new Package(['branch_id' => $branchId, 'created_by' => $this->userId]);
                $old = $package->exists ? $package->only(['name', 'price', 'download_mbps', 'upload_mbps', 'billing_cycle', 'is_active']) : null;
                $oldPrice = $package->exists ? (float) $package->price : null;

                $package->fill($request->only(['name', 'code', 'download_mbps', 'upload_mbps', 'price', 'billing_cycle', 'description', 'network_profile']) + [
                    'validity_days' => (int) ($request->validity_days ?: 30),
                    'installation_fee' => (float) $request->installation_fee,
                    'activation_fee' => (float) $request->activation_fee,
                    'is_active' => $request->boolean('is_active', true),
                ]);
                if ($package->reseller_id === null) {
                    $package->visibility = $request->visibility ?: 'universal';
                    // null = the default rates, [] = exempt, [ids] = these rates (a reseller copy follows its base)
                    $package->tax_rate_ids = match ($request->tax_mode ?? 'default') {
                        'exempt' => [],
                        'custom' => array_values(array_unique(array_map('intval', $request->tax_rate_ids ?? []))),
                        default => null,
                    };
                }
                $package->updated_by = $this->userId;
                $package->ipAddress = $request->ip();
                $package->save();

                if ($oldPrice !== null && abs($oldPrice - (float) $package->price) > 0.001) {
                    PackagePriceHistory::create([
                        'package_id' => $package->id,
                        'old_price' => $oldPrice,
                        'new_price' => $package->price,
                        'reason' => $request->price_change_reason,
                        'changed_by' => $this->userId,
                        'created_at' => now(),
                    ]);
                }
                AuditLogger::log($old ? 'package.updated' : 'package.created', $package, $old,
                    $package->only(['name', 'price', 'download_mbps', 'upload_mbps', 'billing_cycle', 'is_active']), $request->price_change_reason);

                $note = $oldPrice !== null && abs($oldPrice - (float) $package->price) > 0.001 ? ' New price applies from the next invoice; existing invoices are unchanged.' : '';
                if ($old && ($copies = $package->resellerCopies()->count())) {
                    $note .= " {$copies} reseller package(s) keep their current terms until each reseller reviews this change.";
                }
                return $this->ok('Package saved successfully.' . $note);
            });
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
    }

    public function destroy(Request $request)
    {
        if ($r = $this->deny('package')) return $r;
        $package = Package::where('branch_id', $this->branchId)->findOrFail($request->id);
        if (Connection::where('package_id', $package->id)->where('status', '!=', 'terminated')->exists()) {
            return send_error('Connections still use this package. Deactivate it instead, or move them to another package.', null, 422);
        }
        if ($package->resellerCopies()->exists()) {
            return send_error('Resellers sell customized copies of this package. Deactivate it instead.', null, 422);
        }
        $package->update(['deleted_by' => $this->userId, 'status' => 'd']);
        $package->delete();
        AuditLogger::log('package.deleted', $package, $package->only(['name', 'price']));
        return $this->ok('Package deleted successfully');
    }
}
