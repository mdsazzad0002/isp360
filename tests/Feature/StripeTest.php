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
use App\Services\Isp\LedgerService;
use App\Services\Isp\Payments\StripeDriver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// Stripe Checkout: hosted payment, result read back from Stripe's API, signed webhooks, and a
// repeated webhook never recording the money twice.
class StripeTest extends TestCase
{
    use DatabaseTransactions;

    private const WHSEC = 'whsec_test_secret';

    private User $admin;
    private Branch $branch;
    private Customer $customer;
    private int $bankId;
    private ?string $lastRef = null; // the newest checkout, for the faked Stripe API

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
            'name' => 'Stripe balance', 'number' => 'acct_1', 'type' => 'bank', 'bank_name' => 'Stripe',
            'status' => 'a', 'branch_id' => $this->branch->id, 'created_at' => now(), 'ipAddress' => '127.0.0.1',
        ]);
        $this->admin('/customer', ['name' => 'Stripe Customer', 'phone' => '01799999222', 'email' => 'payer@example.com', 'previous_due' => 50])->assertOk();
        $this->customer = Customer::where('phone', '01799999222')->firstOrFail();
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

    private function setupStripe(array $extra = []): PaymentGateway
    {
        $this->admin('/isp/payment-gateway', $extra + [
            'gateway' => 'stripe', 'is_active' => true, 'mode' => 'api', 'sandbox' => true, 'bank_id' => $this->bankId,
            'min_amount' => 1, 'max_amount' => 5000, 'credentials' => ['secret_key' => 'sk_test_123', 'webhook_secret' => self::WHSEC],
        ])->assertOk();
        return PaymentGateway::where('branch_id', $this->branch->id)->where('gateway', 'stripe')->firstOrFail();
    }

    private function stripeSession(string $ref, string $status = 'complete', string $paid = 'paid', int $amount = 5000, string $currency = 'usd'): array
    {
        return ['id' => 'cs_test_1', 'object' => 'checkout.session', 'status' => $status, 'payment_status' => $paid, 'client_reference_id' => $ref,
            'amount_total' => $amount, 'currency' => $currency, 'payment_intent' => 'pi_123', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1'];
    }

    // Stripe's API: session create, then reads return $session (a closure so a test can change it).
    private function fakeStripe(callable $session): void
    {
        Http::fake(function (HttpRequest $request) use ($session) {
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/checkout/sessions')) {
                return Http::response(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1', 'status' => 'open', 'payment_status' => 'unpaid']);
            }
            if (str_ends_with($request->url(), '/expire')) {
                $s = $session();
                return $s['status'] === 'open' ? Http::response(['status' => 'expired'] + $s) : Http::response(['error' => ['message' => 'Session is complete']], 400);
            }
            if (str_contains($request->url(), '/checkout/sessions/cs_test_1')) {
                return Http::response($session());
            }
            return Http::response([], 404);
        });
    }

    private function start(float $amount = 50): OnlinePayment
    {
        $res = $this->actingAs($this->customer, 'customer')->postJson('/customer-portal/pay/start', ['gateway' => 'stripe', 'amount' => $amount, 'purpose' => 'bill'])->assertOk();
        $this->assertSame('https://checkout.stripe.com/c/pay/cs_test_1', $res->json('redirect'));
        $op = OnlinePayment::where('customer_id', $this->customer->id)->latest('id')->firstOrFail();
        $this->lastRef = $op->ref;
        return $op;
    }

    private function ref(): string
    {
        return $this->lastRef ??= '';
    }

    private function webhook(PaymentGateway $gateway, array $event, ?string $secret = self::WHSEC, ?int $time = null)
    {
        $body = json_encode($event);
        $t = $time ?? now()->getTimestamp();
        $signature = 't=' . $t . ',v1=' . hash_hmac('sha256', "$t.$body", $secret);
        return $this->call('POST', "/api/payment/webhook/stripe/{$gateway->id}", [], [], [], ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], $body);
    }

    private function event(string $id, string $type, array $object): array
    {
        return ['id' => $id, 'object' => 'event', 'type' => $type, 'data' => ['object' => $object]];
    }

    public function test_setup_checks_keys_and_shows_the_webhook_url(): void
    {
        $this->admin('/isp/payment-gateway', ['gateway' => 'stripe', 'is_active' => true, 'mode' => 'api', 'sandbox' => true, 'bank_id' => $this->bankId,
            'min_amount' => 1, 'max_amount' => 5000, 'credentials' => ['secret_key' => 'sk_live_123', 'webhook_secret' => self::WHSEC]])->assertStatus(422);
        $gateway = $this->setupStripe();

        $json = $this->admin('/isp/get-payment-gateways')->assertOk()->json();
        $stripe = collect($json['gateways'])->firstWhere('gateway', 'stripe');
        $this->assertSame(url("/api/payment/webhook/stripe/{$gateway->id}"), $stripe['webhook_url']);
        $this->assertTrue($stripe['currency_ok']);
        $this->assertStringNotContainsString('sk_test_123', json_encode($json));
        $this->assertStringNotContainsString(self::WHSEC, json_encode($json));
    }

    public function test_checkout_pays_the_bill_from_the_return_url(): void
    {
        $this->setupStripe();
        $paid = false;
        $this->fakeStripe(function () use (&$paid) {
            return $paid ? $this->stripeSession($this->ref()) : $this->stripeSession($this->ref(), 'open', 'unpaid');
        });
        $op = $this->start(50);
        $this->assertSame('cs_test_1', $op->gateway_payment_id);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/checkout/sessions') && $r['line_items[0][price_data][unit_amount]'] == 5000
            && $r['line_items[0][price_data][currency]'] === 'usd' && $r['client_reference_id'] === $op->ref && $r->hasHeader('Idempotency-Key', "checkout-{$op->ref}"));

        // back from Stripe before paying: nothing recorded, still open
        $this->get("/api/payment/callback/stripe/{$op->ref}?session_id=cs_test_1")->assertRedirect();
        $this->assertSame('initiated', $op->fresh()->status);

        $paid = true;
        $this->get("/api/payment/callback/stripe/{$op->ref}?session_id=cs_test_1")->assertRedirect("/customer-portal/pay?ref={$op->ref}");
        $this->get("/api/payment/callback/stripe/{$op->ref}?session_id=cs_test_1"); // refresh
        $op->refresh();
        $this->assertSame(['completed', 'pi_123'], [$op->status, $op->trx_id]);
        $this->assertSame(1, CustomerPayment::where('customer_id', $this->customer->id)->where('reference', $op->ref)->count());
        $this->assertSame('card', CustomerPayment::find($op->customer_payment_id)->method);
        $this->assertEquals(0, LedgerService::balance($this->customer->id));

        // a return URL carrying another session id is refused
        $forged = $this->start(10);
        $this->get("/api/payment/callback/stripe/{$forged->ref}?session_id=cs_someone_else");
        $this->assertSame(['failed', 'Stripe session mismatch'], [$forged->fresh()->status, $forged->fresh()->failure_reason]);
    }

    public function test_a_repeated_webhook_records_the_money_once(): void
    {
        $gateway = $this->setupStripe();
        $this->fakeStripe(fn () => $this->stripeSession($this->ref()));
        $op = $this->start(50);

        $event = $this->event('evt_1', 'checkout.session.completed', $this->stripeSession($op->ref));
        $this->webhook($gateway, $event)->assertOk();
        $this->webhook($gateway, $event)->assertOk(); // Stripe resends
        $this->assertSame('completed', $op->fresh()->status);
        $this->assertSame(1, CustomerPayment::where('reference', $op->ref)->count());
        $row = GatewayEvent::where('gateway', 'stripe')->where('event_id', 'evt_1')->firstOrFail();
        $this->assertNotNull($row->processed_at);
        $this->assertSame([$op->id, 1], [$row->online_payment_id, $row->attempts]);

        // the customer's return after the webhook changes nothing
        $this->get("/api/payment/callback/stripe/{$op->ref}?session_id=cs_test_1");
        $this->assertSame(1, CustomerPayment::where('reference', $op->ref)->count());
    }

    public function test_webhooks_must_be_signed_and_fresh(): void
    {
        $gateway = $this->setupStripe();
        $state = ['open', 'unpaid']; // what Stripe's API really says
        $this->fakeStripe(function () use (&$state) {
            return $this->stripeSession($this->ref(), ...$state);
        });
        $op = $this->start(50);
        $event = $this->event('evt_2', 'checkout.session.completed', $this->stripeSession($op->ref));

        $this->webhook($gateway, $event, 'whsec_wrong')->assertStatus(400);
        $this->webhook($gateway, $event, self::WHSEC, now()->getTimestamp() - 600)->assertStatus(400); // replayed later
        $this->postJson("/api/payment/webhook/stripe/{$gateway->id}", $event)->assertStatus(400); // no signature
        $this->postJson('/api/payment/webhook/stripe/999999', $event)->assertStatus(404);
        $this->assertSame('initiated', $op->fresh()->status);
        $this->assertSame(0, GatewayEvent::where('event_id', 'evt_2')->count());

        // a body claiming "paid" is not trusted: Stripe's API says it isn't
        $this->webhook($gateway, $event)->assertStatus(500); // Stripe will retry
        $this->assertSame('initiated', $op->fresh()->status);
        $this->assertNull(GatewayEvent::where('event_id', 'evt_2')->value('processed_at'));

        // the retry, once Stripe has the money, completes it
        $state = ['complete', 'paid'];
        $this->webhook($gateway, $event)->assertOk();
        $this->assertSame('completed', $op->fresh()->status);
        $this->assertSame(2, GatewayEvent::where('event_id', 'evt_2')->value('attempts'));
    }

    public function test_async_payment_and_expiry(): void
    {
        $gateway = $this->setupStripe();
        $state = ['complete', 'unpaid'];
        $this->fakeStripe(function () use (&$state) {
            return $this->stripeSession($this->ref(), ...$state);
        });
        $op = $this->start(50);

        // a bank debit: checkout finished, money not there yet
        $this->webhook($gateway, $this->event('evt_3', 'checkout.session.completed', $this->stripeSession($op->ref, 'complete', 'unpaid')))->assertOk();
        $this->assertSame('initiated', $op->fresh()->status);
        $state = ['complete', 'paid'];
        $this->webhook($gateway, $this->event('evt_4', 'checkout.session.async_payment_succeeded', $this->stripeSession($op->ref)))->assertOk();
        $this->assertSame('completed', $op->fresh()->status);

        // another attempt that expires
        $state = ['expired', 'unpaid'];
        $op2 = $this->start(20);
        $this->webhook($gateway, $this->event('evt_5', 'checkout.session.expired', $this->stripeSession($op2->ref, 'expired', 'unpaid')))->assertOk();
        $this->assertSame('failed', $op2->fresh()->status);
    }

    public function test_cancel_closes_the_session_and_amount_or_currency_mismatch_goes_to_review(): void
    {
        $this->setupStripe();
        $session = fn () => $this->stripeSession($this->ref(), 'open', 'unpaid');
        $this->fakeStripe(function () use (&$session) {
            return $session();
        });
        $op = $this->start(50);
        $this->get("/api/payment/callback/stripe/{$op->ref}?cancelled=1");
        $this->assertSame('cancelled', $op->fresh()->status);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/cs_test_1/expire'));

        // paid a different amount: an admin decides
        $session = fn () => $this->stripeSession($this->ref(), 'complete', 'paid', 4000);
        $op2 = $this->start(50);
        $this->get("/api/payment/callback/stripe/{$op2->ref}?session_id=cs_test_1");
        $this->assertSame('pending_review', $op2->fresh()->status);

        // charged in another currency: refused
        $session = fn () => $this->stripeSession($this->ref(), 'complete', 'paid', 5000, 'eur');
        $op3 = $this->start(50);
        $this->get("/api/payment/callback/stripe/{$op3->ref}?session_id=cs_test_1");
        $this->assertSame('failed', $op3->fresh()->status);
        $this->assertSame(0, CustomerPayment::whereIn('reference', [$op->ref, $op2->ref, $op3->ref])->count());
    }

    public function test_minor_units_follow_stripe_rules(): void
    {
        $this->assertSame(5000, StripeDriver::toMinor(50, 'USD'));
        $this->assertSame(1500, StripeDriver::toMinor(1500, 'JPY'));
        $this->assertSame(1500000, StripeDriver::toMinor(15000, 'IDR')); // IDR is 2-decimal at Stripe
        $this->assertSame(12340, StripeDriver::toMinor(12.34, 'KWD'));
        $this->assertSame(12.34, StripeDriver::fromMinor(12340, 'KWD'));
        $this->expectException(\RuntimeException::class);
        StripeDriver::toMinor(12.345, 'KWD');
    }
}
