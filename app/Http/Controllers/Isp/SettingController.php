<?php

namespace App\Http\Controllers\Isp;

use App\Models\CompanyProfile;
use App\Services\Isp\AuditLogger;
use App\Services\Isp\IspSettings;
use App\Services\Isp\TaxService;
use App\Support\CountryPack;
use App\Support\Money;
use App\Support\Region;
use App\Support\TwoFactorPolicy;
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
            // who must use two-factor login, company-wide
            'two_factor_policy' => TwoFactorPolicy::current(),
            'log_retention_days' => (int) (company()?->log_retention_days ?? 365),
            'log_retention_minimum' => \App\Support\CountryPack::current()['log_retention_days'],
            'two_factor_policies' => collect(TwoFactorPolicy::POLICIES)->map(fn ($label, $value) => compact('value', 'label'))->values(),
            'my_two_factor' => (bool) auth()->user()?->hasTwoFactor(),
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
            'two_factor_policy' => ['nullable', Rule::in(array_keys(TwoFactorPolicy::POLICIES))],
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
            'sms_tpl_notice' => 'nullable|max:320',
            'sms_tpl_reminder' => 'nullable|max:320',
            'reminder_days' => 'nullable|integer|min:0|max:30',
            'sms_tpl_translations' => 'nullable|json|max:20000',
            'grace_days' => 'nullable|integer|min:0|max:60',
            'postpaid_due_days' => 'nullable|integer|min:0|max:60',
            'bill_day' => 'nullable|integer|min:0|max:28',
            'session_log_mikrotik' => 'boolean',
            'kyc_required' => 'boolean',
            'log_retention_days' => 'nullable|integer|min:0|max:3650',
            'terminate_credit_unused' => 'boolean',
            'notice_days' => 'nullable|integer|min:0|max:30',
            'notice_required' => 'boolean',
            'late_fee_type' => 'nullable|in:none,fixed,percent',
            'late_fee_amount' => 'nullable|numeric|min:0|max:1000000' . ($request->late_fee_type === 'percent' ? '|max:100' : ''),
            'late_fee_after_days' => 'nullable|integer|min:0|max:365',
            'late_fee_repeat' => 'nullable|in:once,monthly',
            'late_fee_max' => 'nullable|integer|min:1|max:24',
        ])) return $r;
        if ($request->boolean('notice_required') && (int) $request->notice_days < 1) {
            return send_error('Validation Error', ['notice_days' => 'Set how many days before suspension the notice goes out.'], 422);
        }

        $company = CompanyProfile::firstOrFail();
        $region = ['country_code' => Region::countryCode(), 'currency_code' => Money::code(), 'timezone' => Region::timezone()];
        $new = $request->only(array_keys($region));
        // every stored amount is in the currency and every stored time in the timezone, so they can't change once money is recorded
        if (($new['currency_code'] !== $region['currency_code'] || $new['timezone'] !== $region['timezone']) && Money::locked()) {
            return send_error("The currency and timezone can't change: invoices or payments already exist in {$region['currency_code']}, {$region['timezone']}.", null, 422);
        }
        $policy = $request->two_factor_policy ?? TwoFactorPolicy::current();
        // whoever requires 2FA must already use it, or they'd lock themselves out of the next page
        if ($policy !== TwoFactorPolicy::current() && ! auth()->user()->hasTwoFactor() && TwoFactorPolicy::requiredFor(auth()->user(), 'web', $policy)) {
            return send_error('Turn on two-factor login for your own account first (My profile), then require it for others.', null, 422);
        }
        // retention can't go under the country's legal minimum (0 = keep forever)
        $retention = $request->filled('log_retention_days') ? (int) $request->log_retention_days : (int) $company->log_retention_days;
        $minimum = (int) (\App\Support\CountryPack::current()['log_retention_days'] ?? 0);
        if ($retention !== (int) $company->log_retention_days && $retention !== 0 && $retention < $minimum) {
            return send_error("Session logs must be kept at least {$minimum} days in this country (0 = forever).", null, 422);
        }
        $tax = ['tax_label' => $company->tax_label, 'tax_number' => $company->tax_number, 'prices_include_tax' => (bool) $company->prices_include_tax];
        $newTax = ['tax_label' => $request->tax_label, 'tax_number' => $request->tax_number ?: null, 'prices_include_tax' => $request->boolean('prices_include_tax')];
        if ($newTax != $tax) {
            // only new invoices follow: each invoice keeps whether its prices included the tax
            $company->update($newTax);
            clearCompanyCache();
            AuditLogger::log('company.tax_updated', null, $tax, $newTax, null, $this->branchId);
        }
        if ($retention !== (int) $company->log_retention_days) {
            AuditLogger::log('company.log_retention_updated', null, ['log_retention_days' => $company->log_retention_days], ['log_retention_days' => $retention], null, $this->branchId);
            $company->update(['log_retention_days' => $retention]);
            clearCompanyCache();
        }
        if ($policy !== TwoFactorPolicy::current()) {
            $oldPolicy = TwoFactorPolicy::current();
            $company->update(['two_factor_policy' => $policy]);
            clearCompanyCache();
            AuditLogger::log('company.two_factor_policy_updated', null, ['two_factor_policy' => $oldPolicy], ['two_factor_policy' => $policy], null, $this->branchId);
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
