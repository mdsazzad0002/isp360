<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Connection;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Due aging buckets, and one action on many connections (preview, run, partial failure, permissions).
class AgingAndBulkTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private Branch $branch;
    private int $areaId;
    private int $packageId;
    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['isp.network_driver' => \App\Services\Network\NullNetworkDriver::class]);
        \App\Services\Isp\IspSettings::flush();
        $this->admin = User::where('role', 'Superadmin')->firstOrFail();
        $this->branch = Branch::firstOrFail();
        $this->areaId = $this->api('/area', ['name' => 'Aging Area ' . random_int(1000, 9999)])->json('id');
        $this->api('/isp/package', ['name' => 'AG 10', 'download_mbps' => 10, 'upload_mbps' => 5, 'price' => 500, 'billing_cycle' => 'monthly'])->assertOk();
        $this->api('/isp/package', ['name' => 'AG 20', 'download_mbps' => 20, 'upload_mbps' => 10, 'price' => 800, 'billing_cycle' => 'monthly'])->assertOk();
        $this->packageId = \App\Models\Package::where('name', 'AG 10')->latest('id')->value('id');
        \App\Services\Isp\IspSettings::save($this->branch->id, ['auto_install_work_order' => false]);
    }

    private function api(string $uri, array $data = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->withSession(['branch' => $this->branch])->postJson($uri, $data);
    }

    private function connection(array $customer = []): Connection
    {
        $n = ++$this->n;
        $phone = '01714' . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->api('/customer', $customer + ['name' => "Aging {$n}", 'phone' => $phone, 'area_id' => $this->areaId])->assertOk();
        $c = Customer::where('phone', $phone)->firstOrFail();
        $this->api('/isp/connection', ['customer_id' => $c->id, 'package_id' => $this->packageId, 'connection_type' => 'pppoe', 'pppoe_username' => "ag_{$n}_" . random_int(100, 999)])->assertOk();
        return Connection::where('customer_id', $c->id)->firstOrFail();
    }

    // an open bill of $due, due $days days ago (negative = not due yet)
    private function bill(Connection $c, float $due, int $days): void
    {
        DB::table('invoices')->insert(['invoice_no' => 'AG-' . random_int(100000, 999999), 'customer_id' => $c->customer_id, 'connection_id' => $c->id, 'invoice_date' => now()->subDays(max($days, 0) + 5)->toDateString(),
            'due_date' => now()->subDays($days)->toDateString(), 'subtotal' => $due, 'total' => $due, 'paid' => 0, 'due' => $due, 'status' => $days > 0 ? 'overdue' : 'issued',
            'branch_id' => $this->branch->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_aging_puts_each_due_in_its_bucket(): void
    {
        $a = $this->connection();
        $b = $this->connection();
        // only the bills below: the connections' own first bills (due today) are set aside
        Invoice::whereIn('customer_id', [$a->customer_id, $b->customer_id])->update(['status' => 'void']);
        $this->bill($a, 100, -3);  // not due yet
        $this->bill($a, 200, 10);  // 0-30
        $this->bill($b, 300, 45);  // 31-60
        $this->bill($b, 400, 75);  // 61-90
        $this->bill($b, 500, 120); // 90+

        $data = $this->api('/isp/get-due-aging', ['group' => 'area', 'areaId' => $this->areaId])->assertOk()->json();
        $row = $data['rows'][0];
        $this->assertSame([100.0, 200.0, 300.0, 400.0, 500.0, 1500.0, 2], [(float) $row['not_due'], (float) $row['d0_30'], (float) $row['d31_60'], (float) $row['d61_90'], (float) $row['d90'], (float) $row['total'], (int) $row['customers']]);

        $byCustomer = collect($this->api('/isp/get-due-aging', ['group' => 'customer', 'areaId' => $this->areaId])->json('rows'));
        $this->assertSame((int) $b->customer_id, (int) $byCustomer[0]['id'], 'the oldest debt first');
        foreach (['zone', 'box', 'reseller', 'package'] as $group) {
            $this->api('/isp/get-due-aging', ['group' => $group])->assertOk();
        }
    }

    public function test_bulk_previews_then_acts_on_each_and_reports_failures(): void
    {
        $a = $this->connection();
        $b = $this->connection();
        $c = $this->connection();
        foreach ([$a, $b] as $x) {
            $x->update(['status' => 'active']);
        }

        $preview = $this->api('/isp/connection-bulk', ['action' => 'suspend', 'reason' => 'Area maintenance', 'ids' => [$a->id, $b->id, $c->id]])->assertOk();
        $this->assertSame(3, $preview->json('count'));
        $this->assertSame('active', $a->fresh()->status, 'a preview changes nothing');

        $res = $this->api('/isp/connection-bulk', ['action' => 'suspend', 'reason' => 'Area maintenance', 'ids' => [$a->id, $b->id, $c->id], 'run' => true])->assertOk();
        $this->assertSame(['suspended', 'suspended'], [$a->fresh()->status, $b->fresh()->status]);
        $this->assertCount(1, $res->json('failed')); // the pending one can't be suspended
        $this->assertSame($c->code, $res->json('failed.0.code'));
        $this->assertStringStartsWith('2 of 3 suspended', $res->json('message'));
        $this->assertSame(1, AuditLog::where('action', 'connection.bulk')->count());

        // every connection matching the list's filter
        $this->assertSame(2, $this->api('/isp/connection-bulk', ['action' => 'reactivate', 'reason' => 'Done', 'all_matching' => true, 'status' => 'suspended', 'areaId' => $this->areaId])->json('count'));
    }

    public function test_bulk_sms_respects_opt_out_and_work_orders_need_their_permission(): void
    {
        Bus::fake([\App\Jobs\SendSms::class]);
        $a = $this->connection();
        $b = $this->connection();
        Customer::whereKey($b->customer_id)->update(['marketing_opt_out' => true]);

        $res = $this->api('/isp/connection-bulk', ['action' => 'sms', 'sms_kind' => 'promotion', 'message' => 'Hi {name}, 20 Mbps for {connection} now', 'ids' => [$a->id, $b->id], 'run' => true])->assertOk();
        $this->assertStringContainsString('1 skipped', $res->json('message'));
        Bus::assertDispatched(\App\Jobs\SendSms::class, fn ($job) => str_contains($job->message, "for {$a->code} now") && $job->purpose === 'isp_promotion');
        Bus::assertDispatchedTimes(\App\Jobs\SendSms::class, 1);
        // a service notice goes to both
        $this->api('/isp/connection-bulk', ['action' => 'sms', 'message' => 'Maintenance tonight', 'ids' => [$a->id, $b->id], 'run' => true])->assertOk();
        Bus::assertDispatchedTimes(\App\Jobs\SendSms::class, 3);

        $this->api('/isp/connection-bulk', ['action' => 'work_order', 'wo_type' => 'repair', 'notes' => 'Check drop cable', 'ids' => [$a->id, $b->id], 'run' => true])->assertOk();
        $this->assertSame(2, WorkOrder::whereIn('connection_id', [$a->id, $b->id])->where('type', 'repair')->count());

        \App\Models\Role::forceCreate(['name' => 'BulkClerk', 'access' => json_encode(['connection', 'connectionAction']), 'status' => 'a', 'branch_id' => $this->branch->id]);
        $clerk = User::forceCreate(['name' => 'Bulk clerk', 'username' => 'bulk_' . random_int(1000, 9999), 'email' => 'bulk' . random_int(1000, 9999) . '@example.test',
            'role' => 'BulkClerk', 'password' => bcrypt('secret-pass'), 'status' => 'a', 'ipAddress' => '127.0.0.1', 'branch_id' => $this->branch->id]);
        $this->api('/isp/connection-bulk', ['action' => 'sms', 'message' => 'x', 'ids' => [$a->id]], $clerk)->assertForbidden();
        $this->api('/isp/connection-bulk', ['action' => 'work_order', 'wo_type' => 'repair', 'ids' => [$a->id]], $clerk)->assertForbidden();
        $this->api('/isp/connection-bulk', ['action' => 'sync', 'ids' => [$a->id]], $clerk)->assertOk();
    }

    public function test_bulk_package_change_and_other_branch_ids_are_ignored(): void
    {
        $a = $this->connection();
        $a->update(['status' => 'active', 'expire_at' => now()->addDays(20), 'activation_date' => now()->subDays(10)]);
        $new = \App\Models\Package::where('name', 'AG 20')->latest('id')->value('id');
        $this->api('/isp/connection-bulk', ['action' => 'change_package', 'package_id' => $new, 'ids' => [$a->id], 'run' => true])->assertOk();
        $this->assertSame($new, (int) $a->fresh()->package_id);

        $foreign = Connection::where('branch_id', '!=', $this->branch->id)->value('id');
        if ($foreign) {
            $this->assertSame(0, $this->api('/isp/connection-bulk', ['action' => 'sync', 'ids' => [$foreign]])->json('count'));
        }
    }
}
