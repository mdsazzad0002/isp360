<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CompanyProfile;
use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Isp\IspSettings;
use App\Services\Isp\TaxService;
use App\Support\CountryPack;
use App\Support\Money;
use App\Support\Region;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

// Country packs: a country's defaults (config/country_packs) resolved over the generic pack and
// applied to the company without touching anything already recorded.
class CountryPackTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private Branch $branch;
    private string $originalTz;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::where('role', 'Superadmin')->firstOrFail();
        $this->branch = Branch::firstOrFail();
        $this->originalTz = date_default_timezone_get();
        CompanyProfile::query()->update(['country_code' => 'BD', 'currency_code' => 'BDT', 'timezone' => 'Asia/Dhaka', 'language' => 'en', 'tax_label' => 'VAT', 'prices_include_tax' => false]);
        clearCompanyCache();
        TaxRate::query()->delete();
        TaxService::flush();
    }

    protected function tearDown(): void
    {
        Region::apply($this->originalTz);
        clearCompanyCache();
        TaxService::flush();
        IspSettings::flush();
        parent::tearDown();
    }

    private function api(string $uri, array $data = [])
    {
        return $this->actingAs($this->admin)->withSession(['branch' => $this->branch])->postJson($uri, $data);
    }

    private function unlockMoney(): void
    {
        LedgerEntry::query()->delete();
        CustomerPayment::query()->delete();
        Invoice::query()->delete();
    }

    public function test_every_pack_file_is_valid(): void
    {
        $this->assertContains('BD', CountryPack::codes());
        foreach (array_keys(config('countries')) as $code) {
            $pack = CountryPack::get($code);
            $this->assertArrayHasKey($pack['currency'], config('currencies'), "$code currency");
            $this->assertContains($pack['timezone'], Region::timezonesFor($code), "$code timezone");
            $this->assertFileExists(resource_path("js/lang/{$pack['language']}.json"), "$code language");
            $this->assertSame([], array_diff_key($pack['billing'], IspSettings::DEFAULTS), "$code billing keys");
            foreach ($pack['tax']['rates'] as $rate) {
                $this->assertTrue($rate['rate'] >= 0 && $rate['rate'] <= 100, "$code tax rate");
            }
            // only gateways that exist and can take the currency are offered
            foreach ($pack['available_gateways'] as $gateway) {
                $this->assertContains($gateway, $pack['payment_gateways']);
            }
        }
        // a pack file's keys all belong to the pack schema
        foreach (CountryPack::codes() as $code) {
            $this->assertSame([], array_diff_key(config("country_packs.$code"), CountryPack::GENERIC), "$code unknown keys");
        }
    }

    public function test_the_bd_pack_reproduces_the_default_behaviour(): void
    {
        $pack = CountryPack::get('BD');
        $this->assertTrue($pack['full']);
        $this->assertSame(['BDT', 'Asia/Dhaka', 'en'], [$pack['currency'], $pack['timezone'], $pack['language']]);
        $this->assertSame([], $pack['tax']['rates']);
        $this->assertFalse($pack['tax']['prices_include_tax']);
        $this->assertSame(['bkash', 'nagad', 'rocket', 'sslcommerz'], $pack['available_gateways']);
        foreach ($pack['billing'] as $key => $value) {
            $this->assertSame(IspSettings::DEFAULTS[$key], $value, "BD billing $key");
        }

        $this->unlockMoney();
        $this->api('/isp/country-pack', ['country_code' => 'BD', 'tax' => true, 'billing' => true])->assertOk();
        $company = CompanyProfile::first();
        $this->assertSame(['BD', 'BDT', 'Asia/Dhaka', 'en', 'VAT', false],
            [$company->country_code, $company->currency_code, $company->timezone, $company->language, $company->tax_label, (bool) $company->prices_include_tax]);
        $this->assertSame(0, TaxRate::count());
    }

    public function test_a_country_without_a_pack_file_gets_the_generic_pack(): void
    {
        $pack = CountryPack::get('JP');
        $this->assertFalse($pack['full']);
        $this->assertSame(['JPY', 'Asia/Tokyo', 'en'], [$pack['currency'], $pack['timezone'], $pack['language']]);
        $this->assertSame([], $pack['tax']['rates']);
    }

    public function test_applying_a_pack_sets_the_company_tax_rates_and_branch_billing(): void
    {
        $this->unlockMoney();
        $res = $this->api('/isp/country-pack', ['country_code' => 'IN', 'tax' => true, 'billing' => true])->assertOk();
        $this->assertNotEmpty($res->json('done'));

        $company = CompanyProfile::first();
        $this->assertSame(['IN', 'INR', 'Asia/Kolkata', 'GST', false],
            [$company->country_code, $company->currency_code, $company->timezone, $company->tax_label, (bool) $company->prices_include_tax]);
        $this->assertSame('INR', Money::code());
        $this->assertSame('Asia/Kolkata', config('app.timezone'));
        $this->assertEqualsCanonicalizing(['CGST 9%', 'SGST 9%'], TaxRate::pluck('name')->all());
        $this->assertTrue(TaxRate::get()->every(fn ($r) => $r->is_default && $r->is_active && $r->rate == 9));
        foreach (Branch::pluck('id') as $id) {
            $this->assertSame(15, IspSettings::get($id, 'due_days'));
        }
        $this->assertDatabaseHas('audit_logs', ['action' => 'company.country_pack_applied']);

        // applied again, the existing rates stay as they are
        TaxRate::query()->update(['rate' => 5]);
        $this->api('/isp/country-pack', ['country_code' => 'IN', 'tax' => true])->assertOk();
        $this->assertSame(2, TaxRate::count());
        $this->assertSame(5.0, (float) TaxRate::first()->rate);
    }

    public function test_tax_and_billing_are_left_alone_unless_asked(): void
    {
        $this->unlockMoney();
        IspSettings::save($this->branch->id, ['due_days' => 7]);
        $this->api('/isp/country-pack', ['country_code' => 'GB'])->assertOk();

        $company = CompanyProfile::first();
        $this->assertSame(['GB', 'GBP', 'Europe/London', 'VAT', false],
            [$company->country_code, $company->currency_code, $company->timezone, $company->tax_label, (bool) $company->prices_include_tax]);
        $this->assertSame(0, TaxRate::count());
        IspSettings::flush();
        $this->assertSame(7, IspSettings::get($this->branch->id, 'due_days'));
    }

    public function test_a_multi_zone_country_keeps_the_zone_already_picked(): void
    {
        $this->unlockMoney();
        CompanyProfile::query()->update(['country_code' => 'US', 'currency_code' => 'USD', 'timezone' => 'America/Chicago']);
        clearCompanyCache();
        $this->api('/isp/country-pack', ['country_code' => 'US'])->assertOk();
        $this->assertSame('America/Chicago', CompanyProfile::first()->timezone);
    }

    public function test_currency_and_timezone_stay_once_money_is_recorded(): void
    {
        if (! Money::locked()) {
            // any recorded invoice locks the currency and timezone
            Invoice::withoutEvents(fn () => Invoice::forceCreate(['invoice_no' => 'CP-LOCK-1', 'customer_id' => 0, 'invoice_date' => '2026-09-01', 'due_date' => '2026-09-10', 'branch_id' => $this->branch->id]));
        }
        $this->assertTrue(Money::locked());
        $res = $this->api('/isp/country-pack', ['country_code' => 'US', 'tax' => true])->assertOk();
        $company = CompanyProfile::first();
        $this->assertSame(['US', 'BDT', 'Asia/Dhaka', 'Sales tax'], [$company->country_code, $company->currency_code, $company->timezone, $company->tax_label]);
        $this->assertStringContainsString('Currency and timezone kept', $res->json('message'));
    }

    public function test_settings_show_every_country_with_its_pack_and_the_default_locale_is_shared(): void
    {
        $res = $this->api('/isp/get-settings')->assertOk();
        $bd = collect($res->json('countries'))->firstWhere('code', 'BD');
        $this->assertSame('880', $bd['pack']['phone']['calling_code']);
        $this->assertSame('en', $res->json('language'));

        $this->api('/isp/country-pack', ['country_code' => 'XX'])->assertStatus(422);
    }

    public function test_the_console_command_lists_and_applies_packs(): void
    {
        $this->unlockMoney();
        $this->artisan('isp:country-pack')->assertSuccessful();
        $this->artisan('isp:country-pack', ['country' => 'zz'])->assertFailed();
        $this->artisan('isp:country-pack', ['country' => 'ke', '--apply' => true, '--tax' => true])->assertSuccessful();
        $this->assertSame(['KE', 'KES'], [CompanyProfile::first()->country_code, CompanyProfile::first()->currency_code]);
        $this->assertSame(['VAT 16%'], TaxRate::pluck('name')->all());
    }
}
