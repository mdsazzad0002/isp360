<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Connection;
use App\Models\Customer;
use App\Models\CustomerConsent;
use App\Models\CustomerDocument;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Isp\IspSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// KYC, terms / privacy acceptance, marketing consent, data export and erasure (roadmap 2.6).
class ComplianceTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private Branch $branch;
    private int $areaId;
    private int $packageId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['isp.network_driver' => \App\Services\Network\NullNetworkDriver::class]);
        Storage::fake('local');
        $this->admin = User::where('role', 'Superadmin')->firstOrFail();
        $this->branch = Branch::firstOrFail();
        $this->areaId = $this->api('/area', ['name' => 'CP Area'])->json('id');
        $this->api('/isp/package', ['name' => 'CP 5', 'download_mbps' => 5, 'upload_mbps' => 2, 'price' => 500, 'billing_cycle' => 'monthly'])->assertOk();
        $this->packageId = \App\Models\Package::where('name', 'CP 5')->value('id');
    }

    protected function tearDown(): void
    {
        IspSettings::flush();
        parent::tearDown();
    }

    private function api(string $uri, array $data = [])
    {
        return $this->actingAs($this->admin)->withSession(['branch' => $this->branch])->postJson($uri, $data);
    }

    private function customer(string $phone, array $extra = []): Customer
    {
        $this->api('/customer', $extra + ['name' => "CP {$phone}", 'phone' => $phone, 'area_id' => $this->areaId, 'email' => "cp{$phone}@example.test"])->assertOk();
        return Customer::where('phone', $phone)->firstOrFail();
    }

    private function connect(Customer $customer, bool $activate = true)
    {
        return $this->api('/isp/connection', ['customer_id' => $customer->id, 'package_id' => $this->packageId, 'connection_type' => 'pppoe',
            'pppoe_username' => 'cp_' . $customer->id, 'pppoe_password' => 'pw', 'mac_address' => 'AA:BB:CC:DD:EE:01', 'activate_now' => $activate]);
    }

    public function test_kyc_can_be_required_before_activation(): void
    {
        IspSettings::save($this->branch->id, ['kyc_required' => true]);
        $customer = $this->customer('01712345601');
        $this->connect($customer)->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'identity document'));
        $this->assertSame(0, Connection::where('customer_id', $customer->id)->count()); // nothing half-created

        $this->call('POST', '/isp/kyc-document', ['customer_id' => $customer->id, 'type' => 'nid', 'number' => '1990123456789'], [], ['file' => UploadedFile::fake()->image('nid.jpg')],
            ['HTTP_ACCEPT' => 'application/json'])->assertOk();
        $doc = CustomerDocument::where('customer_id', $customer->id)->sole();
        Storage::disk('local')->assertExists($doc->file_path);
        $this->assertArrayNotHasKey('file_path', $this->api('/isp/get-compliance', ['customer_id' => $customer->id])->json('documents.0'));
        $this->get("/isp/kyc-file/{$doc->id}")->assertOk();

        $this->connect($customer)->assertStatus(422); // uploaded but not verified yet
        $this->api('/isp/kyc-review', ['id' => $doc->id, 'status' => 'verified'])->assertOk();
        $this->assertTrue($this->api('/isp/get-compliance', ['customer_id' => $customer->id])->json('kyc_verified'));
        $this->connect($customer)->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'kyc.document_verified']);
    }

    public function test_customers_accept_new_terms_in_the_portal(): void
    {
        $customer = $this->customer('01712345602', ['username' => 'cp_portal', 'password' => 'secret123']);
        $this->api('/isp/legal-publish', ['type' => 'terms', 'body' => 'These are the terms of service, version one.'])->assertOk();

        $this->actingAs($customer, 'customer')->get('/customer-portal/dashboard')->assertOk();
        $this->actingAs($customer, 'customer')->postJson('/customer-portal/accept-legal')->assertOk()->assertJsonPath('accepted', 1);
        $consent = CustomerConsent::where('customer_id', $customer->id)->sole();
        $this->assertSame(['terms', 1, true, 'portal'], [$consent->type, $consent->version, $consent->granted, $consent->source]);
        $this->assertSame([], \App\Services\Isp\ComplianceService::pendingFor($customer));

        // a new version has to be accepted again
        $this->api('/isp/legal-publish', ['type' => 'terms', 'body' => 'These are the terms of service, version two.'])->assertOk();
        $this->assertSame(2, \App\Services\Isp\ComplianceService::pendingFor($customer)[0]['version']);
    }

    public function test_marketing_opt_out_stops_promotions(): void
    {
        $in = $this->customer('01712345603');
        $out = $this->customer('01712345604');
        $this->actingAs($out, 'customer')->postJson('/customer-portal/marketing', ['opt_out' => true])->assertOk();
        $this->assertTrue((bool) $out->fresh()->marketing_opt_out);

        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response('OK')]);
        $gateway = new \App\Models\SmsGateway();
        $gateway->forceFill(['name' => 'CP SMS', 'provider_type' => 'custom', 'method' => 'GET', 'url_template' => 'https://sms.example.test/s?to={number}&m={message}', 'is_active' => true, 'is_default' => true, 'branch_id' => $this->branch->id, 'ipAddress' => '127.0.0.1'])->save();
        $this->api('/send-sms-promotion', ['customerIds' => [$in->id, $out->id], 'message' => 'Offer'])->assertOk()->assertJsonPath('sent', 1);
        $this->assertFalse(\App\Models\SmsLog::where('customer_id', $out->id)->exists());
    }

    public function test_export_and_pseudonymised_erasure(): void
    {
        $customer = $this->customer('01712345605', ['username' => 'cp_erase', 'password' => 'secret123', 'nid' => '123']);
        $this->connect($customer)->assertOk();
        $connection = Connection::where('customer_id', $customer->id)->firstOrFail();
        $this->api('/isp/payment', ['customer_id' => $customer->id, 'amount' => 500, 'method' => 'cash', 'payment_date' => now()->toDateString()])->assertOk();

        $data = $this->api('/isp/customer-data-export', ['id' => $customer->id])->assertOk()->json();
        $this->assertSame($customer->phone, $data['customer']['phone']);
        $this->assertArrayNotHasKey('password', $data['customer']);
        $this->assertCount(1, $data['invoices']);

        $this->api('/isp/customer-erase', ['id' => $customer->id, 'reason' => 'GDPR request'])->assertStatus(422); // line still in service
        $this->api('/isp/connection-action', ['id' => $connection->id, 'action' => 'terminate', 'reason' => 'Leaving'])->assertOk();
        $this->api('/isp/customer-erase', ['id' => $customer->id, 'reason' => 'GDPR request'])->assertOk();

        $customer->refresh();
        $this->assertSame(["Erased customer #{$customer->id}", null, null, null, null], [$customer->name, $customer->phone, $customer->email, $customer->nid, $customer->username]);
        $this->assertNotNull($customer->erased_at);
        $this->assertNull($connection->fresh()->mac_address);
        // the money stays
        $this->assertSame('paid', Invoice::where('customer_id', $customer->id)->first()->status);
        $this->artisan('isp:ledger-check')->assertSuccessful();
        $this->assertFalse(auth('customer')->attempt(['username' => 'cp_erase', 'password' => 'secret123']));
        $this->api('/isp/customer-erase', ['id' => $customer->id, 'reason' => 'again'])->assertStatus(422);
    }
}
