<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\OnlinePayment;
use App\Models\PaymentGateway;
use App\Models\User;
use App\Services\Isp\CollectionService;
use App\Services\Isp\LedgerService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// Customer online payments: admin gateway setup, bKash / SSLCommerz checkout with faked
// gateway servers, manual (Rocket) TrxID review, idempotent callbacks and the wallet.
class OnlinePaymentTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private Branch $branch;
    private Customer $customer;
    private int $bankId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['isp.network_driver' => \App\Services\Network\NullNetworkDriver::class]);
        Http::preventStrayRequests();
        $this->admin = User::where('role', 'Superadmin')->firstOrFail();
        $this->branch = Branch::firstOrFail();
        PaymentGateway::where('branch_id', $this->branch->id)->delete();

        $this->bankId = DB::table('banks')->insertGetId([
            'name' => 'T bKash Merchant', 'number' => '01700000000', 'type' => 'mobile', 'bank_name' => 'bKash',
            'status' => 'a', 'branch_id' => $this->branch->id, 'created_at' => now(), 'ipAddress' => '127.0.0.1',
        ]);
        $this->admin('/customer', ['name' => 'T Online Customer', 'phone' => '01799999111', 'previous_due' => 500])->assertOk();
        $this->customer = Customer::where('phone', '01799999111')->firstOrFail();
    }

    private function admin(string $uri, array $data = [])
    {
        return $this->actingAs($this->admin)->withSession(['branch' => $this->branch])->postJson($uri, $data);
    }

    private function portal(string $uri, array $data = [])
    {
        return $this->actingAs($this->customer, 'customer')->postJson($uri, $data);
    }

    private function setupGateway(string $gateway, array $extra = []): void
    {
        $this->admin('/isp/payment-gateway', $extra + [
            'gateway' => $gateway, 'is_active' => true, 'sandbox' => true, 'bank_id' => $this->bankId,
            'min_amount' => 10, 'max_amount' => 50000,
        ])->assertOk();
    }

    private function fakeBkash(string $trxStatus = 'Completed', string $amount = '500.00'): void
    {
        Http::fake([
            '*/token/grant' => Http::response(['id_token' => 'tok']),
            '*/checkout/create' => Http::response(['paymentID' => 'PAY123', 'bkashURL' => 'https://sandbox.bka.sh/pay/PAY123', 'statusCode' => '0000']),
            '*/checkout/execute' => Http::response(['paymentID' => 'PAY123', 'trxID' => 'BKTRX1', 'transactionStatus' => $trxStatus, 'amount' => $amount]),
            '*/payment/status' => Http::response(['paymentID' => 'PAY123', 'transactionStatus' => 'Initiated']),
        ]);
    }

    public function test_gateway_setup_requires_credentials_and_hides_secrets(): void
    {
        // API mode without credentials can't be switched on
        $this->admin('/isp/payment-gateway', ['gateway' => 'bkash', 'is_active' => true, 'mode' => 'api', 'bank_id' => $this->bankId, 'min_amount' => 10, 'max_amount' => 1000])
            ->assertStatus(422);
        // Rocket has no API mode
        $this->admin('/isp/payment-gateway', ['gateway' => 'rocket', 'is_active' => true, 'mode' => 'api', 'bank_id' => $this->bankId, 'min_amount' => 10, 'max_amount' => 1000])
            ->assertStatus(422);

        $this->setupGateway('bkash', ['mode' => 'api', 'credentials' => ['app_key' => 'k', 'app_secret' => 'SECRET', 'username' => 'u', 'password' => 'PW']]);
        // saving again with blank secrets keeps them
        $this->setupGateway('bkash', ['mode' => 'api', 'credentials' => ['app_key' => 'k2', 'app_secret' => '', 'username' => 'u', 'password' => '']]);
        $gw = PaymentGateway::where('branch_id', $this->branch->id)->where('gateway', 'bkash')->first();
        $this->assertSame('SECRET', $gw->credential('app_secret'));
        $this->assertSame('k2', $gw->credential('app_key'));
        $this->assertStringNotContainsString('SECRET', DB::table('payment_gateways')->where('id', $gw->id)->value('credentials'));

        $json = $this->admin('/isp/get-payment-gateways')->assertOk()->json();
        $bkash = collect($json['gateways'])->firstWhere('gateway', 'bkash');
        $this->assertSame('', $bkash['credentials']['app_secret']);
        $this->assertTrue($bkash['credentials']['has_app_secret']);
        $this->assertStringNotContainsString('SECRET', json_encode($json));
    }

    public function test_bkash_checkout_pays_the_bill_once(): void
    {
        $this->setupGateway('bkash', ['mode' => 'api', 'credentials' => ['app_key' => 'k', 'app_secret' => 's', 'username' => 'u', 'password' => 'p']]);
        $this->fakeBkash();

        $this->portal('/customer-portal/pay/start', ['gateway' => 'bkash', 'amount' => 5, 'purpose' => 'bill'])->assertStatus(422); // below minimum
        $res = $this->portal('/customer-portal/pay/start', ['gateway' => 'bkash', 'amount' => 500, 'purpose' => 'bill'])->assertOk();
        $this->assertSame('https://sandbox.bka.sh/pay/PAY123', $res->json('redirect'));
        $op = OnlinePayment::where('customer_id', $this->customer->id)->latest('id')->first();
        $this->assertSame('initiated', $op->status);

        // callback arrives twice (browser refresh): money is recorded once
        $this->get("/api/payment/callback/bkash/{$op->ref}?paymentID=PAY123&status=success")->assertRedirect("/customer-portal/pay?ref={$op->ref}");
        $this->get("/api/payment/callback/bkash/{$op->ref}?paymentID=PAY123&status=success")->assertRedirect();

        $op->refresh();
        $this->assertSame('completed', $op->status);
        $this->assertSame('BKTRX1', $op->trx_id);
        $this->assertSame(1, CustomerPayment::where('customer_id', $this->customer->id)->count());
        $payment = CustomerPayment::find($op->customer_payment_id);
        $this->assertSame('bkash', $payment->method);
        $this->assertSame('gateway', $payment->source);
        $this->assertEquals(0.0, LedgerService::balance($this->customer->id));
        $this->assertSame(1, DB::table('receives')->where('customer_payment_id', $payment->id)->count());

        // the result page belongs to this customer only
        $this->actingAs($this->customer, 'customer')->get("/customer-portal/pay?ref={$op->ref}")->assertOk();
    }

    public function test_bkash_cancel_and_amount_mismatch(): void
    {
        $this->setupGateway('bkash', ['mode' => 'api', 'credentials' => ['app_key' => 'k', 'app_secret' => 's', 'username' => 'u', 'password' => 'p']]);
        $this->fakeBkash('Completed', '100.00'); // gateway charged less than asked

        $this->portal('/customer-portal/pay/start', ['gateway' => 'bkash', 'amount' => 500, 'purpose' => 'bill'])->assertOk();
        $op = OnlinePayment::latest('id')->first();
        $this->get("/api/payment/callback/bkash/{$op->ref}?paymentID=PAY123&status=cancel");
        $this->assertSame('cancelled', $op->fresh()->status);

        $this->portal('/customer-portal/pay/start', ['gateway' => 'bkash', 'amount' => 500, 'purpose' => 'bill'])->assertOk();
        $op = OnlinePayment::latest('id')->first();
        $this->get("/api/payment/callback/bkash/{$op->ref}?paymentID=WRONG&status=success");
        $this->assertSame('failed', $op->fresh()->status); // payment id doesn't match ours

        $this->portal('/customer-portal/pay/start', ['gateway' => 'bkash', 'amount' => 500, 'purpose' => 'bill'])->assertOk();
        $op = OnlinePayment::latest('id')->first();
        $this->get("/api/payment/callback/bkash/{$op->ref}?paymentID=PAY123&status=success");
        $op->refresh();
        $this->assertSame('pending_review', $op->status);
        $this->assertSame(0, CustomerPayment::where('customer_id', $this->customer->id)->count());

        // admin confirms what was really received
        $this->admin('/isp/online-payment-approve', ['id' => $op->id, 'amount' => 100])->assertOk();
        $this->assertEquals(400.0, LedgerService::balance($this->customer->id));
    }

    public function test_sslcommerz_wallet_top_up_with_ipn(): void
    {
        $this->setupGateway('sslcommerz', ['mode' => 'api', 'credentials' => ['store_id' => 'test', 'store_password' => 'test@ssl']]);
        Http::fake([
            '*/gwprocess/v4/api.php' => Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => 'https://sandbox.sslcommerz.com/pay/x', 'sessionkey' => 'SK1']),
            '*/validator/api/validationserverAPI.php*' => function ($request) {
                $ref = OnlinePayment::latest('id')->value('ref');
                return Http::response(['status' => 'VALID', 'tran_id' => $ref, 'amount' => '800.00', 'currency_amount' => '800.00', 'currency_type' => 'BDT', 'bank_tran_id' => 'SSLBANK1', 'val_id' => 'V1']);
            },
        ]);

        // 800 top-up while 500 is due: 500 pays the bill, 300 stays in the wallet
        $this->portal('/customer-portal/pay/start', ['gateway' => 'sslcommerz', 'amount' => 800, 'purpose' => 'wallet'])->assertOk();
        $op = OnlinePayment::latest('id')->first();

        // cross-site POST back from SSLCommerz (no session), then the IPN
        $this->post("/api/payment/callback/sslcommerz/{$op->ref}", ['tran_id' => $op->ref, 'val_id' => 'V1', 'status' => 'VALID'])->assertRedirect();
        $this->postJson('/api/payment/ipn/sslcommerz', ['tran_id' => $op->ref, 'val_id' => 'V1', 'status' => 'VALID'])->assertOk()->assertJsonPath('status', 'completed');

        $this->assertSame('completed', $op->fresh()->status);
        $this->assertSame(1, CustomerPayment::where('customer_id', $this->customer->id)->count());
        $this->assertEquals(-300.0, LedgerService::balance($this->customer->id));
        $this->assertEquals(300.0, CollectionService::advanceCredit($this->customer->id));
        $this->assertSame('gateway', CustomerPayment::find($op->fresh()->customer_payment_id)->method);
    }

    public function test_manual_rocket_payment_review(): void
    {
        $this->setupGateway('rocket', ['mode' => 'manual', 'manual_number' => '01812345678', 'manual_account_type' => 'personal']);

        $this->portal('/customer-portal/pay/start', ['gateway' => 'rocket', 'amount' => 200, 'purpose' => 'bill'])->assertStatus(422); // no API checkout for Rocket
        $this->portal('/customer-portal/pay/manual', ['gateway' => 'rocket', 'amount' => 200, 'purpose' => 'bill', 'trx_id' => 'x', 'sender_number' => '01911111111'])->assertStatus(422);
        $this->portal('/customer-portal/pay/manual', ['gateway' => 'rocket', 'amount' => 200, 'purpose' => 'bill', 'trx_id' => 'RKT12345', 'sender_number' => '01911111111'])->assertOk();
        // the same TrxID can't be used twice
        $this->portal('/customer-portal/pay/manual', ['gateway' => 'rocket', 'amount' => 200, 'purpose' => 'bill', 'trx_id' => 'rkt12345', 'sender_number' => '01911111111'])->assertStatus(422);
        $this->portal('/customer-portal/pay/manual', ['gateway' => 'rocket', 'amount' => 150, 'purpose' => 'wallet', 'trx_id' => 'RKT99999', 'sender_number' => '01911111111'])->assertOk();

        $this->assertEquals(0.0, (float) CustomerPayment::where('customer_id', $this->customer->id)->sum('amount')); // nothing counted before review
        $list = $this->admin('/isp/get-online-payments', ['status' => 'pending_review'])->assertOk();
        $this->assertSame(2, $list->json('pending.count'));

        [$first, $second] = OnlinePayment::where('customer_id', $this->customer->id)->orderBy('id')->get()->all();
        $this->admin('/isp/online-payment-approve', ['id' => $first->id])->assertOk();
        $this->admin('/isp/online-payment-approve', ['id' => $first->id])->assertStatus(422); // already done
        $this->admin('/isp/online-payment-reject', ['id' => $second->id, 'reason' => 'TrxID not found in Rocket app'])->assertOk();

        $this->assertSame('completed', $first->fresh()->status);
        $this->assertSame('rejected', $second->fresh()->status);
        $this->assertSame('rocket', CustomerPayment::find($first->fresh()->customer_payment_id)->method);
        $this->assertEquals(300.0, LedgerService::balance($this->customer->id));
    }

    public function test_pages_render_and_are_guarded(): void
    {
        $this->setupGateway('rocket', ['mode' => 'manual', 'manual_number' => '01812345678']);
        $this->actingAs($this->admin)->withSession(['branch' => $this->branch])->get('/isp/payment-gateways')->assertOk();
        $this->actingAs($this->admin)->withSession(['branch' => $this->branch])->get('/isp/online-payments')->assertOk();

        // ask for the Inertia JSON page to check what the customer is sent
        $version = app(\App\Http\Middleware\HandleInertiaRequests::class)->version(\Illuminate\Http\Request::create('/'));
        $page = $this->actingAs($this->customer, 'customer')
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $version])
            ->get('/customer-portal/pay')->assertOk();
        $this->assertSame('CustomerPortal/Pay', $page->json('component'));
        $this->assertSame('rocket', $page->json('props.gateways.0.gateway'));
        $this->assertSame('01812345678', $page->json('props.gateways.0.manual_number'));
        $this->assertArrayNotHasKey('credentials', $page->json('props.gateways.0'));
        $this->assertEquals(500, $page->json('props.summary.due'));

        // a staff user without the permission can't change gateways
        $staff = User::where('role', '!=', 'Superadmin')->where('role', '!=', 'admin')->where('id', '!=', 1)->first();
        if ($staff) {
            $this->actingAs($staff)->withSession(['branch' => $this->branch])
                ->postJson('/isp/payment-gateway', ['gateway' => 'rocket', 'is_active' => false, 'mode' => 'manual', 'min_amount' => 10, 'max_amount' => 100])
                ->assertStatus(403);
        }
        // portal pages need a customer login
        $this->app['auth']->forgetGuards();
        $this->get('/customer-portal/pay')->assertRedirect();
    }

    public function test_nagad_checkout_with_rsa_keys(): void
    {
        // our merchant key pair, and a key pair standing in for Nagad
        $merchant = openssl_pkey_new(['private_key_bits' => 2048]);
        $nagad = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($merchant, $merchantPrivate);
        $merchantPublic = openssl_pkey_get_details($merchant)['key'];
        $nagadPublic = openssl_pkey_get_details($nagad)['key'];

        $this->setupGateway('nagad', ['mode' => 'api', 'credentials' => [
            'merchant_id' => '683002007104225', 'merchant_number' => '01711428036',
            // keys pasted without PEM headers, as the Nagad panel shows them
            'merchant_private_key' => preg_replace('/-----[^-]+-----|\s/', '', $merchantPrivate),
            'nagad_public_key' => $nagadPublic,
        ]]);

        Http::fake([
            '*/check-out/initialize/*' => function ($request) use ($nagad, $merchantPublic) {
                // Nagad can read what we encrypted for it and our signature checks out
                openssl_private_decrypt(base64_decode($request['sensitiveData']), $plain, $nagad);
                $data = json_decode($plain, true);
                $this->assertSame('683002007104225', $data['merchantId']);
                $this->assertSame(1, openssl_verify($plain, base64_decode($request['signature']), $merchantPublic, OPENSSL_ALGO_SHA256));
                openssl_public_encrypt(json_encode(['paymentReferenceId' => 'NGREF1', 'challenge' => 'CH1']), $enc, $merchantPublic);
                return Http::response(['sensitiveData' => base64_encode($enc), 'signature' => 'x']);
            },
            '*/check-out/complete/NGREF1' => function ($request) use ($nagad) {
                openssl_private_decrypt(base64_decode($request['sensitiveData']), $plain, $nagad);
                $order = json_decode($plain, true);
                $this->assertSame('300.00', $order['amount']);
                $this->assertSame('CH1', $order['challenge']);
                return Http::response(['status' => 'Success', 'callBackUrl' => 'https://sandbox.mynagad.com/pay/NGREF1']);
            },
            '*/verify/payment/NGREF1' => fn () => Http::response([
                'status' => 'Success', 'amount' => '300.00', 'orderId' => OnlinePayment::latest('id')->value('ref'), 'issuerPaymentRefNo' => 'NGTRX9',
            ]),
        ]);

        $this->portal('/customer-portal/pay/start', ['gateway' => 'nagad', 'amount' => 300, 'purpose' => 'bill'])
            ->assertOk()->assertJsonPath('redirect', 'https://sandbox.mynagad.com/pay/NGREF1');
        $op = OnlinePayment::latest('id')->first();
        $this->get("/api/payment/callback/nagad/{$op->ref}?merchant=683002007104225&order_id={$op->ref}&payment_ref_id=NGREF1&status=Success")->assertRedirect();

        $op->refresh();
        $this->assertSame('completed', $op->status);
        $this->assertSame('NGTRX9', $op->trx_id);
        $this->assertSame('nagad', CustomerPayment::find($op->customer_payment_id)->method);
        $this->assertEquals(200.0, LedgerService::balance($this->customer->id));
    }

    public function test_portal_connections_page_splits_active_and_inactive(): void
    {
        $version = app(\App\Http\Middleware\HandleInertiaRequests::class)->version(\Illuminate\Http\Request::create('/'));
        $page = $this->actingAs($this->customer, 'customer')
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $version])
            ->get('/customer-portal/connections')->assertOk();
        $this->assertSame('CustomerPortal/Connections', $page->json('component'));
        $this->assertSame($this->customer->code, $page->json('props.customer.code'));
        $this->assertEquals(500, $page->json('props.summary.balance'));
        $this->assertIsArray($page->json('props.connections'));

        // one running and one terminated connection, each billed; a payment is collected
        $this->admin('/isp/package', ['name' => 'T Portal 10', 'download_mbps' => 10, 'upload_mbps' => 10, 'price' => 600, 'billing_cycle' => 'monthly'])->assertOk();
        $packageId = DB::table('packages')->where('name', 'T Portal 10')->value('id');
        $areaId = $this->admin('/area', ['name' => 'T Portal Area'])->assertOk()->json('id'); // a connection needs an area
        foreach (['t_portal_a', 't_portal_b'] as $user) {
            $this->admin('/isp/connection', ['customer_id' => $this->customer->id, 'package_id' => $packageId, 'connection_type' => 'pppoe', 'area_id' => $areaId,
                'pppoe_username' => $user, 'activate_now' => true, 'activation_date' => now()->startOfMonth()->toDateString()])->assertOk();
        }
        \App\Services\Isp\BillingService::generateForBranch($this->branch->id, now());
        [$a, $b] = \App\Models\Connection::where('customer_id', $this->customer->id)->orderBy('id')->get()->all();
        \App\Services\Isp\ConnectionService::terminate($b, 'moved away');
        CollectionService::receive($this->customer, ['amount' => 500, 'method' => 'cash']);

        $props = $this->actingAs($this->customer, 'customer')
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $version])
            ->get('/customer-portal/connections')->assertOk()->json('props');
        $byId = collect($props['connections'])->keyBy('id');
        $this->assertSame('active', $byId[$a->id]['status']);
        $this->assertSame('terminated', $byId[$b->id]['status']);
        $this->assertEquals(600, $byId[$a->id]['billed']);
        $this->assertEquals(600, $byId[$b->id]['billed']);
        // 500 went to the oldest bill (the opening due), so both connection bills are still fully due
        $this->assertEquals(0, $byId[$a->id]['collected'] + $byId[$b->id]['collected']);
        $this->assertEquals(1200, $byId[$a->id]['due'] + $byId[$b->id]['due']);
        $this->assertCount(1, $props['payments']);
    }
}
