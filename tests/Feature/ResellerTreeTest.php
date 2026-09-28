<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Reseller;
use App\Models\ResellerTransaction;
use App\Models\User;
use App\Services\Isp\CollectionService;
use App\Services\Isp\IspSettings;
use App\Services\Isp\ResellerLedgerService;
use App\Services\Isp\ResellerWalletService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Multi-level reseller network (roadmap 3.3): master M -> distributor D -> sub-reseller S.
// Company package 500, M's copy 600, D's 700, S's 800: each level earns 100 on a bill.
// Each reseller's balance is with the level above; a parent's includes its subtree.
class ResellerTreeTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private Branch $branch;
    private Reseller $m;
    private Reseller $d;
    private Reseller $s;
    private Package $sPackage;
    private int $areaId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['isp.network_driver' => \App\Services\Network\NullNetworkDriver::class]);
        Carbon::setTestNow('2026-09-01 10:00:00');
        $this->admin = User::where('role', 'Superadmin')->firstOrFail();
        $this->branch = Branch::firstOrFail();
        IspSettings::save($this->branch->id, ['reseller_max_depth' => 3]);

        $this->api('/isp/package', ['name' => 'RT 20', 'download_mbps' => 20, 'upload_mbps' => 10, 'price' => 500, 'billing_cycle' => 'monthly', 'visibility' => 'hidden'])->assertOk();
        $company = Package::where('name', 'RT 20')->whereNull('reseller_id')->firstOrFail();

        $this->m = $this->makeReseller('rt_master');
        $this->d = $this->makeReseller('rt_dist', $this->m);
        $this->s = $this->makeReseller('rt_sub', $this->d);
        $this->assertSame([1, 2, 3], [$this->m->depth, $this->d->depth, $this->s->depth]);

        $mPackage = $this->customize($this->m, $company, 'RT M', 600);
        // a sub-reseller builds on its parent's packages, never on the company's directly
        $this->portal($this->d)->postJson('/reseller/package', ['base_package_id' => $company->id, 'name' => 'RT D', 'price' => 700])->assertStatus(422);
        $dPackage = $this->customize($this->d, $mPackage, 'RT D', 700);
        $this->portal($this->s)->postJson('/reseller/package', ['base_package_id' => $dPackage->id, 'name' => 'RT S', 'price' => 650])->assertStatus(422);
        $this->sPackage = $this->customize($this->s, $dPackage, 'RT S', 800);

        $this->api('/isp/zone', ['name' => 'RT Zone', 'code' => 'RTZ'])->assertOk();
        $this->api('/area', ['name' => 'RT Area', 'zone_id' => DB::table('zones')->where('name', 'RT Zone')->value('id')])->assertOk();
        $this->areaId = DB::table('areas')->where('name', 'RT Area')->value('id');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function api(string $uri, array $data = [])
    {
        return $this->actingAs($this->admin)->withSession(['branch' => $this->branch])->postJson($uri, $data);
    }

    private function portal(Reseller $reseller)
    {
        return $this->actingAs($reseller, 'reseller');
    }

    private function makeReseller(string $username, ?Reseller $parent = null): Reseller
    {
        $this->api('/reseller', ['name' => "Reseller {$username}", 'phone' => '0171' . random_int(1000000, 9999999), 'username' => $username,
            'password' => 'Secret-pass-123', 'parent_id' => $parent?->id])->assertOk();
        return Reseller::where('username', $username)->firstOrFail();
    }

    private function customize(Reseller $reseller, Package $base, string $name, float $price): Package
    {
        $this->portal($reseller)->postJson('/reseller/package', ['base_package_id' => $base->id, 'name' => $name, 'price' => $price])->assertOk();
        return Package::where('reseller_id', $reseller->id)->where('name', $name)->firstOrFail();
    }

    // a customer of S on S's package: its first bill (800) is issued at once
    private function customerOfS(string $phone, string $pppoe): array
    {
        $this->api('/customer', ['name' => "RT {$pppoe}", 'phone' => $phone, 'area_id' => $this->areaId])->assertOk();
        $customer = Customer::where('phone', $phone)->firstOrFail();
        $customer->forceFill(['reseller_id' => $this->s->id])->save();
        $this->api('/isp/connection', ['customer_id' => $customer->id, 'package_id' => $this->sPackage->id, 'connection_type' => 'pppoe',
            'pppoe_username' => $pppoe, 'activate_now' => true, 'activation_date' => '2026-09-01'])->assertOk();
        return [$customer, Invoice::where('customer_id', $customer->id)->whereNotNull('service_months')->firstOrFail()];
    }

    private function balances(): array
    {
        return array_map(fn ($r) => ResellerWalletService::summary($r->id)['balance'], [$this->s, $this->d, $this->m]);
    }

    private function assertStatementsMatchWallets(): void
    {
        foreach ([$this->s, $this->d, $this->m] as $r) {
            $this->assertEquals(ResellerWalletService::summary($r->id)['balance'], ResellerLedgerService::statement($r->id)['closing'], "statement of {$r->username}");
        }
    }

    public function test_each_level_earns_its_margin_and_parents_carry_their_subtree(): void
    {
        [, $invoice] = $this->customerOfS('01799997001', 'rt_user_1');
        $this->assertEquals(800, (float) $invoice->total);
        $this->assertEquals(500, (float) $invoice->reseller_cost, 'the company keeps the top price');
        $this->assertSame($this->s->id, (int) $invoice->reseller_id);
        $this->assertEquals([[0, $this->s->id, 700], [1, $this->d->id, 600], [2, $this->m->id, 500]],
            DB::table('invoice_reseller_shares')->where('invoice_id', $invoice->id)->orderBy('level')->get()->map(fn ($r) => [$r->level, $r->reseller_id, (float) $r->cost])->all());

        // the customer pays the company (online / at the office): the company owes the chain
        CollectionService::receive(Customer::findOrFail($invoice->customer_id), ['amount' => 800, 'method' => 'cash', 'payment_date' => '2026-09-01']);
        $this->assertEquals([100, 200, 300], $this->balances());
        foreach ([$this->s, $this->d, $this->m] as $r) {
            $this->assertEquals(100, ResellerWalletService::summary($r->id)['earned']);
        }
        $this->assertEquals(200, ResellerWalletService::summary($this->m->id)['downline']);

        // a second customer pays S in cash: the cash sits in S's hand, inside D's and M's subtree
        [$bob] = $this->customerOfS('01799997002', 'rt_user_2');
        $this->portal($this->s)->postJson('/reseller/payment', ['customer_id' => $bob->id, 'amount' => 800, 'method' => 'cash'])->assertOk();
        $this->assertEquals([-600, -400, -200], $this->balances());
        $this->assertEquals(800, ResellerWalletService::summary($this->m->id)['downline_collected']);
        $this->assertStatementsMatchWallets();

        // S hands 600 to D: S is square, D's position with M doesn't move (the money stays in D's subtree)
        $this->api('/isp/reseller-deposit', ['reseller_id' => $this->s->id, 'amount' => 600, 'method' => 'cash'])->assertStatus(422);
        $this->portal($this->m)->postJson('/reseller/sub-reseller-deposit', ['reseller_id' => $this->s->id, 'amount' => 600, 'method' => 'cash'])->assertStatus(404);
        $this->portal($this->d)->postJson('/reseller/sub-reseller-deposit', ['reseller_id' => $this->s->id, 'amount' => 600, 'method' => 'cash'])->assertOk();
        $this->assertEquals([0, -400, -200], $this->balances());

        // D hands 400 to M, M hands 200 to the company (into the company cash book)
        $this->portal($this->m)->postJson('/reseller/sub-reseller-deposit', ['reseller_id' => $this->d->id, 'amount' => 400, 'method' => 'cash'])->assertOk();
        $this->api('/isp/reseller-deposit', ['reseller_id' => $this->m->id, 'amount' => 200, 'method' => 'cash'])->assertOk();
        $this->assertEquals([0, 0, 0], $this->balances());
        $this->assertEquals(1, DB::table('receives')->where('type', 'reseller')->where('reseller_id', $this->m->id)->count());
        $this->assertEquals(0, DB::table('receives')->where('type', 'reseller')->whereIn('reseller_id', [$this->s->id, $this->d->id])->count());
        $this->assertStatementsMatchWallets();
        $this->artisan('isp:ledger-check')->assertSuccessful();
    }

    public function test_a_sub_reseller_withdraws_from_its_parent_not_the_company(): void
    {
        [$alice, $invoice] = $this->customerOfS('01799997011', 'rt_user_11');
        CollectionService::receive($alice, ['amount' => 800, 'method' => 'cash', 'payment_date' => '2026-09-01']);

        $this->portal($this->s)->postJson('/reseller/withdrawal', ['amount' => 100, 'method' => 'cash'])->assertOk()
            ->assertJsonPath('message', fn ($m) => str_contains($m, $this->d->name));
        $tx = ResellerTransaction::where('reseller_id', $this->s->id)->firstOrFail();
        $this->assertSame($this->d->id, (int) $tx->parent_reseller_id);

        $this->api('/isp/reseller-withdrawal-pay', ['id' => $tx->id, 'method' => 'cash'])->assertStatus(422);
        $this->portal($this->m)->postJson('/reseller/sub-reseller-withdrawal-pay', ['id' => $tx->id, 'method' => 'cash'])->assertStatus(404);
        $this->portal($this->d)->postJson('/reseller/get-sub-resellers')->assertOk()
            ->assertJsonPath('children.0.id', $this->s->id)->assertJsonPath('requests.0.id', $tx->id);
        $this->portal($this->d)->postJson('/reseller/sub-reseller-withdrawal-pay', ['id' => $tx->id, 'method' => 'bkash', 'transaction_id' => 'BK1'])->assertOk();

        // only the direct parent reads a sub-reseller's statement
        $this->portal($this->d)->get('/reseller/sub-resellers')->assertOk();
        $this->portal($this->d)->postJson('/reseller/get-sub-reseller-ledger', ['resellerId' => $this->s->id])->assertOk()->assertJsonPath('wallet.balance', 0);
        $this->portal($this->m)->postJson('/reseller/get-sub-reseller-ledger', ['resellerId' => $this->s->id])->assertStatus(404);
        $this->portal($this->s)->postJson('/reseller/get-sub-reseller-ledger', ['resellerId' => $this->d->id])->assertStatus(404);

        // D paid S out of its own pocket: S is square, D is still owed its own 100 plus S's 100
        $this->assertEquals([0, 200, 300], $this->balances());
        $this->assertEquals(0, DB::table('payments')->where('reseller_transaction_id', $tx->id)->count(), 'no company cash-book row');
        $this->assertStatementsMatchWallets();
    }

    public function test_the_credit_limit_stops_cash_collection_until_the_reseller_settles(): void
    {
        [$alice] = $this->customerOfS('01799997021', 'rt_user_21');
        $this->s->forceFill(['credit_limit' => 500])->save();

        // collecting 800 would leave S owing 700 (800 - its 100 margin), over the 500 limit
        $this->portal($this->s)->postJson('/reseller/payment', ['customer_id' => $alice->id, 'amount' => 800, 'method' => 'cash'])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'credit limit'));
        $this->portal($this->s)->postJson('/reseller/payment', ['customer_id' => $alice->id, 'amount' => 400, 'method' => 'cash'])->assertOk();

        $wallet = ResellerWalletService::summary($this->s->id);
        $this->assertEquals(-350, $wallet['balance']); // 50 margin on the 400 paid
        $this->assertFalse($wallet['over_limit']);
        $this->api('/update-reseller', ['id' => $this->s->id, 'name' => $this->s->name, 'phone' => $this->s->phone, 'username' => $this->s->username,
            'parent_id' => $this->d->id, 'credit_limit' => 300])->assertOk();
        $this->assertTrue(ResellerWalletService::summary($this->s->id)['over_limit']);
    }

    public function test_the_tree_keeps_its_depth_and_shape(): void
    {
        // max 3 levels: nothing under S
        $this->api('/reseller', ['name' => 'Too deep', 'phone' => '01711119999', 'username' => 'rt_deep', 'password' => 'Secret-pass-123', 'parent_id' => $this->s->id])
            ->assertStatus(422);
        $this->assertNull(Reseller::where('username', 'rt_deep')->first(), 'the refused reseller is not created');

        // a reseller with packages can't move; nor can one sit under its own sub-reseller
        $this->api('/update-reseller', ['id' => $this->d->id, 'name' => $this->d->name, 'phone' => $this->d->phone, 'username' => $this->d->username, 'parent_id' => null])
            ->assertStatus(422);
        $fresh = $this->makeReseller('rt_fresh');
        $child = $this->makeReseller('rt_fresh_child', $fresh);
        $this->api('/update-reseller', ['id' => $fresh->id, 'name' => $fresh->name, 'phone' => $fresh->phone, 'username' => $fresh->username, 'parent_id' => $child->id])
            ->assertStatus(422);

        // the levels can't drop below the deepest reseller, and a parent with children can't be deleted
        $this->api('/delete-reseller', ['id' => $this->d->id])->assertStatus(422);
        $this->portal($this->d)->postJson('/reseller/delete-package', ['id' => Package::where('reseller_id', $this->d->id)->value('id')])->assertStatus(422);
        $this->assertSame(3, $this->s->fresh()->depth);
        $this->assertSame("/{$this->m->id}/{$this->d->id}/{$this->s->id}/", $this->s->fresh()->path);
    }

    public function test_an_upgrade_bill_splits_between_the_levels_like_a_full_cycle(): void
    {
        [$alice, $invoice] = $this->customerOfS('01799997031', 'rt_user_31');
        CollectionService::receive($alice, ['amount' => 800, 'method' => 'cash', 'payment_date' => '2026-09-01']);

        // the same chain on a package twice the price: company 1000, M 1200, D 1400, S 1600
        $this->api('/isp/package', ['name' => 'RT 40', 'download_mbps' => 40, 'upload_mbps' => 20, 'price' => 1000, 'billing_cycle' => 'monthly', 'visibility' => 'hidden'])->assertOk();
        $m = $this->customize($this->m, Package::where('name', 'RT 40')->firstOrFail(), 'RT M 40', 1200);
        $d = $this->customize($this->d, $m, 'RT D 40', 1400);
        $big = $this->customize($this->s, $d, 'RT S 40', 1600);

        Carbon::setTestNow('2026-09-16 10:00:00');
        $this->api('/isp/connection-change-package', ['id' => DB::table('connections')->where('pppoe_username', 'rt_user_31')->value('id'), 'package_id' => $big->id])->assertOk();
        $extra = Invoice::where('customer_id', $alice->id)->where('id', '!=', $invoice->id)->whereNull('service_months')->latest('id')->firstOrFail();
        $shares = DB::table('invoice_reseller_shares')->where('invoice_id', $extra->id)->orderBy('level')->pluck('cost')->map(fn ($c) => (float) $c)->all();
        $this->assertCount(3, $shares);
        $this->assertEquals((float) $extra->reseller_cost, $shares[2], 'the top level owes the company its share');

        // every level keeps the same margin on the extra, as on a full cycle (100 of 800 each)
        $net = (float) $extra->total - (float) $extra->tax_total;
        $margins = [$net - $shares[0], $shares[0] - $shares[1], $shares[1] - $shares[2]];
        foreach ($margins as $margin) {
            $this->assertEqualsWithDelta($net / 8, $margin, 0.02);
        }
        CollectionService::receive($alice, ['amount' => (float) $extra->total, 'method' => 'cash', 'payment_date' => '2026-09-16']);
        $this->assertStatementsMatchWallets();
        $this->artisan('isp:ledger-check')->assertSuccessful();
    }

    public function test_the_levels_setting_cannot_drop_below_the_deepest_reseller(): void
    {
        $settings = $this->actingAs($this->admin)->withSession(['branch' => $this->branch])->get('/isp/settings');
        $settings->assertOk();
        $this->actingAs($this->admin)->withSession(['branch' => $this->branch])->get('/reseller')->assertOk();
        $data = \App\Services\Isp\IspSettings::all($this->branch->id);
        $this->api('/isp/settings', [])->assertStatus(422); // missing fields: a validation error, not a crash
        $region = ['country_code' => \App\Support\Region::countryCode(), 'currency_code' => \App\Support\Money::code(), 'timezone' => \App\Support\Region::timezone(), 'tax_label' => 'VAT'];
        $this->api('/isp/settings', ['reseller_max_depth' => 2] + $region + $data)->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, '3 levels deep'));
    }

    public function test_one_level_resellers_see_no_change(): void
    {
        IspSettings::save($this->branch->id, ['reseller_max_depth' => 1]);
        $solo = Reseller::create(['code' => 'RS-solo', 'name' => 'Solo', 'phone' => '01711110000', 'username' => 'rt_solo', 'password' => bcrypt('x'), 'branch_id' => $this->branch->id]);
        $this->assertSame(['/' . $solo->id . '/', 1], [$solo->fresh()->path, $solo->fresh()->depth]);
        $this->api('/reseller', ['name' => 'Not allowed', 'phone' => '01711118888', 'username' => 'rt_na', 'password' => 'Secret-pass-123', 'parent_id' => $solo->id])
            ->assertStatus(422);
        $wallet = ResellerWalletService::summary($solo->id);
        $this->assertSame([null, false, 0.0, 0.0], [$wallet['settles_with'], $wallet['over_limit'], $wallet['downline'], $wallet['downline_collected']]);
    }
}
