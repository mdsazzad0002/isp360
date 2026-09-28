<?php

namespace App\Services\Isp;

use App\Support\Money;
use App\Models\Package;
use App\Models\PackagePriceHistory;
use App\Models\Reseller;
use Illuminate\Support\Facades\DB;
use RuntimeException;

// A reseller sells a company package under their own name and price; a sub-reseller does the
// same with its parent reseller's packages (ResellerChainService). Their edits go live at once.
// When the level above changes the base package, the copy does NOT follow on its own: it keeps
// the price the reseller accepted (base_price) and its old speed/profile/cycle until the
// reseller reviews the change and saves the package again.
class ResellerPackageService
{
    // The packages of the level above: its parent reseller's, or the company's for a top-level reseller.
    private static function aboveQuery(Reseller $reseller)
    {
        return Package::where('branch_id', $reseller->branch_id)
            ->when($reseller->parent_id, fn ($q) => $q->where('reseller_id', $reseller->parent_id), fn ($q) => $q->whereNull('reseller_id'));
    }

    // Packages a reseller may customize.
    public static function basePackages(Reseller $reseller)
    {
        return self::aboveQuery($reseller)
            ->where('is_active', true)
            ->orderBy('price')
            ->get(['id', 'name', 'code', 'download_mbps', 'upload_mbps', 'price', 'billing_cycle', 'validity_days', 'installation_fee', 'activation_fee', 'visibility', 'description']);
    }

    // What the company changed on the base package since the reseller last accepted it:
    // [field => [reseller's copy, company now]]. Empty when the copy is up to date.
    public static function baseChanges(Package $copy): array
    {
        $base = $copy->base_package_id ? $copy->basePackage : null;
        if (! $base) {
            return [];
        }
        $changes = [];
        if ($copy->base_price !== null && abs((float) $copy->base_price - (float) $base->price) > 0.001) {
            $changes['company_price'] = [(float) $copy->base_price, (float) $base->price];
        }
        foreach (Package::INHERITED_FIELDS as $field) {
            if ((string) ($copy->{$field} ?? '') !== (string) ($base->{$field} ?? '')) {
                $changes[$field] = [$copy->{$field}, $base->{$field}];
            }
        }
        return $changes;
    }

    // Create or edit. Saving also accepts the company's current version of the base package.
    public static function save(Reseller $reseller, array $data): Package
    {
        return DB::transaction(function () use ($reseller, $data) {
            $package = empty($data['id'])
                ? new Package(['branch_id' => $reseller->branch_id, 'reseller_id' => $reseller->id, 'visibility' => 'universal'])
                : Package::where('reseller_id', $reseller->id)->lockForUpdate()->findOrFail($data['id']);

            $base = self::aboveQuery($reseller)->find($data['base_package_id'] ?? null);
            $above = $reseller->parent_id ? 'parent reseller' : 'company';
            if (! $base || (! $base->is_active && (int) $base->id !== (int) $package->base_package_id)) {
                throw new RuntimeException("Select an active {$above} package to customize.");
            }
            $price = Money::round((float) $data['price']);
            if ($price < (float) $base->price) {
                throw new RuntimeException("Your price cannot be lower than the {$above} price (" . Money::format($base->price) . ').');
            }

            $old = $package->exists ? $package->only(['name', 'price', 'base_price', 'download_mbps', 'upload_mbps', 'billing_cycle']) : null;
            $oldPrice = $package->exists ? (float) $package->price : null;

            $package->fill([
                'name' => $data['name'],
                'code' => $data['code'] ?? null,
                'price' => $price,
                'description' => $data['description'] ?? null,
                'is_active' => (bool) ($data['is_active'] ?? true),
                'base_package_id' => $base->id,
                'base_price' => $base->price,
                'approval_status' => 'approved',
                'pending_changes' => null,
                'ipAddress' => request()->ip(),
            ] + $base->only(Package::INHERITED_FIELDS));
            $package->approved_at = $package->approved_at ?? now();
            $package->save();

            if ($oldPrice !== null && abs($oldPrice - $price) > 0.001) {
                PackagePriceHistory::create([
                    'package_id' => $package->id,
                    'old_price' => $oldPrice,
                    'new_price' => $price,
                    'reason' => $data['price_change_reason'] ?? null,
                    'changed_by' => null,
                    'created_at' => now(),
                ]);
            }
            AuditLogger::log($old ? 'package.updated' : 'package.created', $package, $old,
                $package->only(['name', 'price', 'base_price', 'download_mbps', 'upload_mbps', 'billing_cycle']), $data['price_change_reason'] ?? null, $reseller->branch_id);
            return $package;
        });
    }
}
