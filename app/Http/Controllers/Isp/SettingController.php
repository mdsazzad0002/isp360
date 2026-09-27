<?php

namespace App\Http\Controllers\Isp;

use App\Models\CompanyProfile;
use App\Services\Isp\AuditLogger;
use App\Services\Isp\IspSettings;
use App\Services\Isp\TaxService;
use App\Support\CountryPack;
use App\Support\Money;
use App\Support\Region;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SettingController extends IspController
{
    public function create()
    {
        return $this->page('ispSettings', 'Isp/Settings');
    }

    public function show()
    {
        return response()->json(IspSettings::all($this->branchId) + [
            // company-wide: one installation runs one company in one country, for every branch
            'country_code' => Region::countryCode(),
            'currency_code' => Money::code(),
            'timezone' => Region::timezone(),
            // stored amounts are in the currency and stored times in the timezone, so both are fixed once money is recorded
            'currency_locked' => Money::locked(),
            'language' => company()?->language ?? 'en',
            // every country with its resolved pack (config/country_packs), shown before it is applied
            'countries' => collect(config('countries'))->map(fn ($c, $code) => ['code' => $code, 'timezones' => Region::timezonesFor($code), 'pack' => CountryPack::get($code)] + $c)->values(),
            'currencies' => collect(config('currencies'))->map(fn ($c, $code) => ['code' => $code] + $c)->values(),
            // sales tax, company-wide
            'tax_label' => company()?->tax_label ?? 'VAT',
            'tax_number' => company()?->tax_number ?? '',
            'prices_include_tax' => (bool) (company()?->prices_include_tax ?? false),
            'tax_rates' => TaxService::all()->values(),
        ]);
    }

    public function update(Request $request)
    {
        if ($r = $this->deny('ispSettings')) return $r;
        if ($r = $this->validateOrFail($request->all(), [
            'country_code' => ['required', Rule::in(array_keys(config('countries')))],
            'currency_code' => ['required', Rule::in(array_keys(config('currencies')))],
            // a zone of the chosen country, or the current one kept as it is
            'timezone' => ['required', Rule::in([...Region::timezonesFor((string) $request->country_code), Region::timezone()])],
            'tax_label' => 'required|string|max:20',
            'tax_number' => 'nullable|string|max:60',
            'prices_include_tax' => 'boolean',
            'due_days' => 'required|integer|min:0|max:90',
            'renewal_invoice_days' => 'required|integer|min:0|max:30',
            'init_bonus_days' => 'required|integer|min:0|max:365',
            'referral_commission_type' => 'required|in:fixed,percent',
            'referral_commission' => 'required|numeric|min:0|max:100000' . ($request->referral_commission_type === 'percent' ? '|max:100' : ''),
            'invoice_prefix' => 'required|alpha_num|max:8',
            'receipt_prefix' => 'required|alpha_num|max:8',
            'credit_note_prefix' => 'required|alpha_num|max:8',
            'debit_note_prefix' => 'required|alpha_num|max:8',
            'refund_prefix' => 'required|alpha_num|max:8',
            'connection_prefix' => 'required|alpha_num|max:8',
            'sms_tpl_invoice' => 'nullable|max:320',
            'sms_tpl_payment' => 'nullable|max:320',
            'sms_tpl_suspend' => 'nullable|max:320',
            'sms_tpl_reactivate' => 'nullable|max:320',
        ])) return $r;

        $company = CompanyProfile::firstOrFail();
        $region = ['country_code' => Region::countryCode(), 'currency_code' => Money::code(), 'timezone' => Region::timezone()];
        $new = $request->only(array_keys($region));
        // every stored amount is in the currency and every stored time in the timezone, so they can't change once money is recorded
        if (($new['currency_code'] !== $region['currency_code'] || $new['timezone'] !== $region['timezone']) && Money::locked()) {
            return send_error("The currency and timezone can't change: invoices or payments already exist in {$region['currency_code']}, {$region['timezone']}.", null, 422);
        }
        $tax = ['tax_label' => $company->tax_label, 'tax_number' => $company->tax_number, 'prices_include_tax' => (bool) $company->prices_include_tax];
        $newTax = ['tax_label' => $request->tax_label, 'tax_number' => $request->tax_number ?: null, 'prices_include_tax' => $request->boolean('prices_include_tax')];
        if ($newTax != $tax) {
            // only new invoices follow: each invoice keeps whether its prices included the tax
            $company->update($newTax);
            clearCompanyCache();
            AuditLogger::log('company.tax_updated', null, $tax, $newTax, null, $this->branchId);
        }
        if ($new != $region) {
            $company->update($new);
            clearCompanyCache();
            Region::apply($new['timezone']);
            AuditLogger::log('company.region_updated', null, $region, $new, null, $this->branchId);
        }

        $old = IspSettings::all($this->branchId);
        IspSettings::save($this->branchId, $request->only(array_keys(IspSettings::DEFAULTS)));
        $new = IspSettings::all($this->branchId);
        $changed = array_keys(array_diff_assoc(array_map('strval', $new), array_map('strval', $old)));
        if ($changed) {
            AuditLogger::log('settings.updated', null, array_intersect_key($old, array_flip($changed)), array_intersect_key($new, array_flip($changed)), null, $this->branchId);
        }
        return $this->ok('Settings saved successfully');
    }

    // Applies a country's pack to the company (and, when asked, its tax rates and every branch's
    // billing defaults). The currency and timezone stay once money is recorded.
    public function applyCountryPack(Request $request)
    {
        if ($r = $this->deny('ispSettings')) return $r;
        if ($r = $this->validateOrFail($request->all(), [
            'country_code' => ['required', Rule::in(array_keys(config('countries')))],
            'tax' => 'boolean',
            'billing' => 'boolean',
        ])) return $r;

        try {
            $done = CountryPack::apply($request->country_code, $request->boolean('tax'), $request->boolean('billing'), $this->branchId);
            return $this->ok(implode(' ', $done), ['done' => $done]);
        } catch (\Throwable $th) {
            return $this->fail($th);
        }
    }
}
