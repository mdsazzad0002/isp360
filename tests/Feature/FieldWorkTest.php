<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Connection;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventorySerial;
use App\Models\Olt;
use App\Models\Onu;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Isp\InventoryService;
use App\Services\Isp\IspSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// Inventory (stock only ever computed from movements) and work orders (a technician's job from new
// connection to done, materials leaving their stock, the ONU serial following the customer).
class FieldWorkTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private User $tech;
    private Branch $branch;
    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['isp.network_driver' => \App\Services\Network\NullNetworkDriver::class]);
        Storage::fake('local');
        IspSettings::flush();
        $this->admin = User::where('role', 'Superadmin')->firstOrFail();
        $this->branch = Branch::firstOrFail();
        \App\Models\Role::forceCreate(['name' => 'Technician', 'access' => json_encode(['workOrderTech']), 'status' => 'a', 'branch_id' => $this->branch->id]);
        $this->tech = User::forceCreate(['name' => 'Tech Rafiq', 'username' => 'tech_' . random_int(1000, 9999), 'email' => 'tech' . random_int(1000, 9999) . '@example.test',
            'role' => 'Technician', 'password' => bcrypt('secret-pass'), 'status' => 'a', 'ipAddress' => '127.0.0.1', 'branch_id' => $this->branch->id]);
    }

    private function api(string $uri, array $data = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->withSession(['branch' => $this->branch])->postJson($uri, $data);
    }

    private function item(array $data): InventoryItem
    {
        return InventoryItem::findOrFail($this->api('/isp/inventory-item', $data + ['unit' => 'pcs'])->assertOk()->json('id'));
    }

    private function connection(): Connection
    {
        $n = ++$this->n;
        $area = $this->api('/area', ['name' => "FW Area {$n}"])->json('id');
        $this->api('/isp/package', ['name' => "FW 10 {$n}", 'download_mbps' => 10, 'upload_mbps' => 5, 'price' => 600, 'billing_cycle' => 'monthly'])->assertOk();
        $phone = '01713' . random_int(100000, 999999);
        $this->api('/customer', ['name' => "Field customer {$n}", 'phone' => $phone, 'area_id' => $area, 'address' => 'House 5, Road 2'])->assertOk();
        $customer = Customer::where('phone', $phone)->firstOrFail();
        $this->api('/isp/connection', ['customer_id' => $customer->id, 'package_id' => \App\Models\Package::where('name', "FW 10 {$n}")->value('id'),
            'connection_type' => 'pppoe', 'pppoe_username' => "fw_user_{$n}_" . random_int(100, 999)])->assertOk();
        return Connection::where('customer_id', $customer->id)->firstOrFail();
    }

    public function test_stock_is_counted_from_movements_and_never_goes_negative(): void
    {
        $cable = $this->item(['name' => 'Drop cable 2 core', 'category' => 'cable', 'unit' => 'm', 'low_stock' => 100]);
        $onu = $this->item(['name' => 'Huawei HG8010H', 'category' => 'onu', 'serialized' => true]);

        $this->api('/isp/inventory-receive', ['item_id' => $cable->id, 'qty' => 500, 'unit_cost' => 12])->assertOk();
        $this->api('/isp/inventory-receive', ['item_id' => $onu->id, 'serials' => "HWTC00000001\nHWTC00000002, hwtc00000003"])->assertOk();
        $this->api('/isp/inventory-receive', ['item_id' => $onu->id, 'serials' => 'HWTC00000002'])->assertStatus(422); // already in stock

        $this->api('/isp/inventory-move', ['item_id' => $cable->id, 'type' => 'issue', 'tech_id' => $this->tech->id, 'qty' => 450])->assertOk();
        $this->api('/isp/inventory-move', ['item_id' => $cable->id, 'type' => 'issue', 'tech_id' => $this->tech->id, 'qty' => 51])->assertStatus(422)
            ->assertJsonPath('message', 'Drop cable 2 core: only 50 m at the store.');
        $this->api('/isp/inventory-move', ['item_id' => $onu->id, 'type' => 'issue', 'tech_id' => $this->tech->id, 'serials' => ['HWTC00000001', 'HWTC00000003']])->assertOk();
        $this->api('/isp/inventory-move', ['item_id' => $onu->id, 'type' => 'faulty', 'serials' => ['HWTC00000002']])->assertOk();

        $rows = collect($this->api('/isp/get-inventory')->json())->keyBy('id');
        $this->assertSame([50, 450, 0], [(int) $rows[$cable->id]['store'], (int) $rows[$cable->id]['techs'], (int) $rows[$cable->id]['installed']]);
        $this->assertTrue($rows[$cable->id]['low']);
        $this->assertSame([0, 2, 1], [(int) $rows[$onu->id]['store'], (int) $rows[$onu->id]['techs'], (int) $rows[$onu->id]['faulty']]);
        $this->assertSame(12.0, $cable->fresh()->unit_cost);

        // stock count: 47 m found in the store
        $this->api('/isp/inventory-adjust', ['item_id' => $cable->id, 'counted' => 47])->assertOk();
        $this->assertSame(47.0, InventoryService::stock($cable, 'store'));
        // serial tracking can't be switched once there is stock
        $this->api('/isp/inventory-item', ['id' => $onu->id, 'name' => $onu->name, 'category' => 'onu', 'unit' => 'pcs', 'serialized' => false])->assertStatus(422);

        $held = collect($this->api('/isp/get-inventory-held', [], $this->tech)->assertOk()->json())->keyBy('item.id');
        $this->assertSame(['HWTC00000001', 'HWTC00000003'], $held[$onu->id]['serials']);
        $this->api('/isp/get-inventory', [], $this->tech)->assertForbidden();
    }

    public function test_new_connection_opens_a_job_that_the_technician_finishes(): void
    {
        $cable = $this->item(['name' => 'Drop cable', 'category' => 'cable', 'unit' => 'm']);
        $onuItem = $this->item(['name' => 'ONU', 'category' => 'onu', 'serialized' => true]);
        $this->api('/isp/inventory-receive', ['item_id' => $cable->id, 'qty' => 300]);
        $this->api('/isp/inventory-receive', ['item_id' => $onuItem->id, 'serials' => 'HWTC1A2B3C4D']);
        $this->api('/isp/inventory-move', ['item_id' => $cable->id, 'type' => 'issue', 'tech_id' => $this->tech->id, 'qty' => 200]);
        $this->api('/isp/inventory-move', ['item_id' => $onuItem->id, 'type' => 'issue', 'tech_id' => $this->tech->id, 'serials' => ['HWTC1A2B3C4D']]);

        $connection = $this->connection();
        $wo = WorkOrder::where('connection_id', $connection->id)->sole();
        $this->assertSame(['installation', 'new'], [$wo->type, $wo->status]);
        $this->assertStringContainsString('House 5, Road 2', $wo->address);
        $this->assertNull(\App\Services\Isp\WorkOrderService::autoInstall($connection, $this->admin->id), 'no second installation job while one is open');

        // the technician can't see it until it is theirs
        $this->api('/isp/get-work-order', ['id' => $wo->id], $this->tech)->assertForbidden();
        $this->api('/isp/work-order-assign', ['id' => $wo->id, 'assigned_to' => $this->tech->id, 'scheduled_at' => now()->addDay()->format('Y-m-d 10:00')])->assertOk();
        $this->assertSame(1, $this->api('/isp/get-work-orders', ['mine' => true], $this->tech)->json('page.total'));
        $this->api('/isp/work-order-start', ['id' => $wo->id], $this->tech)->assertOk();
        $this->api('/isp/work-order-photo', ['id' => $wo->id, 'photo' => UploadedFile::fake()->image('site.jpg')], $this->tech)->assertOk();
        $this->api('/isp/work-order-cancel', ['id' => $wo->id, 'reason' => 'x'], $this->tech)->assertForbidden(); // office only

        // the ONU on the OLT list gets linked when the technician reports its serial
        $olt = Olt::create(['branch_id' => $this->branch->id, 'name' => 'OLT', 'vendor' => 'other', 'host' => '10.1.1.1']);
        $onu = Onu::create(['branch_id' => $this->branch->id, 'olt_id' => $olt->id, 'pon_port' => '0/1/0', 'onu_id' => 4, 'serial' => 'HWTC1A2B3C4D']);

        $png = 'data:image/png;base64,' . base64_encode(self::png());
        $this->api('/isp/work-order-complete', ['id' => $wo->id, 'result' => 'Installed, 180 m drop, ONU on port 0/1/0', 'onu_serial' => 'hwtc1a2b3c4d', 'signature' => $png,
            'materials' => [['item_id' => $cable->id, 'qty' => 180]]], $this->tech)->assertOk();

        $wo->refresh();
        $this->assertSame(['done', 'HWTC1A2B3C4D', $this->tech->id], [$wo->status, $wo->onu_serial, $wo->completed_by]);
        $this->assertTrue(Storage::disk('local')->exists($wo->signature));
        $this->assertSame(20.0, InventoryService::stock($cable, InventoryService::tech($this->tech->id)));
        $this->assertSame(180.0, InventoryService::stock($cable, InventoryService::conn($connection->id)));
        $unit = InventorySerial::where('serial', 'HWTC1A2B3C4D')->sole();
        $this->assertSame([InventoryService::conn($connection->id), $connection->id], [$unit->location, $unit->connection_id]);
        $this->assertSame($connection->id, $onu->fresh()->connection_id);
        $this->assertSame(2, InventoryMovement::where('work_order_id', $wo->id)->count());
        $this->api('/isp/work-order-complete', ['id' => $wo->id, 'result' => 'again'], $this->tech)->assertStatus(422); // already done

        // the signature is served to the job's people only
        $this->actingAs($this->tech)->withSession(['branch' => $this->branch])->get("/isp/work-order-file/{$wo->id}?type=signature")->assertOk();
    }

    public function test_materials_must_be_in_the_technicians_hands(): void
    {
        $cable = $this->item(['name' => 'Patch cord', 'category' => 'cable', 'unit' => 'm']);
        $this->api('/isp/inventory-receive', ['item_id' => $cable->id, 'qty' => 100]); // in the store, not with the technician
        $connection = $this->connection();
        $wo = WorkOrder::where('connection_id', $connection->id)->sole();
        $this->api('/isp/work-order-assign', ['id' => $wo->id, 'assigned_to' => $this->tech->id]);
        $this->api('/isp/work-order-complete', ['id' => $wo->id, 'result' => 'done', 'materials' => [['item_id' => $cable->id, 'qty' => 10]]], $this->tech)
            ->assertStatus(422)->assertJsonPath('message', 'Patch cord: only 0 m at technician Tech Rafiq.');
        $this->assertSame('assigned', $wo->fresh()->status, 'nothing is half-done');
        $this->api('/isp/work-order-complete', ['id' => $wo->id, 'result' => 'x', 'signature' => 'data:image/png;base64,' . base64_encode('<?php echo 1;')], $this->tech)->assertStatus(422);
    }

    public function test_disconnection_brings_the_equipment_back_and_ticket_gets_a_note(): void
    {
        IspSettings::save($this->branch->id, ['auto_install_work_order' => false]);
        $onuItem = $this->item(['name' => 'ONU', 'category' => 'onu', 'serialized' => true]);
        $connection = $this->connection();
        $this->assertSame(0, WorkOrder::where('connection_id', $connection->id)->count(), 'setting off: no automatic job');
        $this->api('/isp/inventory-receive', ['item_id' => $onuItem->id, 'serials' => 'ZTEGC0FFEE01']);
        InventoryService::move($onuItem, 'install', 'store', InventoryService::conn($connection->id), 1, ['ZTEGC0FFEE01'], [], $this->admin->id);

        $ticketId = $this->api('/isp/ticket', ['customer_id' => $connection->customer_id, 'connection_id' => $connection->id, 'subject' => 'Close my line', 'message' => 'Moving abroad'])->json('id');
        $woId = $this->api('/isp/work-order', ['type' => 'disconnection', 'ticket_id' => $ticketId, 'connection_id' => $connection->id, 'assigned_to' => $this->tech->id])->assertOk()->json('id');
        $this->api('/isp/work-order-complete', ['id' => $woId, 'result' => 'ONU collected', 'recovered' => [['item_id' => $onuItem->id, 'serials' => ['ZTEGC0FFEE01']]]], $this->tech)->assertOk();

        $this->assertSame(InventoryService::tech($this->tech->id), InventorySerial::where('serial', 'ZTEGC0FFEE01')->value('location'));
        $notes = Ticket::findOrFail($ticketId)->replies()->where('is_internal', true)->pluck('message')->all();
        $this->assertCount(2, $notes); // opened, done
        $this->assertStringContainsString('done: ONU collected', $notes[1]);
    }

    public function test_onu_linked_on_the_olt_page_leaves_stock(): void
    {
        $onuItem = $this->item(['name' => 'ONU', 'category' => 'onu', 'serialized' => true]);
        $this->api('/isp/inventory-receive', ['item_id' => $onuItem->id, 'serials' => 'HWTC00C0FFEE']);
        $connection = $this->connection();
        $olt = Olt::create(['branch_id' => $this->branch->id, 'name' => 'OLT', 'vendor' => 'other', 'host' => '10.1.1.1']);
        $onu = Onu::create(['branch_id' => $this->branch->id, 'olt_id' => $olt->id, 'pon_port' => '0/1/0', 'onu_id' => 9, 'serial' => 'HWTC00C0FFEE']);
        $this->api('/isp/onu-link', ['id' => $onu->id, 'connection_id' => $connection->id])->assertOk();
        $this->assertSame(InventoryService::conn($connection->id), InventorySerial::where('serial', 'HWTC00C0FFEE')->value('location'));
    }

    private static function png(): string
    {
        $img = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($img);
        return (string) ob_get_clean();
    }
}
