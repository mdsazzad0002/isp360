<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\CompanyProfile;
use App\Models\PaymentGateway;
use App\Models\TaxRate;
use App\Services\Isp\AuditLogger;
use App\Services\Isp\IspSettings;
use App\Services\Isp\TaxService;
use Illuminate\Support\Facades\DB;

// A country's defaults in one place, so onboarding a new ISP is picking a country, not editing code.
//
// config/countries.php lists every country the company can pick (name, currency, main timezone).
// config/country_packs/{CODE}.php adds the rest for the countries that have a full pack; any other
// country gets the generic pack below. Keys of a pack:
//   name, currency, timezone      from config/countries.php (a pack file may override them)
//   language                      default UI language for users who haven't picked one (resources/js/lang)
//   date_format                   PHP date format for printed dates, e.g. d/m/Y
//   number_locale                 BCP 47 locale for numbers in the UI (grouping, decimal mark)
//   phone                         calling_code, national_prefix (trunk "0"), lengths (national digits), example
//   address                       state and postcode labels, postcode_required
//   id_types                      code => label, for customer KYC
//   tax                           label (VAT/GST), prices_include_tax, rates [[name, rate, default]] (suggested)
//   payment_gateways              gateway codes for this market; only the built ones are offered (PaymentGateway::GATEWAYS)
//   sms_providers                 SMS providers used in this market (informational until the drivers exist)
//   billing                       branch billing defaults, IspSettings keys only
//   log_retention_days            how long session/NAT logs must be kept (legal minimum), null = no rule
//   regulatory_reports            reports the regulator asks for
//
// Every country difference is data here, never an if ($country === 'BD') in code. The BD pack
// reproduces today's behaviour exactly.
class CountryPack
{
    public const GENERIC = [
        'language' => 'en',
        'date_format' => 'd/m/Y',
        // BCP 47 locale for number grouping in the UI (Intl.NumberFormat): "1,00,000" in en-IN, "1.000,00" in pt-BR
        'number_locale' => 'en-US',
        'phone' => ['calling_code' => null, 'national_prefix' => '', 'lengths' => [], 'example' => ''],
        'address' => ['state_label' => 'State / province', 'postcode_label' => 'Postcode', 'postcode_required' => false],
        'id_types' => ['national_id' => 'National ID', 'passport' => 'Passport'],
        'tax' => ['label' => 'VAT', 'prices_include_tax' => false, 'rates' => []],
        'payment_gateways' => [],
        'sms_providers' => [],
        'billing' => [],
        'log_retention_days' => null,
        'regulatory_reports' => [],
    ];

    // Countries with a full pack file.
    public static function codes(): array
    {
        return array_values(array_filter(array_keys(config('countries')), fn ($code) => is_array(config("country_packs.$code"))));
    }

    public static function exists(string $code): bool
    {
        return (bool) config("countries.$code");
    }

    public static function hasFullPack(string $code): bool
    {
        return is_array(config("country_packs.$code"));
    }

    // The resolved pack of a country: generic defaults < config/countries.php < its pack file.
    public static function get(string $code): array
    {
        $country = config("countries.$code");
        if (! $country) {
            throw new \InvalidArgumentException("Unknown country: $code");
        }
        $pack = array_replace_recursive(self::GENERIC, $country, config("country_packs.$code") ?? []);
        // lists are replaced whole, not merged index by index
        foreach (['id_types', 'payment_gateways', 'sms_providers', 'regulatory_reports'] as $key) {
            $pack[$key] = config("country_packs.$code.$key") ?? self::GENERIC[$key];
        }
        $pack['tax']['rates'] = config("country_packs.$code.tax.rates") ?? [];
        $pack['phone']['lengths'] = config("country_packs.$code.phone.lengths") ?? [];
        $pack['billing'] = array_intersect_key($pack['billing'], IspSettings::DEFAULTS);
        // gateways of this market that are built and can take its currency
        $pack['available_gateways'] = array_values(array_filter($pack['payment_gateways'],
            fn ($g) => isset(PaymentGateway::GATEWAYS[$g]) && PaymentGateway::supportsCurrency($g, $pack['currency'])));

        return ['code' => $code, 'full' => self::hasFullPack($code)] + $pack;
    }

