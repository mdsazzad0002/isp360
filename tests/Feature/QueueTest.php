<?php

namespace Tests\Feature;

use App\Jobs\SendSms;
use App\Jobs\SendSmsBatch;
use App\Jobs\SyncConnectionToNetwork;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\SmsGateway;
use App\Models\SmsLog;
use App\Models\User;
use App\Services\Isp\IspNotifier;
use App\Services\Isp\IspSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

// SMS and router pushes run as queued jobs ("sms", "network"), and the background jobs page.
class QueueTest extends TestCase
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
        $this->areaId = $this->api('/area', ['name' => 'Queue Area'])->json('id');
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

    private function customer(?string $phone = null): Customer
    {
        $phone ??= '0178888' . str_pad((string) (1000 + ++$this->phone), 4, '0', STR_PAD_LEFT);
        $this->api('/customer', ['name' => "Queue Customer {$this->phone}", 'phone' => $phone, 'area_id' => $this->areaId])->assertOk();
        return Customer::where('phone', $phone)->firstOrFail();
    }

    private function gateway(): SmsGateway
    {
        $gateway = new SmsGateway();
        $gateway->forceFill([
            'name' => 'Queue SMS', 'provider_type' => 'custom', 'method' => 'GET', 'url_template' => 'https://sms.example.test/send?to={number}&text={message}',
            'is_active' => true, 'is_default' => true, 'branch_id' => $this->branch->id, 'ipAddress' => '127.0.0.1',
        ])->save();
        return $gateway;
    }

    public function test_billing_sms_is_queued_after_commit(): void
    {
        Queue::fake();
        IspSettings::save($this->branch->id, ['sms_invoice' => true]);
        $customer = $this->customer();

        IspNotifier::send($this->branch->id, $customer, 'invoice', ['invoice' => 'INV-1', 'amount' => '500.00', 'due_date' => '2026-10-01']);

        // sent only once the billing transaction has committed
        Queue::assertPushedOn('sms', SendSms::class, fn (SendSms $job) => $job->phone === $customer->phone && $job->afterCommit
            && $job->customerId === $customer->id && $job->purpose === 'isp_invoice' && str_contains($job->message, 'INV-1'));

        // an event switched off sends nothing
        IspSettings::save($this->branch->id, ['sms_invoice' => false]);
        IspNotifier::send($this->branch->id, $customer, 'invoice');
        Queue::assertPushed(SendSms::class, 1);
    }

    public function test_the_sms_job_sends_through_the_gateway_and_logs(): void
    {
        Http::fake(['sms.example.test/*' => Http::response('OK')]);
        $this->gateway();
        $customer = $this->customer();

        (new SendSms($this->branch->id, $customer->phone, 'Hello', $customer->id, 'isp_payment', $this->admin->id, '10.0.0.5'))->handle();

        Http::assertSentCount(1);
        $log = SmsLog::where('customer_id', $customer->id)->latest('id')->firstOrFail();
        $this->assertTrue((bool) $log->is_success);
        $this->assertSame(['isp_payment', '10.0.0.5', 'Hello'], [$log->purpose, $log->ipAddress, $log->message]);
        $this->assertSame(1, (new SendSms($this->branch->id, '1', 'x'))->tries); // never retried: no double SMS
    }

    public function test_promotions_are_queued_in_batches_with_a_worker(): void
    {
        $this->gateway();
        $customers = collect(range(1, 3))->map(fn () => $this->customer());

        config(['queue.default' => 'database']);
        Queue::fake();
        $res = $this->api('/send-sms-promotion', ['customerIds' => $customers->pluck('id')->all(), 'message' => 'Eid offer'])->assertOk();
        $this->assertSame(3, $res->json('queued'));
        Queue::assertPushedOn('sms', SendSmsBatch::class, fn (SendSmsBatch $job) => count($job->customerIds) === 3 && $job->message === 'Eid offer');
    }

    public function test_promotions_are_sent_and_counted_without_a_worker(): void
    {
        Http::fake(['sms.example.test/*' => Http::response('OK')]);
        $this->gateway();
        $customers = collect(range(1, 3))->map(fn () => $this->customer());

        $res = $this->api('/send-sms-promotion', ['customerIds' => $customers->pluck('id')->all(), 'message' => 'Eid offer'])->assertOk();
        $this->assertSame(3, $res->json('sent'));
        Http::assertSentCount(1); // one gateway call for the whole batch
        $this->assertSame(3, SmsLog::whereIn('customer_id', $customers->pluck('id'))->where('purpose', 'promotional')->where('is_success', true)->count());
    }

    public function test_router_pushes_run_on_the_network_queue_once_per_connection(): void
    {
        Queue::fake();
        SyncConnectionToNetwork::dispatch(987654);
        SyncConnectionToNetwork::dispatch(987654); // still waiting: the later push would send the same state
        SyncConnectionToNetwork::dispatch(987655);

        Queue::assertPushedOn('network', SyncConnectionToNetwork::class);
        Queue::assertPushed(SyncConnectionToNetwork::class, 2);
    }

    public function test_the_background_jobs_page_lists_and_retries_failed_jobs(): void
    {
        // a real job payload, as a worker would have recorded it
        Queue::connection('database')->push(new SendSms($this->branch->id, '01700000000', 'Hi'));
        $job = DB::table('jobs')->latest('id')->first();
        DB::table('jobs')->where('id', $job->id)->delete();
        $uuid = json_decode($job->payload, true)['uuid'];
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid, 'connection' => 'database', 'queue' => 'sms', 'payload' => $job->payload,
            'exception' => "RuntimeException: gateway down\n#0 trace", 'failed_at' => now(),
        ]);

        $res = $this->api('/isp/get-queue')->assertOk();
        $this->assertSame('sync', $res->json('driver'));
        $this->assertArrayHasKey('network', $res->json('waiting'));
        $row = collect($res->json('failed'))->firstWhere('uuid', $uuid);
        $this->assertSame(['SendSms', 'sms', 'RuntimeException: gateway down'], [$row['job'], $row['queue'], $row['error']]);

        $this->api('/isp/queue/retry', ['uuid' => $uuid])->assertOk();
        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $uuid]);
        $this->assertSame(1, DB::table('jobs')->where('queue', 'sms')->where('payload', 'like', "%{$uuid}%")->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'queue.retried']);

        // only users with the access
        $collector = User::forceCreate([
            'code' => 'U-QCOL', 'name' => 'Collector', 'username' => 'q_collector', 'email' => 'q_collector@example.test', 'phone' => '01700000009',
            'role' => 'Collector', 'password' => bcrypt('x'), 'status' => 'a', 'ipAddress' => '127.0.0.1', 'branch_id' => $this->branch->id,
        ]);
        $this->actingAs($collector)->withSession(['branch' => $this->branch])->postJson('/isp/get-queue')->assertStatus(403);
        $this->assertFalse(\Illuminate\Support\Facades\Gate::forUser($collector)->allows('viewHorizon'));
        $this->assertTrue(\Illuminate\Support\Facades\Gate::forUser($this->admin)->allows('viewHorizon'));
    }

    public function test_scheduler_indexes_exist(): void
    {
        $indexes = collect(DB::select("SHOW INDEX FROM connections"))->pluck('Key_name');
        $this->assertContains('connections_branch_status_expire_index', $indexes);
        $indexes = collect(DB::select("SHOW INDEX FROM invoices"))->pluck('Key_name');
        $this->assertContains('invoices_branch_status_due_date_index', $indexes);
    }
}
