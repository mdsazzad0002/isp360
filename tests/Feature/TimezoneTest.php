<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CompanyProfile;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Support\Money;
use App\Support\Region;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// The app runs in the company's timezone: now(), stored wall-clock times, and prepaid expiry that
// keeps the same local time across a daylight-saving change.
class TimezoneTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private Branch $branch;
    private string $originalTz;

    protected function setUp(): void
    {
        parent::setUp();
        config(['isp.network_driver' => \App\Services\Network\NullNetworkDriver::class]);
        $this->admin = User::where('role', 'Superadmin')->firstOrFail();
        $this->branch = Branch::firstOrFail();
        $this->originalTz = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Region::apply($this->originalTz);
        clearCompanyCache();
        \App\Services\Isp\IspSettings::flush();
        parent::tearDown();
    }

    private function api(string $uri, array $data = [])
    {
        return $this->actingAs($this->admin)->withSession(['branch' => $this->branch])->postJson($uri, $data);
    }

    public function test_the_app_runs_in_the_company_timezone(): void
    {
        CompanyProfile::query()->update(['timezone' => 'America/New_York']);
        clearCompanyCache();
        Region::apply();
        $this->assertSame('America/New_York', config('app.timezone'));
        $this->assertSame('America/New_York', now()->getTimezone()->getName());

        // an invalid stored zone falls back to the default instead of breaking the app
        CompanyProfile::query()->update(['timezone' => 'Mars/Olympus']);
        clearCompanyCache();
        $this->assertSame(Region::DEFAULT_TIMEZONE, Region::timezone());

        $this->assertSame('Asia/Dhaka', Region::timezonesFor('BD')[0]);
        $this->assertSame('America/New_York', Region::timezonesFor('US')[0]); // the country's main zone first
        $this->assertContains('America/Los_Angeles', Region::timezonesFor('US'));
    }

    public function test_prepaid_expiry_keeps_local_time_across_dst(): void
    {
        Region::apply('America/New_York');
        // US daylight saving ends on 1 Nov 2026: 20 Oct is UTC-4, 20 Nov is UTC-5
        Carbon::setTestNow('2026-10-20 10:00:00');

        $this->api('/isp/zone', ['name' => 'TZ Zone', 'code' => 'TZZ'])->assertOk();
        $this->api('/area', ['name' => 'TZ Area', 'zone_id' => DB::table('zones')->where('name', 'TZ Zone')->value('id')])->assertOk();
        $areaId = DB::table('areas')->where('name', 'TZ Area')->value('id');
        $this->api('/isp/package', ['name' => 'TZ 10', 'download_mbps' => 10, 'upload_mbps' => 5, 'price' => 600, 'billing_cycle' => 'monthly'])->assertOk();
        $packageId = DB::table('packages')->where('name', 'TZ 10')->value('id');
        $this->api('/customer', ['name' => 'TZ Customer', 'phone' => '01799999201', 'area_id' => $areaId])->assertOk();
        $customer = Customer::where('phone', '01799999201')->firstOrFail();
        $this->api('/isp/payment', ['customer_id' => $customer->id, 'amount' => 600, 'method' => 'cash', 'payment_date' => '2026-10-20'])->assertOk();
        $this->api('/isp/connection', [
            'customer_id' => $customer->id, 'package_id' => $packageId, 'connection_type' => 'pppoe', 'pppoe_username' => 'tz_user_1', 'activate_now' => true,
        ])->assertOk();
        $line = fn () => DB::table('connections')->where('pppoe_username', 'tz_user_1')->first(['status', 'expire_at']);

        // same local time a month later, not shifted by the hour DST gave back
        $this->assertEquals('2026-11-20 10:00:00', $line()->expire_at);
        // rows that used MySQL's CURRENT_TIMESTAMP default are stamped in the company's time
        $this->assertEquals('2026-10-20 10:00:00', LedgerEntry::where('customer_id', $customer->id)->latest('id')->value('created_at')->format('Y-m-d H:i:s'));

        Carbon::setTestNow('2026-11-20 09:59:59');
        $this->artisan('isp:process-overdue')->assertSuccessful();
        $this->assertEquals('active', $line()->status);

        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->artisan('isp:process-overdue')->assertSuccessful();
        $this->assertEquals('suspended', $line()->status);

        $this->artisan('isp:ledger-check')->assertSuccessful();
    }

    public function test_settings_manage_the_timezone_and_lock_it_with_the_currency(): void
    {
        $as = $this->actingAs($this->admin)->withSession(['branch' => $this->branch]);
        $settings = $as->postJson('/isp/get-settings')->assertOk()->json();
        $this->assertSame(Region::timezone(), $settings['timezone']);
        $bd = collect($settings['countries'])->firstWhere('code', 'BD');
        $this->assertSame(['Asia/Dhaka'], $bd['timezones']);

        // a zone must belong to the chosen country
        $as->postJson('/isp/settings', ['country_code' => 'BD', 'timezone' => 'Europe/London'] + $settings)->assertStatus(422);

        if (Money::locked()) {
            $as->postJson('/isp/settings', ['country_code' => 'US', 'currency_code' => $settings['currency_code'], 'timezone' => 'America/Chicago'] + $settings)
                ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'timezone'));
            $this->assertSame($settings['timezone'], CompanyProfile::first()->timezone);
            return;
        }
        $as->postJson('/isp/settings', ['country_code' => 'US', 'currency_code' => 'USD', 'timezone' => 'America/Chicago'] + $settings)->assertOk();
        $this->assertSame('America/Chicago', CompanyProfile::first()->timezone);
    }
}