    public static function current(): array
    {
        return self::get(Region::countryCode());
    }

    // Applies a country's pack to the company: country, currency, timezone, language, tax name and
    // pricing, and optionally its suggested tax rates and every branch's billing defaults.
    // Nothing already recorded changes: once money exists the currency and timezone stay, and tax
    // rates are only added when the company has none. Returns what was done, line by line.
    public static function apply(string $code, bool $withTax = false, bool $withBilling = false, ?int $branchId = null): array
    {
        $done = DB::transaction(fn () => self::applyPack($code, $withTax, $withBilling, $branchId));
        // the transaction may have rolled back a cached company or tax rate list
        clearCompanyCache();
        TaxService::flush();
        IspSettings::flush();
        Region::apply();
        return $done;
    }

    private static function applyPack(string $code, bool $withTax, bool $withBilling, ?int $branchId): array
    {
        $pack = self::get($code);
        $company = CompanyProfile::firstOrFail();
        $before = $company->only(['country_code', 'currency_code', 'timezone', 'language', 'tax_label', 'prices_include_tax']);
        $done = [];

        // session logs are kept at least as long as the country requires (never shorter than a year here)
        $new = ['country_code' => $code, 'language' => $pack['language'], 'log_retention_days' => max(365, (int) ($pack['log_retention_days'] ?? 0))];
        if ($withTax) {
            $new += ['tax_label' => $pack['tax']['label'], 'prices_include_tax' => (bool) $pack['tax']['prices_include_tax']];
        }
        // a multi-zone country keeps the zone already picked when it belongs to that country
        $timezone = in_array(Region::timezone(), Region::timezonesFor($code), true) ? Region::timezone() : $pack['timezone'];
        if (Money::locked()) {
            if ($pack['currency'] !== Money::code() || $timezone !== Region::timezone()) {
                $done[] = 'Currency and timezone kept (' . Money::code() . ', ' . Region::timezone() . '): invoices or payments already exist.';
            }
        } else {
            $new += ['currency_code' => config("currencies.{$pack['currency']}") ? $pack['currency'] : Money::code(), 'timezone' => $timezone];
        }
        $company->update($new);
        clearCompanyCache();
        Region::apply();
        $done[] = "Company set to {$pack['name']}: " . Money::code() . ', ' . Region::timezone() . ", language {$pack['language']}"
            . ($withTax ? ", {$pack['tax']['label']} " . ($pack['tax']['prices_include_tax'] ? 'included in prices' : 'added to prices') : '') . '.';

        if ($withTax) {
            if (! $pack['tax']['rates']) {
                $done[] = 'No suggested tax rates for this country: add them under Sales tax.';
            } elseif (TaxRate::exists()) {
                $done[] = 'Tax rates kept: the company already has tax rates.';
            } else {
                foreach ($pack['tax']['rates'] as $i => $rate) {
                    TaxRate::create(['name' => $rate['name'], 'rate' => $rate['rate'], 'is_default' => $rate['default'] ?? true, 'is_active' => true, 'sort' => $i]);
                }
                TaxService::flush();
                $done[] = 'Tax rates added: ' . implode(', ', array_column($pack['tax']['rates'], 'name')) . '. Check them with your accountant.';
            }
        }

        if ($withBilling && $pack['billing']) {
            foreach (Branch::pluck('id') as $id) {
                IspSettings::save($id, $pack['billing']);
            }
            $done[] = 'Billing defaults set on every branch.';
        }

        AuditLogger::log('company.country_pack_applied', null, $before,
            $company->fresh()->only(array_keys($before)) + ['pack' => $code, 'tax' => $withTax, 'billing' => $withBilling], null, $branchId);

        return $done;
    }
}
