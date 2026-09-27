<?php

namespace Tests\Feature;

use App\Jobs\SendChannelMessage;
use App\Jobs\SendSms;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\MessagingChannel;
use App\Models\NotificationLog;
use App\Models\SmsGateway;
use App\Models\User;
use App\Services\Isp\IspNotifier;
use App\Services\Isp\IspSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

// Global SMS providers, e-mail and WhatsApp channels, customer channels, renewal reminder (roadmap 4.8).
class NotificationChannelsTest extends TestCase
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
        SmsGateway::where('branch_id', $this->branch->id)->delete();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        IspSettings::flush();
        parent::tearDown();
    }

    private function api(string $uri, array $data = [])
    {
        return $this->actingAs($this->admin)->withSession(['branch' => $this->branch])->postJson($uri, $data);
    }

    private function gateway(string $type, string $key, string $from, ?string $url = null): void
    {
        $this->api('/sms-gateway', ['name' => $type, 'provider_type' => $type, 'api_key' => $key, 'sender_id' => $from, 'url_template' => $url, 'is_active' => true])->assertOk();
    }

    public function test_international_sms_providers_send_in_e164(): void
    {
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201), 'rest.nexmo.com/*' => Http::response(['messages' => [['status' => '0']]]), '*.api.infobip.com/*' => Http::response(['messages' => []])]);
        $this->api('/sms-gateway', ['name' => 'x', 'provider_type' => 'infobip', 'api_key' => 'k', 'sender_id' => 'ISP'])->assertStatus(422); // base URL needed

        $this->gateway('twilio', 'AC123:tok', '+15005550006');
        $this->assertTrue(sendTransactionalSms($this->branch->id, null, '01712345678', 'Hello'));
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'Accounts/AC123/Messages.json') && $r['To'] === '+8801712345678' && $r['From'] === '+15005550006' && $r->hasHeader('Authorization', 'Basic ' . base64_encode('AC123:tok')));

        SmsGateway::where('branch_id', $this->branch->id)->delete();
        $this->gateway('vonage', 'key:sec', 'ISP');
        $this->assertTrue(sendTransactionalSms($this->branch->id, null, '01712345678', 'বিল'));
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'nexmo') && $r['to'] === '8801712345678' && $r['type'] === 'unicode');

        SmsGateway::where('branch_id', $this->branch->id)->delete();
        $this->gateway('infobip', 'APPKEY', 'ISP', 'https://abc.api.infobip.com');
        $this->assertTrue(sendTransactionalSms($this->branch->id, null, '01712345678', 'Hi'));
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://abc.api.infobip.com/sms/2/text/advanced' && $r['messages'][0]['destinations'][0]['to'] === '8801712345678' && $r->hasHeader('Authorization', 'App APPKEY'));
    }

    public function test_messages_go_out_on_the_customers_channels(): void
    {
        Queue::fake();
        IspSettings::save($this->branch->id, ['sms_payment' => true]);
        $this->api('/isp/messaging', ['channel' => 'email', 'is_active' => true, 'values' => ['from_address' => 'billing@isp.test', 'from_name' => 'ISP']])->assertOk();
        $this->api('/isp/messaging', ['channel' => 'whatsapp', 'is_active' => true, 'values' => ['phone_number_id' => '123']])->assertStatus(422); // token + template needed
        $this->api('/isp/messaging', ['channel' => 'whatsapp', 'is_active' => true, 'values' => ['phone_number_id' => '123', 'access_token' => 'EAAT', 'template_name' => 'isp_notice', 'template_language' => 'en']])->assertOk();
        $this->assertStringNotContainsString('EAAT', json_encode($this->api('/isp/get-messaging')->json()));

        $this->api('/customer', ['name' => 'All Channels', 'phone' => '01712345681', 'email' => 'all@example.test', 'notify_channels' => 'email,whatsapp'])->assertOk();
        $customer = Customer::where('phone', '01712345681')->firstOrFail();
        IspNotifier::send($this->branch->id, $customer, 'payment', ['amount' => '10', 'receipt' => 'R1']);

        Queue::assertNotPushed(SendSms::class); // SMS not ticked for this customer
        Queue::assertPushed(SendChannelMessage::class, fn ($j) => $j->channel === 'email' && $j->recipient === 'all@example.test' && str_contains($j->subject, 'Payment received'));
        Queue::assertPushed(SendChannelMessage::class, fn ($j) => $j->channel === 'whatsapp' && $j->recipient === '01712345681');
        $this->api('/customer', ['name' => 'Bad', 'phone' => '01712345682', 'notify_channels' => 'sms,fax'])->assertStatus(422);
    }

    public function test_email_and_whatsapp_delivery_is_logged(): void
    {
        MessagingChannel::create(['branch_id' => $this->branch->id, 'channel' => 'email', 'is_active' => true, 'credentials' => ['from_address' => 'billing@isp.test']]);
        MessagingChannel::create(['branch_id' => $this->branch->id, 'channel' => 'whatsapp', 'is_active' => true, 'credentials' => ['phone_number_id' => '555', 'access_token' => 'EAAT', 'template_name' => 'isp_notice']]);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);

        (new SendChannelMessage($this->branch->id, 'email', 'a@example.test', 'Body text', 'Subject'))->handle();
        (new SendChannelMessage($this->branch->id, 'whatsapp', '01712345678', 'Body text'))->handle();

        $this->assertSame(1, count(app('mailer')->getSymfonyTransport()->messages()));
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/555/messages') && $r['to'] === '8801712345678' && $r['template']['name'] === 'isp_notice'
            && $r['template']['components'][0]['parameters'][0]['text'] === 'Body text');
        $this->assertSame(2, NotificationLog::where('branch_id', $this->branch->id)->where('is_success', true)->count());
    }

    public function test_renewal_reminder_goes_once_before_expiry(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-09-15 10:00:00');
        IspSettings::save($this->branch->id, ['reminder_days' => 3]);
        $areaId = $this->api('/area', ['name' => 'NC Area'])->json('id');
        $this->api('/isp/package', ['name' => 'NC 5', 'download_mbps' => 5, 'upload_mbps' => 2, 'price' => 500, 'billing_cycle' => 'monthly'])->assertOk();
        $this->api('/customer', ['name' => 'Reminder', 'phone' => '01712345683', 'area_id' => $areaId])->assertOk();
        $customer = Customer::where('phone', '01712345683')->firstOrFail();
        $this->api('/isp/connection', ['customer_id' => $customer->id, 'package_id' => \App\Models\Package::where('name', 'NC 5')->value('id'), 'connection_type' => 'pppoe', 'pppoe_username' => 'nc_user', 'activate_now' => true])->assertOk();
        $this->api('/isp/payment', ['customer_id' => $customer->id, 'amount' => 500, 'method' => 'cash', 'payment_date' => '2026-09-15'])->assertOk(); // until 15 Oct 10:00

        Carbon::setTestNow('2026-10-12 09:59:00');
        $this->artisan('isp:process-overdue')->assertSuccessful();
        Queue::assertNotPushed(SendSms::class, fn ($j) => $j->purpose === 'isp_reminder');
        Carbon::setTestNow('2026-10-12 10:00:00');
        $this->artisan('isp:process-overdue')->assertSuccessful();
        $this->artisan('isp:process-overdue')->assertSuccessful();
        Queue::assertPushed(SendSms::class, fn ($j) => $j->purpose === 'isp_reminder' && str_contains($j->message, '15 Oct 2026'));
        $this->assertSame(1, collect(Queue::pushed(SendSms::class))->where('purpose', 'isp_reminder')->count());
    }
}
