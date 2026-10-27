<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashHandover;
use App\Models\CollectionVisit;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\User;
use App\Services\Isp\CollectorService;
use App\Services\Isp\IspSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

// Door-to-door collection: the route covers the collector's areas only, cash goes through the normal
// payment, cash in hand = collected - confirmed handovers, and nobody confirms their own cash.
class CollectorTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private User $collector;
    private Branch $branch;
    private int $areaA;
    private int $areaB;

    protected function setUp(): void
    {
        parent::setUp();
        config(['isp.network_driver' => \App\Services\Network\NullNetworkDriver::class]);
        IspSettings::flush();
        $this->admin = User::where('role', 'Superadmin')->firstOrFail();
        $this->branch = Branch::firstOrFail();
        IspSettings::save($this->branch->id, ['auto_install_work_order' => false]);
        \App\Models\Role::forceCreate(['name' => 'Collector', 'access' => json_encode(['collector']), 'status' => 'a', 'branch_id' => $this->branch->id]);
        $this->collector = User::forceCreate(['name' => 'Collector Karim', 'username' => 'col_' . random_int(1000, 9999), 'email' => 'col' . random_int(1000, 9999) . '@example.test',
            'role' => 'Collector', 'password' => bcrypt('secret-pass'), 'status' => 'a', 'ipAddress' => '127.0.0.1', 'branch_id' => $this->branch->id]);
        $this->areaA = $this->api('/area', ['name' => 'Col Area A ' . random_int(100, 999)])->json('id');
        $this->areaB = $this->api('/area', ['name' => 'Col Area B ' . random_int(100, 999)])->json('id');
        $this->api('/isp/package', ['name' => 'COL 500', 'download_mbps' => 10, 'upload_mbps' => 5, 'price' => 500, 'billing_cycle' => 'monthly'])->assertOk();
    }

    private function api(string $uri, array $data = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->withSession(['branch' => $this->branch])->postJson($uri, $data);
    }

    // a customer owing one 500 bill
    private function debtor(int $areaId, string $name): Customer
    {
        $phone = '01715' . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->api('/customer', ['name' => $name, 'phone' => $phone, 'area_id' => $areaId])->assertOk();
        $c = Customer::where('phone', $phone)->firstOrFail();
        $this->api('/isp/connection', ['customer_id' => $c->id, 'package_id' => \App\Models\Package::where('name', 'COL 500')->latest('id')->value('id'),
            'connection_type' => 'pppoe', 'pppoe_username' => 'col_' . $c->id, 'activate_now' => true])->assertOk();
        return $c->fresh();
    }

    public function test_route_collect_visit_and_handover(): void
    {
        $inA = $this->debtor($this->areaA, 'Debtor in A');
        $inB = $this->debtor($this->areaB, 'Debtor in B');
        $this->assertGreaterThan(0, (float) $inA->ledger_balance);

        // no areas yet: nothing to walk
        $this->assertSame([], $this->api('/isp/get-collect-route', [], $this->collector)->json('customers'));
        $this->api('/isp/collector-areas', ['user_id' => $this->collector->id, 'area_ids' => [$this->areaA]])->assertOk();
        $this->api('/isp/collector-areas', ['user_id' => $this->collector->id, 'area_ids' => [$this->areaA]], $this->collector)->assertForbidden();

        $route = collect($this->api('/isp/get-collect-route', [], $this->collector)->assertOk()->json('customers'));
        $this->assertContains($inA->id, $route->pluck('id')->all());
        $this->assertNotContains($inB->id, $route->pluck('id')->all());

        // someone outside the areas: refused
        $this->api('/isp/collect-pay', ['customer_id' => $inB->id, 'amount' => 500], $this->collector)->assertStatus(422);

        $this->api('/isp/collect-pay', ['customer_id' => $inA->id, 'amount' => 500, 'lat' => 23.81, 'lng' => 90.41], $this->collector)->assertOk();
        $payment = CustomerPayment::where('customer_id', $inA->id)->sole();
        $this->assertSame(['cash', $this->collector->id], [$payment->method, (int) $payment->received_by]);
        $this->assertEquals(0, (float) $inA->fresh()->ledger_balance);
        $this->assertSame('paid', CollectionVisit::where('customer_id', $inA->id)->value('outcome'));
        $this->assertEquals(23.81, (float) CollectionVisit::where('customer_id', $inA->id)->value('latitude'));

        $other = $this->debtor($this->areaA, 'Not home');
        $this->api('/isp/collect-visit', ['customer_id' => $other->id, 'outcome' => 'not_home'], $this->collector)->assertOk();
        $this->assertSame('not_home', collect($this->api('/isp/get-collect-route', [], $this->collector)->json('customers'))->firstWhere('id', $other->id)['visited_today']);

        // cash in hand and handover
        $this->assertEquals(500, $this->api('/isp/get-collect-route', [], $this->collector)->json('cash.in_hand'));
        $this->api('/isp/collect-handover', ['amount' => 600], $this->collector)->assertStatus(422);
        $this->api('/isp/collect-handover', ['amount' => 300], $this->collector)->assertOk();
        $this->api('/isp/collect-handover', ['amount' => 300], $this->collector)->assertStatus(422); // 300 already waiting
        $h = CashHandover::where('collector_id', $this->collector->id)->sole();
        $this->assertEquals(500, CollectorService::cash($this->branch->id, $this->collector->id)['in_hand'], 'still in hand until the office confirms');

        $this->api('/isp/cash-handover-decide', ['id' => $h->id, 'confirm' => true])->assertOk();
        $cash = CollectorService::cash($this->branch->id, $this->collector->id);
        $this->assertEquals([200, 300, 0], [$cash['in_hand'], $cash['handed_over'], $cash['pending']]);

        $row = collect($this->api('/isp/get-collectors')->assertOk()->json())->firstWhere('id', $this->collector->id);
        $this->assertSame([$this->areaA], $row['area_ids']);
    }

    public function test_nobody_confirms_their_own_handover_and_reversed_cash_leaves_the_hand(): void
    {
        // a senior collector who also manages collectors
        \App\Models\Role::forceCreate(['name' => 'HeadCollector', 'access' => json_encode(['collector', 'collectorManage']), 'status' => 'a', 'branch_id' => $this->branch->id]);
        $head = User::forceCreate(['name' => 'Head collector', 'username' => 'head_' . random_int(1000, 9999), 'email' => 'head' . random_int(1000, 9999) . '@example.test',
            'role' => 'HeadCollector', 'password' => bcrypt('secret-pass'), 'status' => 'a', 'ipAddress' => '127.0.0.1', 'branch_id' => $this->branch->id]);
        $c = $this->debtor($this->areaA, 'Self check');
        CollectorService::setAreas($this->branch->id, $head->id, [$this->areaA]);
        $this->api('/isp/collect-pay', ['customer_id' => $c->id, 'amount' => 500], $head)->assertOk();
        $this->api('/isp/collect-handover', ['amount' => 500], $head)->assertOk();
        $h = CashHandover::where('collector_id', $head->id)->sole();
        $this->api('/isp/cash-handover-decide', ['id' => $h->id, 'confirm' => true], $head)->assertStatus(422)
            ->assertJsonPath('message', 'Someone else must confirm the cash you hand over.');

        // the office reverses the payment: the cash is no longer theirs to hold
        $payment = CustomerPayment::where('customer_id', $c->id)->sole();
        $this->api('/isp/payment-reverse', ['id' => $payment->id, 'reason' => 'Wrong customer'])->assertOk();
        $this->assertEquals(0, CollectorService::cash($this->branch->id, $head->id)['in_hand']);
        $this->api('/isp/cash-handover-decide', ['id' => $h->id, 'confirm' => false, 'reason' => 'Cash was for a reversed payment'])->assertOk();
        $this->assertSame('rejected', $h->fresh()->status);
    }
}
