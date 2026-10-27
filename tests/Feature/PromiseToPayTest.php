<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Connection;
use App\Models\Customer;
use App\Models\Package;
use App\Models\PaymentPromise;
use App\Models\User;
use App\Services\Isp\IspSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

// Promise to pay: holds the suspension, brings a suspended line back, keeps or breaks by itself.
class PromiseToPayTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private Branch $branch;
    private int $areaId;
    private Package $package;
    private int $phone = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['isp.network_driver' => \App\Services\Network\NullNetworkDriver::class]);
        $this->admin = User::where('role', 'Superadmin')->firstOrFail();
        $this->branch = Branch::firstOrFail();
        Carbon::setTestNow('2026-09-15 10:00:00');
        IspSettings::flush();
        IspSettings::save($this->branch->id, ['auto_install_work_order' => false]);
        $this->areaId = $this->api('/area', ['name' => 'PTP Area'])->json('id');
        $this->api('/isp/package', ['name' => 'PTP 1000', 'download_mbps' => 10, 'upload_mbps' => 5, 'price' => 1000, 'billing_cycle' => 'monthly'])->assertOk();
        $this->package = Package::where('name', 'PTP 1000')->whereNull('reseller_id')->latest('id')->firstOrFail();
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

    // paid 15 Sep 10:00 → 15 Oct 10:00
    private function paidConnection(): Connection
    {
        $phone = '0176777' . str_pad((string) (3000 + ++$this->phone), 4, '0', STR_PAD_LEFT);
        $this->api('/customer', ['name' => "PTP Customer {$this->phone}", 'phone' => $phone, 'area_id' => $this->areaId])->assertOk();
        $customer = Customer::where('phone', $phone)->firstOrFail();
        $id = $this->api('/isp/connection', ['customer_id' => $customer->id, 'package_id' => $this->package->id, 'connection_type' => 'pppoe',
            'pppoe_username' => 'ptp_user_' . $customer->id, 'activate_now' => true])->assertOk()->json('id');
        $this->api('/isp/payment', ['customer_id' => $customer->id, 'amount' => 1000, 'method' => 'cash', 'payment_date' => '2026-09-15'])->assertOk();
        return Connection::findOrFail($id);
    }

    private function runAt(string $time): void
    {
        Carbon::setTestNow($time);
        $this->artisan('isp:generate-invoices')->assertSuccessful();
        $this->artisan('isp:process-overdue')->assertSuccessful();
    }

    public function test_promise_brings_a_suspended_line_back_and_is_kept_when_paid(): void
    {
        $c = $this->paidConnection();
        $this->runAt('2026-10-15 10:00:00');
        $this->assertSame('suspended', $c->fresh()->status);

        $this->api('/isp/promise', ['connection_id' => $c->id, 'promised_date' => '2026-10-30'])->assertStatus(422); // more than 7 days ahead
        $this->api('/isp/promise', ['connection_id' => $c->id, 'promised_date' => '2026-10-18', 'amount' => 1000, 'note' => 'Salary on the 18th'])->assertOk()
            ->assertJsonPath('message', 'Promise recorded: the line stays on until 18 Oct 2026 (switched back on)');
        $this->assertSame('active', $c->fresh()->status);
        $this->api('/isp/promise', ['connection_id' => $c->id, 'promised_date' => '2026-10-19'])->assertStatus(422); // one open promise at a time

        // the scheduler leaves it on until the date
        $this->runAt('2026-10-17 12:00:00');
        $this->assertSame('active', $c->fresh()->status);

        $this->api('/isp/payment', ['customer_id' => $c->customer_id, 'amount' => 1000, 'method' => 'cash', 'payment_date' => '2026-10-17'])->assertOk();
        $this->runAt('2026-10-17 12:01:00');
        $this->assertSame('kept', PaymentPromise::where('connection_id', $c->id)->value('status'));
        $this->assertSame('active', $c->fresh()->status);
    }

    public function test_a_broken_promise_suspends_the_line_and_reminds_the_day_before(): void
    {
        Bus::fake([\App\Jobs\SendSms::class]);
        $c = $this->paidConnection();
        $this->runAt('2026-10-15 10:00:00');
        $this->api('/isp/promise', ['connection_id' => $c->id, 'promised_date' => '2026-10-17'])->assertOk();

        $this->runAt('2026-10-16 09:00:00'); // the day before: reminder
        $this->runAt('2026-10-16 09:01:00'); // only once
        Bus::assertDispatched(\App\Jobs\SendSms::class, fn ($job) => str_contains($job->message, 'promised to pay') && str_contains($job->message, '17 Oct 2026'));
        $this->assertSame(1, Bus::dispatched(\App\Jobs\SendSms::class, fn ($job) => str_contains($job->message, 'promised to pay'))->count());

        $this->runAt('2026-10-17 23:59:00');
        $this->assertSame('active', $c->fresh()->status, 'the whole promised day');
        $this->runAt('2026-10-18 00:00:00');
        $this->assertSame('broken', PaymentPromise::where('connection_id', $c->id)->value('status'));
        $this->assertSame('suspended', $c->fresh()->status);
        $this->assertSame(1, $this->api('/isp/get-connection', ['id' => $c->id])->json('promises_broken'));
    }

    public function test_limits_and_cancel(): void
    {
        IspSettings::save($this->branch->id, ['promise_max_per_30' => 1]);
        $c = $this->paidConnection();
        $this->api('/isp/promise', ['connection_id' => $c->id, 'promised_date' => '2026-09-20'])->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'has paid time until 15 Oct 2026')); // nothing to hold
        $this->runAt('2026-10-15 10:00:00');
        $id = $this->api('/isp/promise', ['connection_id' => $c->id, 'promised_date' => '2026-10-16'])->assertOk()->json('id');
        $this->assertSame('active', $c->fresh()->status);
        $this->api('/isp/promise-cancel', ['id' => $id, 'reason' => 'Customer changed their mind'])->assertOk();
        $this->assertSame('suspended', $c->fresh()->status, 'the hold is gone at once');
        // cancelled ones don't count against the limit, kept/broken/open do
        $this->api('/isp/promise', ['connection_id' => $c->id, 'promised_date' => '2026-10-16'])->assertOk();
        $this->api('/isp/promise-cancel', ['id' => PaymentPromise::where('connection_id', $c->id)->where('status', 'open')->value('id'), 'reason' => 'x x x'])->assertOk();
        PaymentPromise::where('connection_id', $c->id)->latest('id')->first()->update(['status' => 'broken']);
        $this->api('/isp/promise', ['connection_id' => $c->id, 'promised_date' => '2026-10-16'])->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'promise(s) in the last 30 days'));
        $this->assertSame(1, $this->api('/isp/get-promises', ['status' => 'broken'])->json('page.total'));
    }
}
