<?php

namespace App\Services\Isp;

use App\Models\Package;
use App\Models\TaxRate;
use App\Support\Money;
use Illuminate\Support\Collection;

// Sales tax (VAT / GST) math. Company-wide settings (company_profiles): tax_label, tax_number and
// prices_include_tax. Amounts come in three terms:
//   price terms: what packages and invoice lines are priced in (tax included when prices_include_tax)
//   net:         without tax — reseller margins, company shares and revenue are always net
//   gross:       what the customer pays, tax included
// A rate list is [['id' => 1, 'name' => 'VAT', 'rate' => 15.0], ...]. Tax is rounded per line
// and per rate to the currency (Money::round).
class TaxService
{
    private static ?Collection $rates = null;

    public static function flush(): void
    {
        self::$rates = null;
    }

    public static function all(): Collection
    {
        return self::$rates ??= TaxRate::orderBy('sort')->orderBy('id')->get();
    }

    public static function inclusive(): bool
    {
        return (bool) (company()?->prices_include_tax ?? false);
    }

    public static function label(): string
    {
        return (string) (company()?->tax_label ?: 'Tax');
    }

    // The active default rates: packages set to "default" and manual invoice lines use them.
    public static function defaults(): array
    {
        return self::snapshot(self::all()->where('is_active', true)->where('is_default', true));
    }

    // A package's rates. A reseller's copy is taxed like its company base package.
    // tax_rate_ids: null = the default rates, [] = exempt, [ids] = those rates.
    public static function forPackage(?Package $package): array
    {
        if (! $package) {
            return self::defaults();
        }
        $source = $package->reseller_id && $package->base_package_id ? ($package->basePackage ?? $package) : $package;
        $ids = $source->tax_rate_ids;
        if ($ids === null) {
            return self::defaults();
        }
        return self::snapshot(self::all()->where('is_active', true)->whereIn('id', array_map('intval', $ids)));
    }

    public static function totalRate(array $rates): float
    {
        return (float) array_sum(array_column($rates, 'rate'));
    }

    /**
     * The tax in one line amount (after discounts, in price terms).
     * @return array{amount: float, net: float, taxes: array}
     */
    public static function lineTax(float $amount, array $rates, bool $inclusive): array
    {
        $amount = Money::round($amount);
        if (! $rates || $amount <= 0) {
            return ['amount' => 0.0, 'net' => $amount, 'taxes' => []];
        }
        $net = self::net($amount, $rates, $inclusive);
        $taxes = [];
        foreach ($rates as $r) {
            $taxes[] = ['id' => $r['id'], 'name' => $r['name'], 'rate' => $r['rate'], 'taxable' => $net, 'amount' => Money::round($net * $r['rate'] / 100)];
        }
        if ($inclusive) {
            // the tax is what the price holds beyond the net; rounding differences go to the last rate
            $total = Money::round($amount - $net);
            $taxes[count($taxes) - 1]['amount'] = Money::round($total - array_sum(array_column(array_slice($taxes, 0, -1), 'amount')));
        }
        return ['amount' => Money::round(array_sum(array_column($taxes, 'amount'))), 'net' => $net, 'taxes' => $taxes];
    }

    // Price terms -> net.
    public static function net(float $price, array $rates, ?bool $inclusive = null): float
    {
        $inclusive ??= self::inclusive();
        return $inclusive && $rates ? Money::round($price / (1 + self::totalRate($rates) / 100)) : Money::round($price);
    }

    // Price terms -> gross (what the customer pays).
    public static function gross(float $price, array $rates, ?bool $inclusive = null): float
    {
        $inclusive ??= self::inclusive();
        return $inclusive ? Money::round($price) : Money::round($price + self::lineTax($price, $rates, false)['amount']);
    }

    // Net -> price terms, to put a net amount on an invoice line.
    public static function priceFromNet(float $net, array $rates, ?bool $inclusive = null): float
    {
        $inclusive ??= self::inclusive();
        return $inclusive ? Money::round($net + self::lineTax($net, $rates, false)['amount']) : Money::round($net);
    }

    private static function snapshot(Collection $rates): array
    {
        return $rates->map(fn (TaxRate $r) => ['id' => $r->id, 'name' => $r->name, 'rate' => (float) $r->rate])->values()->all();
    }
}
