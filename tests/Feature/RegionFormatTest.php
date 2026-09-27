<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CompanyProfile;
use App\Models\Customer;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

// Phone numbers and addresses for any country (roadmap 2.9): read against the company's country,
// stored as national digits (home country) or E.164 (others).
class RegionFormatTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::where('role', 'Superadmin')->firstOrFail();
        $this->branch = Branch::firstOrFail();
        $this->country('BD');
    }

    protected function tearDown(): void
    {
        $this->country('BD');
        parent::tearDown();
    }

    private function country(string $code): void
    {
        CompanyProfile::query()->update(['country_code' => $code]);
        clearCompanyCache();
    }

    private function api(string $uri, array $data = [])
    {
        return $this->actingAs($this->admin)->withSession(['branch' => $this->branch])->postJson($uri, $data);
    }

    public function test_phone_helper(): void
    {
        $this->assertSame('01712345678', Phone::normalize('+880 1712-345678'));
        $this->assertSame('01712345678', Phone::normalize('01712 345678'));
        $this->assertSame('+447400123456', Phone::normalize('+44 7400 123456'));
        $this->assertSame('+8801712345678', Phone::e164('01712345678'));
        $this->assertNull(Phone::normalize('12345'));
        $this->assertSame('2015550123', Phone::normalize('(201) 555-0123', 'US'));
    }

    public function test_customer_phones_are_checked_and_stored_in_one_form(): void
    {
        $this->api('/customer', ['name' => 'Phone A', 'phone' => '+880 1712-345670'])->assertOk();
        $this->assertTrue(Customer::where('name', 'Phone A')->where('phone', '01712345670')->exists());
        // the same number written differently is a duplicate
        $this->api('/customer', ['name' => 'Phone B', 'phone' => '01712-345670'])->assertStatus(422);
        $this->api('/customer', ['name' => 'Phone C', 'phone' => '12345'])->assertStatus(422)->assertJsonPath('errors.phone.0', fn ($m) => str_contains($m, '01812-345678'));
        // a foreign number with its + code is fine, kept international
        $this->api('/customer', ['name' => 'Phone D', 'phone' => '+44 7400 123456'])->assertOk();
        $this->assertTrue(Customer::where('name', 'Phone D')->where('phone', '+447400123456')->exists());
    }

    public function test_another_country_reads_national_numbers_and_needs_its_postcode(): void
    {
        $this->country('GB');
        $this->api('/customer', ['name' => 'UK A', 'phone' => '07400 123456', 'city' => 'Leeds'])->assertStatus(422)->assertJsonValidationErrors('postcode');
        $this->api('/customer', ['name' => 'UK A', 'phone' => '07400 123456', 'city' => 'Leeds', 'state' => 'West Yorkshire', 'postcode' => 'LS1 4AP'])->assertOk();
        $customer = Customer::where('name', 'UK A')->firstOrFail();
        $this->assertSame(['07400123456', 'Leeds', 'West Yorkshire', 'LS1 4AP'], [$customer->phone, $customer->city, $customer->state, $customer->postcode]);

        $this->actingAs($this->admin);
        $request = \Illuminate\Http\Request::create('/customer');
        $request->setLaravelSession(app('session.store'));
        $region = (new \App\Http\Middleware\HandleInertiaRequests)->share($request)['region']();
        $this->assertSame(['GB', 'County', 'Postcode', true, 'en-GB'], [$region['country'], $region['state_label'], $region['postcode_label'], $region['postcode_required'], $region['number_locale']]);
    }
}
