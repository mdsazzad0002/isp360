<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CompanyProfile;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Reseller;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Isp\ResellerLedgerService;
use App\Services\Isp\ResellerWalletService;
use App\Services\Isp\TaxReportService;
use App\Services\Isp\TaxService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

// Sales tax (VAT / GST): rates, exclusive and inclusive prices, per-package rates, notes, voids,
// reseller margins net of tax, package changes and the tax report.
class TaxTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private Branch $branch;
    private int $areaId;
    private int $phone = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['isp.network_driver' => \App\Services\Network\NullNetworkDriver::class]);
        $this->admin = User::where('role', 'Superadmin')->firstOrFail();
        $this->branch = Branch::firstOrFail();
        Carbon::setTestNow('2026-09-15 10:00:00');
        CompanyProfile::query()->update(['currency_code' => 'BDT', 'prices_include_tax' => false, 'tax_label' => 'VAT']);
        clearCompanyCache();
        TaxRate::query()->delete();
        TaxService::flush();
        $this->areaId = $this->api('/area', ['name' => 'TX Area'])->json('id');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        clearCompanyCache();
        TaxService::flush();
        parent::tearDown();
    }

    private function api(string $uri, array $data = [])
    {
        return $this->actingAs($this->admin)->withSession(['branch' => $this->branch])->postJson($uri, $data);
    }

    private function rate(string $name, float $rate, bool $default = true): int
    {
        return $this->api('/isp/tax-rate', ['name' => $name, 'rate' => $rate, 'is_default' => $default])->assertOk()->json('id');
    }

    private function package(string $name, float $price, array $extra = []): Package
    {
        $this->api('/isp/package', $extra + ['name' => $name, 'download_mbps' => 10, 'upload_mbps' => 5, 'price' => $price, 'billing_cycle' => 'monthly'])->assertOk();
        return Package::where('name', $name)->whereNull('reseller_id')->firstOrFail();
    }

    private function customer(array $extra = []): Customer
    {
        $phone = '0179999' . str_pad((string) (8000 + ++$this->phone), 4, '0', STR_PAD_LEFT);
        $this->api('/customer', $extra + ['name' => "TX Customer {$this->phone}", 'phone' => $phone, 'area_id' => $this->areaId])->assertOk();
        return Customer::where('phone', $phone)->firstOrFail();
    }

    private function connect(Customer $customer, Package $package): int
    {
        return $this->api('/isp/connection', [
            'customer_id' => $customer->id, 'package_id' => $package->id, 'connection_type' => 'pppoe',
            'pppoe_username' => 'tx_user_' . $customer->id, 'activate_now' => true,
        ])->assertOk()->json('id');
    }

    private function bill(int $connectionId): Invoice
    {
        return Invoice::with('items')->where('connection_id', $connectionId)->whereNotNull('service_months')->latest('id')->firstOrFail();
    }

    public function test_no_rates_means_no_tax(): void
    {
        $bill = $this->bill($this->connect($this->customer(), $this->package('TX Plain', 1000)));
        $this->assertEquals([1000.0, 0.0, 1000.0], [(float) $bill->subtotal, (float) $bill->tax, (float) $bill->total]);
        $this->assertNull($bill->items[0]->taxes);
    }

    public function test_exclusive_tax_is_added_and_reported(): void
    {
        $this->rate('VAT', 15);
        $customer = $this->customer();
        $id = $this->connect($customer, $this->package('TX 1000', 1000));
        $bill = $this->bill($id);
        $this->assertEquals([1000.0, 150.0, 150.0, 1150.0], [(float) $bill->subtotal, (float) $bill->tax, (float) $bill->tax_total, (float) $bill->total]);
        $this->assertFalse($bill->tax_inclusive);
        $this->assertEquals(['VAT', 15.0, 1000.0, 150.0], [$bill->items[0]->taxes[0]['name'], $bill->items[0]->taxes[0]['rate'], $bill->items[0]->taxes[0]['taxable'], $bill->items[0]->taxes[0]['amount']]);
        $this->assertEquals(1150.0, (float) $customer->fresh()->ledger_balance);

        // the pay panel asks for a further cycle with its tax
        $quote = $this->api('/isp/connection-pay-quote', ['id' => $id])->assertOk()->json();
        $this->assertEquals(1150.0, $quote['charge']);
        $this->assertEquals(2300.0, collect($quote['options'])->firstWhere('cycles', 2)['amount']);

        // credit note of 115 gives back its share of tax (15); a void takes the rest off in its own period
        $this->api('/isp/invoice-note', ['id' => $bill->id, 'type' => 'credit', 'amount' => 115, 'reason' => 'Outage'])->assertOk();
        $bill->refresh();
        $this->assertEquals([1035.0, 135.0], [(float) $bill->total, (float) $bill->tax_total]);

        $report = TaxReportService::report('2026-09-01', '2026-09-30');
        $this->assertEquals([['name' => 'VAT', 'rate' => 15.0, 'taxable' => 1000.0, 'tax' => 150.0]], $report['rates']);
        $this->assertEquals([150.0, 15.0, 135.0], [$report['totals']['sales'], $report['totals']['credit_notes'], $report['totals']['net']]);

        Carbon::setTestNow('2026-10-02 10:00:00');
        $this->api('/isp/invoice-void', ['id' => $bill->id, 'reason' => 'Wrong customer'])->assertOk();
        $this->assertEquals(135.0, TaxReportService::report('2026-09-01', '2026-09-30')['totals']['net']); // September stays as filed
        $october = TaxReportService::report('2026-10-01', '2026-10-31')['totals'];
        $this->assertEquals([135.0, -135.0], [$october['voided'], $october['net']]);
        $this->artisan('isp:ledger-check')->assertSuccessful();
    }

    public function test_inclusive_prices_hold_the_tax(): void
    {
        $this->rate('VAT', 15);
        CompanyProfile::query()->update(['prices_include_tax' => true]);
        clearCompanyCache();
        $bill = $this->bill($this->connect($this->customer(), $this->package('TX Incl', 1150)));
        $this->assertEquals([1150.0, 150.0, 1150.0], [(float) $bill->subtotal, (float) $bill->tax, (float) $bill->total]);
        $this->assertTrue($bill->tax_inclusive);
        $this->assertEquals(1000.0, $bill->items[0]->taxes[0]['taxable']);
        $this->artisan('isp:ledger-check')->assertSuccessful();
    }

    public function test_package_rates_exempt_and_untaxed_invoices(): void
    {
        $this->rate('VAT', 15);
        $cgst = $this->rate('CGST', 9, false);
        $sgst = $this->rate('SGST', 9, false);

        $custom = $this->bill($this->connect($this->customer(), $this->package('TX GST', 1000, ['tax_mode' => 'custom', 'tax_rate_ids' => [$cgst, $sgst]])));
        $this->assertEquals([180.0, 1180.0], [(float) $custom->tax, (float) $custom->total]);
        $this->assertEquals(['CGST', 'SGST'], array_column($custom->items[0]->taxes, 'name'));

        $exempt = $this->bill($this->connect($this->customer(), $this->package('TX Exempt', 1000, ['tax_mode' => 'exempt'])));
        $this->assertEquals([0.0, 1000.0], [(float) $exempt->tax, (float) $exempt->total]);

        // an opening balance is old due, never taxed
        $old = $this->customer(['previous_due' => 500]);
        $this->assertEquals(500.0, (float) $old->fresh()->ledger_balance);

        // a manual invoice uses the default rates, unless marked no-tax; the discount is taken before tax
        $customer = $this->customer();
        $items = [['description' => 'Router', 'unit_price' => 2000, 'quantity' => 1]];
        $this->api('/isp/invoice', ['customer_id' => $customer->id, 'invoice_date' => '2026-09-15', 'due_date' => '2026-09-20', 'discount' => 200, 'items' => $items])->assertOk();
        $this->api('/isp/invoice', ['customer_id' => $customer->id, 'invoice_date' => '2026-09-15', 'due_date' => '2026-09-20', 'no_tax' => true, 'items' => $items])->assertOk();
        $manual = Invoice::where('customer_id', $customer->id)->orderBy('id')->get();
        $this->assertEquals([270.0, 2070.0], [(float) $manual[0]->tax, (float) $manual[0]->total]);
        $this->assertEquals([0.0, 2000.0], [(float) $manual[1]->tax, (float) $manual[1]->total]);
        $this->artisan('isp:ledger-check')->assertSuccessful();
    }

    // The reseller earns on the amount before tax; the tax is always the company's.
    public function test_reseller_margin_is_net_of_tax(): void
    {
        $this->rate('VAT', 15);
        $base = $this->package('TX Base', 800, ['visibility' => 'hidden']);
        $reseller = Reseller::create([
            'code' => 'RS-TX', 'name' => 'TX Reseller', 'phone' => '01700007777', 'username' => 'tx_reseller',
            'password' => Hash::make('secret'), 'branch_id' => $this->branch->id,
        ]);
        $this->actingAs($reseller, 'reseller')->postJson('/reseller/package', ['base_package_id' => $base->id, 'name' => 'TX Mine', 'price' => 1000])->assertOk();
        $mine = Package::where('reseller_id', $reseller->id)->where('name', 'TX Mine')->firstOrFail();
        $customer = $this->customer();
        $customer->forceFill(['reseller_id' => $reseller->id])->save();
        $bill = $this->bill($this->connect($customer, $mine));
        $this->assertEquals([1150.0, 150.0, 800.0], [(float) $bill->total, (float) $bill->tax, (float) $bill->reseller_cost]);

        $this->api('/isp/payment', ['customer_id' => $customer->id, 'amount' => 1150, 'method' => 'cash', 'payment_date' => '2026-09-15'])->assertOk();
        $this->assertEquals(200.0, ResellerWalletService::summary($reseller->id)['earned']); // 1000 - 800, not 1150 - 800
        $this->assertEquals(ResellerWalletService::summary($reseller->id)['balance'], ResellerLedgerService::statement($reseller->id)['closing']);
    }

    // Day-wise package change is worked out before tax; the adjustment carries the new package's tax.
    public function test_package_change_adds_and_returns_tax(): void
    {
        $this->rate('VAT', 15);
        $small = $this->package('TX 600', 600);
        $big = $this->package('TX 900', 900);
        $customer = $this->customer();
        $id = $this->connect($customer, $small);
        $this->api('/isp/connection-pay', ['id' => $id, 'cycles' => 1, 'method' => 'cash'])->assertOk(); // 690 with tax

        // 19 of 30 days left: unused 380, new 570, difference 190 + 28.50 tax
        Carbon::setTestNow('2026-09-25 12:00:00');
        $quote = $this->api('/isp/connection-package-quote', ['id' => $id, 'package_id' => $big->id])->assertOk()->json();
        $this->assertEquals([380, 570, 190, 28.5, 218.5], [$quote['credit'], $quote['cost'], $quote['difference'], $quote['difference_tax'], $quote['difference_gross']]);
        $this->api('/isp/connection-change-package', ['id' => $id, 'package_id' => $big->id])->assertOk();
        $this->assertEquals(218.5, (float) $customer->fresh()->ledger_balance);
        $adjustment = Invoice::where('customer_id', $customer->id)->whereNull('service_months')->latest('id')->firstOrFail();
        $this->assertEquals([190.0, 28.5], [(float) $adjustment->subtotal, (float) $adjustment->tax]);

        // back down a day later: 18 days at 900 (540) - 18 days at 600 (360) = 180 + 27 tax back
        Carbon::setTestNow('2026-09-26 12:00:00');
        $this->api('/isp/connection-change-package', ['id' => $id, 'package_id' => $small->id])->assertOk();
        $this->assertEquals(218.5 - 207.0, (float) $customer->fresh()->ledger_balance);
        $credit = DB::table('customer_payments')->where('customer_id', $customer->id)->where('method', 'adjustment')->first();
        $this->assertEquals([207.0, 27.0], [(float) $credit->amount, (float) $credit->tax_amount]);

        $report = TaxReportService::report('2026-09-01', '2026-09-30');
        $this->assertEquals([90.0 + 28.5, 27.0, 90.0 + 28.5 - 27.0], [$report['totals']['sales'], $report['totals']['credits'], $report['totals']['net']]);
        $this->artisan('isp:ledger-check')->assertSuccessful();
    }

    public function test_settings_and_report_pages(): void
    {
        $this->api('/isp/tax-rate', ['name' => '', 'rate' => 15])->assertStatus(422);
        $this->api('/isp/tax-rate', ['name' => 'VAT', 'rate' => 150])->assertStatus(422);
        $id = $this->rate('VAT', 15);
        $this->api('/isp/tax-rate', ['id' => $id, 'name' => 'VAT', 'rate' => 7.5, 'is_default' => true, 'is_active' => false])->assertOk();
        $this->assertEquals([7.5, false], [TaxRate::find($id)->rate, TaxRate::find($id)->is_active]);
        $this->assertSame([], TaxService::defaults()); // switched off: no longer charged

        $settings = $this->api('/isp/get-settings')->assertOk()->json();
        $this->api('/isp/settings', ['tax_label' => 'GST', 'tax_number' => 'GST-123', 'prices_include_tax' => true] + $settings)->assertOk();
        $company = CompanyProfile::first();
        $this->assertEquals(['GST', 'GST-123', true], [$company->tax_label, $company->tax_number, (bool) $company->prices_include_tax]);

        $this->actingAs($this->admin)->withSession(['branch' => $this->branch])->get('/isp/tax-report')->assertOk();
        $this->api('/isp/get-tax-report', ['dateFrom' => '2026-09-01', 'dateTo' => '2026-09-30'])->assertOk()->assertJsonPath('label', 'GST');
    }
}
