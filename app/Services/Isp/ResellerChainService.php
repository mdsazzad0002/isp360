<?php

namespace App\Services\Isp;

use App\Models\Invoice;
use App\Models\Package;
use App\Models\Reseller;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

// The reseller tree (roadmap 3.3). A sub-reseller customizes its parent's package, the parent
// customized its own parent's, and the top reseller a company package. Each copy keeps the price
// it accepted from the level above (packages.base_price), so a sale at level 0 costs:
//
//   level 0 (seller)    owes its parent  net(base_price of the seller's package)
//   level 1 (parent)    owes its parent  net(base_price of the parent's package)
//   ...
//   top level           owes the company net(base_price of its package) = invoices.reseller_cost
//
// Each level earns (what the level below owes it) - (what it owes above); the seller earns the
// bill's net total - its cost. The costs are snapshotted per bill in invoice_reseller_shares.
class ResellerChainService
{
    // [[reseller_id, unit cost net of tax or null], ...] from the selling reseller up to the top.
    // Empty for a company package.
    public static function chain(Package $package): array
    {
        $chain = [];
        $seen = [];
        $taxes = TaxService::forPackage($package);
        while ($package && $package->reseller_id && ! isset($seen[$package->id])) {
            $seen[$package->id] = true;
            $base = $package->base_package_id ? Package::withTrashed()->find($package->base_package_id) : null;
            $price = $package->base_price ?? $base?->price;
            $chain[] = [(int) $package->reseller_id, $price === null ? null : TaxService::net((float) $price, $taxes)];
            $package = $base;
        }
        return $chain;
    }

    // The company's share of one cycle of the package, net of tax (null: unknown, all the company's).
    public static function companyUnitCost(Package $package): ?float
    {
        $chain = self::chain($package);
        return $chain ? end($chain)[1] : null;
    }

    // Records who shares the bill: invoices.reseller_id (seller) / reseller_cost (company's share,
    // given or one cycle scaled by $factor) and one invoice_reseller_shares row per level. Lower
    // levels' costs scale with the company's share, so a pro-rata or package-change bill splits
    // the same way as a full cycle.
    public static function record(Invoice $invoice, Package $package, ?float $companyShare = null, float $factor = 1.0): void
    {
        $chain = self::chain($package);
        DB::table('invoice_reseller_shares')->where('invoice_id', $invoice->id)->delete();
        if (! $chain) {
            return;
        }
        $top = end($chain)[1];
        if ($companyShare === null && $top !== null) {
            $companyShare = Money::round($top * $factor);
        }
        $scale = ($top !== null && $top > 0 && $companyShare !== null) ? $companyShare / $top : null;

        $rows = [];
        foreach ($chain as $level => [$resellerId, $unit]) {
            $cost = $level === count($chain) - 1
                ? $companyShare
                : ($unit === null || $scale === null ? null : Money::round($unit * $scale));
            $rows[] = ['invoice_id' => $invoice->id, 'reseller_id' => $resellerId, 'level' => $level, 'cost' => $cost];
        }
        DB::table('invoice_reseller_shares')->insert($rows);
        Invoice::whereKey($invoice->id)->update(['reseller_id' => $chain[0][0], 'reseller_cost' => $companyShare]);
    }

    // The reseller and everyone below it.
    public static function subtreeIds(Reseller|int $reseller): array
    {
        $reseller = $reseller instanceof Reseller ? $reseller : Reseller::withTrashed()->find($reseller);
        if (! $reseller) {
            return [];
        }
        if (! $reseller->path) {
            return [(int) $reseller->id];
        }
        return Reseller::withTrashed()->where('path', 'like', $reseller->path . '%')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public static function maxDepth(int $branchId): int
    {
        return max(1, (int) IspSettings::get($branchId, 'reseller_max_depth'));
    }

    // Sets (or changes) a reseller's parent. Only while the reseller has no money history, no
    // packages and no sub-resellers: its bills and settlements belong to the old position.
    public static function setParent(Reseller $reseller, ?int $parentId): void
    {
        $parentId = $parentId ?: null;
        if ((int) $reseller->parent_id === (int) $parentId && $reseller->path) {
            return;
        }
        $parent = $parentId ? Reseller::where('branch_id', $reseller->branch_id)->find($parentId) : null;
        if ($parentId && ! $parent) {
            throw new \RuntimeException('Select a reseller of this branch as the parent.');
        }
        if ($parent && $reseller->exists && str_contains((string) $parent->path, '/' . $reseller->id . '/')) {
            throw new \RuntimeException('A reseller cannot sit under itself or its own sub-reseller.');
        }
        $depth = $parent ? $parent->depth + 1 : 1;
        if ($depth > self::maxDepth($reseller->branch_id)) {
            throw new \RuntimeException('The reseller network allows ' . self::maxDepth($reseller->branch_id) . ' level(s) (ISP Settings → Reseller levels).');
        }
        if ($reseller->exists && $reseller->path && (
            Reseller::where('parent_id', $reseller->id)->exists()
            || Package::withTrashed()->where('reseller_id', $reseller->id)->exists()
            || DB::table('invoice_reseller_shares')->where('reseller_id', $reseller->id)->exists()
            || DB::table('reseller_transactions')->where('reseller_id', $reseller->id)->exists()
            || DB::table('customer_payments')->where('collected_by_reseller_id', $reseller->id)->exists()
        )) {
            throw new \RuntimeException('The parent can only change while the reseller has no packages, bills, settlements or sub-resellers.');
        }
        $old = $reseller->exists ? $reseller->only(['parent_id', 'depth']) : null;
        $reseller->forceFill(['parent_id' => $parent?->id, 'depth' => $depth]);
        $reseller->save();
        $reseller->forceFill(['path' => ($parent ? $parent->path : '/') . $reseller->id . '/'])->save();
        if ($old && $old['parent_id'] != $reseller->parent_id) {
            AuditLogger::log('reseller.parent_changed', $reseller, $old, $reseller->only(['parent_id', 'depth']), null, $reseller->branch_id);
        }
    }
}
