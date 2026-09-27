<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CompanyProfile;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\GatewayEvent;
use App\Models\OnlinePayment;
use App\Models\PaymentGateway;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// PayPal Checkout (Orders v2): approve on PayPal, capture here, webhooks verified by PayPal and
// never recorded twice.
class PaypalTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private Branch $branch;
    private Customer $customer;
    private int $bankId;
    // the faked PayPal order: status CREATED -> APPROVED -> COMPLETED (after capture)
    private array $order = ['status' => 'CREATED'];
    private string $captureStatus = 'COMPLETED';
    private string $captureValue = '50.00';
    private bool $webhookGenuine = true;
    private int $captures = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['isp.network_driver' => \App\Services\Network\NullNetworkDriver::class]);
        Http::preventStrayRequests();
        $this->admin = User::where('role', 'Superadmin')->firstOrFail();
        $this->branch = Branch::firstOrFail();
        CompanyProfile::query()->update(['currency_code' => 'USD']);
        clearCompanyCache();
        PaymentGateway::where('branch_id', $this->branch->id)->delete();
        $this->bankId = DB::table('banks')->insertGetId([
            'name' => 'PayPal balance', 'number' => 'pp', 'type' => 'bank', 'bank_name' => 'PayPal',
            'status' => 'a', 'branch_id' => $this->branch->id, 'created_at' => now(), 'ipAddress' => '127.0.0.1',
        ]);
        $this->admin('/customer', ['name' => 'PayPal Customer', 'phone' => '01799999333', 'previous_due' => 50])->assertOk();
        $this->customer = Customer::where('phone', '01799999333')->firstOrFail();
        $this->fakePaypal();
    }

    protected function tearDown(): void
    {
        CompanyProfile::query()->update(['currency_code' => 'BDT']);
        clearCompanyCache();
        parent::tearDown();
    }

    private function admin(string $uri, array $data = [])
    {
        return $this->actingAs($this->admin)->withSession(['branch' => $this->branch])->postJson($uri, $data);
    }

    private function orderJson(string $ref): array
    {
        $unit = ['reference_id' => $ref, 'custom_id' => $ref];
        if ($this->order['status'] === 'COMPLETED') {
            $unit['payments']['captures'][] = ['id' => 'CAP-1', 'status' => $this->captureStatus, 'amount' => ['currency_code' => 'USD', 'value' => $this->captureValue]];
        }
        return ['id' => 'ORDER-1', 'status' => $this->order['status'], 'purchase_units' => [$unit]];
    }

    private function fakePaypal(): void
    {
        Http::fake(function (HttpRequest $r) {
            $url = $r->url();
            $ref = OnlinePayment::where('gateway', 'paypal')->latest('id')->value('ref') ?? '';
            if (str_ends_with($url, '/v1/oauth2/token')) {
                return Http::response(['access_token' => 'A21-token', 'expires_in' => 32400]);
            }
            if (str_ends_with($url, '/v1/notifications/verify-webhook-signature')) {
                return Http::response(['verification_status' => $this->webhookGenuine ? 'SUCCESS' : 'FAILURE']);
            }
            if ($r->method() === 'POST' && str_ends_with($url, '/v2/checkout/orders')) {
                return Http::response(['id' => 'ORDER-1', 'status' => 'PAYER_ACTION_REQUIRED', 'links' => [['rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER-1']]]);
            }
            if (str_ends_with($url, '/capture')) {
                if ($this->order['status'] !== 'APPROVED') {
                    return Http::response(['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'ORDER_ALREADY_CAPTURED']]], 422);
                }
                $this->captures++;
                $this->order['status'] = 'COMPLETED';
                return Http::response($this->orderJson($ref), 201);
            }
            if (str_contains($url, '/v2/checkout/orders/ORDER-1')) {
                return Http::response($this->orderJson($ref));
            }
            return Http::response([], 404);
        });
    }

    private function setupPaypal(): PaymentGateway
    {
        $this->admin('/isp/payment-gateway', [
            'gateway' => 'paypal', 'is_active' => true, 'mode' => 'api', 'sandbox' => true, 'bank_id' => $this->bankId,
            'min_amount' => 1, 'max_amount' => 5000, 'credentials' => ['client_id' => 'CLIENT', 'client_secret' => 'SECRET', 'webhook_id' => 'WH-1'],
        ])->assertOk();
        return PaymentGateway::where('branch_id', $this->branch->id)->where('gateway', 'paypal')->firstOrFail();
    }

    private function start(float $amount = 50): OnlinePayment
    {
        $res = $this->actingAs($this->customer, 'customer')->postJson('/customer-portal/pay/start', ['gateway' => 'paypal', 'amount' => $amount, 'purpose' => 'bill'])->assertOk();
        $this->assertSame('https://www.sandbox.paypal.com/checkoutnow?token=ORDER-1', $res->json('redirect'));
        return OnlinePayment::where('customer_id', $this->customer->id)->latest('id')->firstOrFail();
    }

    private function webhook(PaymentGateway $gateway, string $id, string $type, array $resource)
    {
        return $this->call('POST', "/api/payment/webhook/paypal/{$gateway->id}", [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_PAYPAL_TRANSMISSION_ID' => 't-1', 'HTTP_PAYPAL_TRANSMISSION_SIG' => 'sig',
            'HTTP_PAYPAL_AUTH_ALGO' => 'SHA256withRSA', 'HTTP_PAYPAL_CERT_URL' => 'https://api.paypal.com/cert', 'HTTP_PAYPAL_TRANSMISSION_TIME' => now()->toIso8601String(),
        ], json_encode(['id' => $id, 'event_type' => $type, 'resource' => $resource]));
    }

    public function test_approve_and_return_captures_once(): void
    {
        $this->setupPaypal();
        $op = $this->start(50);
        $this->assertSame('ORDER-1', $op->gateway_payment_id);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/v2/checkout/orders') && $r['purchase_units'][0]['amount'] === ['currency_code' => 'USD', 'value' => '50.00']
            && $r['purchase_units'][0]['custom_id'] === $op->ref && $r->hasHeader('PayPal-Request-Id', "order-{$op->ref}"));

        // back before approving: nothing happens
        $this->get("/api/payment/callback/paypal/{$op->ref}?token=ORDER-1");
        $this->assertSame('initiated', $op->fresh()->status);

        $this->order['status'] = 'APPROVED';
        $this->get("/api/payment/callback/paypal/{$op->ref}?token=ORDER-1&PayerID=P1")->assertRedirect("/customer-portal/pay?ref={$op->ref}");
        $this->get("/api/payment/callback/paypal/{$op->ref}?token=ORDER-1&PayerID=P1"); // refresh
        $op->refresh();
        $this->assertSame(['completed', 'CAP-1'], [$op->status, $op->trx_id]);
        $this->assertSame(1, $this->captures);
        $this->assertSame(1, CustomerPayment::where('reference', $op->ref)->count());
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/capture') && $r->hasHeader('PayPal-Request-Id', "capture-{$op->ref}"));
    }

    public function test_webhook_finishes_a_payment_whose_customer_never_came_back(): void
    {
        $gateway = $this->setupPaypal();
        $op = $this->start(50);
        $this->order['status'] = 'APPROVED';

        $event = ['id' => 'ORDER-1', 'status' => 'APPROVED', 'purchase_units' => [['reference_id' => $op->ref, 'custom_id' => $op->ref]]];
        $this->webhook($gateway, 'WH-EVT-1', 'CHECKOUT.ORDER.APPROVED', $event)->assertOk();
        $this->webhook($gateway, 'WH-EVT-1', 'CHECKOUT.ORDER.APPROVED', $event)->assertOk(); // resent
        // the capture event for the same money: found by its order id, nothing recorded again
        $this->webhook($gateway, 'WH-EVT-2', 'PAYMENT.CAPTURE.COMPLETED', ['id' => 'CAP-1', 'status' => 'COMPLETED', 'supplementary_data' => ['related_ids' => ['order_id' => 'ORDER-1']]])->assertOk();

        $this->assertSame('completed', $op->fresh()->status);
        $this->assertSame(1, $this->captures);
        $this->assertSame(1, CustomerPayment::where('reference', $op->ref)->count());
        $this->assertSame(2, GatewayEvent::where('gateway', 'paypal')->whereNotNull('processed_at')->count());
        $this->assertSame(1, GatewayEvent::where('event_id', 'WH-EVT-1')->value('attempts'));
    }

    public function test_webhooks_must_pass_paypal_verification(): void
    {
        $gateway = $this->setupPaypal();
        $op = $this->start(50);
        $this->order['status'] = 'APPROVED';
        $this->webhookGenuine = false;
        $this->webhook($gateway, 'WH-EVT-3', 'CHECKOUT.ORDER.APPROVED', ['id' => 'ORDER-1', 'purchase_units' => [['custom_id' => $op->ref]]])->assertStatus(400);
        $this->assertSame('initiated', $op->fresh()->status);
        $this->assertSame(0, $this->captures);
        $this->postJson('/api/payment/webhook/paypal/999999', ['id' => 'x', 'event_type' => 'y'])->assertStatus(404);
    }

    public function test_cancel_pending_capture_and_amount_mismatch(): void
    {
        $gateway = $this->setupPaypal();
        $op = $this->start(50);
        $this->get("/api/payment/callback/paypal/{$op->ref}?token=ORDER-1&cancelled=1");
        $this->assertSame('cancelled', $op->fresh()->status);

        // eCheck: captured but held; the capture webhook completes it later
        $this->order = ['status' => 'APPROVED'];
        $this->captureStatus = 'PENDING';
        $op2 = $this->start(50);
        $this->get("/api/payment/callback/paypal/{$op2->ref}?token=ORDER-1&PayerID=P1");
        $this->assertSame('initiated', $op2->fresh()->status);
        $this->captureStatus = 'COMPLETED';
        $this->webhook($gateway, 'WH-EVT-4', 'PAYMENT.CAPTURE.COMPLETED', ['id' => 'CAP-1', 'custom_id' => $op2->ref])->assertOk();
        $this->assertSame('completed', $op2->fresh()->status);

        // captured a different amount: to review
        $this->order = ['status' => 'APPROVED'];
        $this->captureValue = '40.00';
        $op3 = $this->start(50);
        $this->get("/api/payment/callback/paypal/{$op3->ref}?token=ORDER-1&PayerID=P1");
        $this->assertSame('pending_review', $op3->fresh()->status);
    }

    public function test_currency_support(): void
    {
        CompanyProfile::query()->update(['currency_code' => 'BDT']);
        clearCompanyCache();
        $this->admin('/isp/payment-gateway', [
            'gateway' => 'paypal', 'is_active' => true, 'mode' => 'api', 'sandbox' => true, 'bank_id' => $this->bankId,
            'min_amount' => 1, 'max_amount' => 5000, 'credentials' => ['client_id' => 'C', 'client_secret' => 'S', 'webhook_id' => 'W'],
        ])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'the company bills in BDT'));
        $this->assertTrue(PaymentGateway::supportsCurrency('paypal', 'JPY'));
    }
}
