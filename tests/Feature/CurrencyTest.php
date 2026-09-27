<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CompanyProfile;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PaymentGateway;
use App\Models\User;
use App\Services\Isp\OnlinePaymentService;
use App\Support\Money;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// The company's billing currency: how amounts print, which gateways it allows, and the lock
// once money is recorded. One installation = one company in one country, for every branch.
class CurrencyTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        config(['isp.network_driver' => \App\Services\Network\NullNetworkDriver::class]);
        $this->admin = User::where('role', 'Superadmin')->firstOrFail();
        $this->branch = Branch::firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        clearCompanyCache();
        parent::tearDown();
    }

    private function useCurrency(string $code): void
    {
        CompanyProfile::query()->update(['currency_code' => $code]);
        clearCompanyCache();
    }

    // saves the ISP settings page: the branch settings as they are, plus country and currency
    private function saveProfile(array $data)
    {
        $as = $this->actingAs($this->admin)->withSession(['branch' => $this->branch]);
        $settings = $as->postJson('/isp/get-settings')->assertOk()->json();
        return $as->postJson('/isp/settings', $data + $settings);
    }

    public function test_format_uses_the_company_currency(): void
    {
        $this->useCurrency('BDT');
        $this->assertSame('Tk 1,234.50', Money::format(1234.5));
        $this->assertSame('-Tk 50.00', Money::format(-50));

        $this->useCurrency('USD');
        $this->assertSame('$1,234.50', Money::format(1234.5));
        $this->assertSame('1,234.50', Money::number(1234.5));
        $this->assertSame('USD', Money::currency()['code']);

        // an unknown code falls back to the default instead of breaking every page
        $this->useCurrency('XXX');
        $this->assertSame('BDT', Money::code());
    }

    public function test_bdt_only_gateways_are_hidden_and_refused_for_other_currencies(): void
    {
        PaymentGateway::where('branch_id', $this->branch->id)->delete();
        $bankId = DB::table('banks')->insertGetId([
            'name' => 'T Rocket', 'number' => '01700000001', 'type' => 'mobile', 'bank_name' => 'Rocket',
            'status' => 'a', 'branch_id' => $this->branch->id, 'created_at' => now(), 'ipAddress' => '127.0.0.1',
        ]);
        $gateway = PaymentGateway::create([
            'branch_id' => $this->branch->id, 'gateway' => 'rocket', 'is_active' => true, 'mode' => 'manual',
            'manual_number' => '01700000001', 'bank_id' => $bankId, 'min_amount' => 10, 'max_amount' => 5000,
        ]);

        $this->useCurrency('BDT');
        $this->assertTrue(OnlinePaymentService::isUsable($gateway));

        $this->useCurrency('USD');
        $this->assertFalse(OnlinePaymentService::isUsable($gateway));
        $this->actingAs($this->admin)->withSession(['branch' => $this->branch])->postJson('/isp/payment-gateway', [
            'gateway' => 'rocket', 'is_active' => true, 'mode' => 'manual', 'manual_number' => '01700000001',
            'bank_id' => $bankId, 'min_amount' => 10, 'max_amount' => 5000,
        ])->assertStatus(422);
    }

    public function test_settings_page_manages_country_and_currency_and_locks_the_currency(): void
    {
        $this->useCurrency('BDT');
        $settings = $this->actingAs($this->admin)->withSession(['branch' => $this->branch])->postJson('/isp/get-settings')->json();
        $this->assertSame('BDT', $settings['currency_code']);
        $this->assertSame(Money::locked(), $settings['currency_locked']);

        $this->saveProfile(['country_code' => 'ZZ', 'currency_code' => 'BDT'])->assertStatus(422);
        $this->saveProfile(['country_code' => 'BD', 'currency_code' => 'XYZ'])->assertStatus(422);

        if (Money::locked()) {
            $this->saveProfile(['country_code' => 'US', 'currency_code' => 'USD'])->assertStatus(422);
            $this->assertSame('BDT', CompanyProfile::first()->currency_code);
            // the country can still change while the currency stays
            $this->saveProfile(['country_code' => 'IN', 'currency_code' => 'BDT'])->assertOk();
            $this->assertSame('IN', CompanyProfile::first()->country_code);
            return;
        }
        $this->saveProfile(['country_code' => 'US', 'currency_code' => 'USD'])->assertOk();
        $this->assertSame('USD', CompanyProfile::first()->currency_code);
    }

    public function test_rounding_follows_the_currency_decimals(): void
    {
        $this->useCurrency('JPY');
        $this->assertSame(634.0, Money::round(633.5));
        $this->assertSame('¥1,234', Money::format(1234.4));
        $this->assertSame(1.0, Money::unit());
        $this->assertTrue(Money::equals(100.4, 100));

        $this->useCurrency('KWD');
        $this->assertSame(0.633, Money::round(0.63333));
        $this->assertSame('KD 1,234.568', Money::format(1234.5678));
        $this->assertSame(0.001, Money::unit());
        $this->assertFalse(Money::equals(1.001, 1.002));
    }

    // The same day-wise package change in a 0-decimal and a 3-decimal currency: every amount is
    // rounded to the currency's unit, and the ledger still balances.
    public static function currencies(): array
    {
        // [currency, old price, new price, unused credit, new cost, difference]; 19 of 30 days left
        return [
            'JPY' => ['JPY', 1000, 1100, 633.0, 697.0, 64.0],
            'KWD' => ['KWD', 1, 1.1, 0.633, 0.697, 0.064],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('currencies')]
    public function test_billing_and_package_change_round_to_the_currency(string $code, $old, $new, float $credit, float $cost, float $diff): void
    {
        $this->useCurrency($code);
        Carbon::setTestNow('2026-09-15 10:00:00');
        $api = fn ($uri, $data = []) => $this->actingAs($this->admin)->withSession(['branch' => $this->branch])->postJson($uri, $data);

        $areaId = $api('/area', ['name' => "CR Area $code"])->json('id');
        $api('/isp/package', ['name' => "CR old $code", 'download_mbps' => 10, 'upload_mbps' => 5, 'price' => $old, 'billing_cycle' => 'monthly'])->assertOk();
        $api('/isp/package', ['name' => "CR new $code", 'download_mbps' => 20, 'upload_mbps' => 5, 'price' => $new, 'billing_cycle' => 'monthly'])->assertOk();
        $pkg = fn ($name) => DB::table('packages')->where('name', $name)->value('id');
        $api('/customer', ['name' => "CR Customer $code", 'phone' => $code === 'JPY' ? '01799999701' : '01799999702', 'area_id' => $areaId])->assertOk();
        $customer = Customer::where('phone', $code === 'JPY' ? '01799999701' : '01799999702')->firstOrFail();
        $id = $api('/isp/connection', ['customer_id' => $customer->id, 'package_id' => $pkg("CR old $code"), 'connection_type' => 'pppoe', 'pppoe_username' => "cr_user_$code", 'activate_now' => true])->json('id');
        $api('/isp/connection-pay', ['id' => $id, 'cycles' => 1, 'method' => 'cash'])->assertOk();

        $bill = Invoice::where('connection_id', $id)->whereNotNull('service_months')->firstOrFail();
        $this->assertSame($code === 'JPY' ? '1000' : '1.000', $bill->total); // printed with the currency's decimals

        Carbon::setTestNow('2026-09-25 12:00:00');
        $quote = $api('/isp/connection-package-quote', ['id' => $id, 'package_id' => $pkg("CR new $code")])->assertOk()->json();
        $this->assertEquals([19, $credit, $cost, $diff], [$quote['days_left'], $quote['credit'], $quote['cost'], $quote['difference']]);
        $api('/isp/connection-change-package', ['id' => $id, 'package_id' => $pkg("CR new $code")])->assertOk();
        $this->assertEquals($diff, (float) $customer->fresh()->ledger_balance);

        $api('/isp/payment', ['customer_id' => $customer->id, 'amount' => $diff, 'method' => 'cash', 'payment_date' => '2026-09-25'])->assertOk();
        $this->assertEquals(0.0, (float) $customer->fresh()->ledger_balance);
        $this->assertEquals($diff, (float) DB::table('receives')->where('customer_id', $customer->id)->latest('id')->value('amount')); // cash book keeps all decimals
        $this->artisan('isp:ledger-check')->assertSuccessful();
    }
}
