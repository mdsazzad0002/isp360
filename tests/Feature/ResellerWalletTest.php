<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Reseller;
use App\Models\User;
use App\Services\Isp\ResellerLedgerService;
use App\Services\Isp\ResellerWalletService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

// Reseller portal money flow: customized package -> billing with the company share
// snapshotted -> reseller collects -> deposit / withdrawal settle the wallet. Company changes
// to a base package wait for the reseller's review.
class ResellerWalletTest extends TestCase
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
        Carbon::setTestNow('2026-09-01 10:00:00');
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

    private function makeReseller(string $username): Reseller
    {
        return Reseller::create([
            'code' => 'RS-' . $username, 'name' => "Reseller {$username}", 'phone' => '0170000' . rand(1000, 9999),
            'username' => $username, 'password' => Hash::make('secret'), 'branch_id' => $this->branch->id,
        ]);
    }

    // A reseller's new connection is invoiced at once and stays due; the reseller can pay it
    // out of their wallet, which then drops by the amount (only up to what is available).
    public function test_new_connection_invoice_stays_due_until_reseller_pays_from_wallet(): void
    {
        $this->api('/isp/package', ['name' => 'WP 10', 'download_mbps' => 10, 'upload_mbps' => 5, 'price' => 500, 'billing_cycle' => 'monthly'])->assertOk();
        $package = Package::where('name', 'WP 10')->whereNull('reseller_id')->firstOrFail();
        $reseller = $this->makeReseller('wp_reseller_1');
        $this->api('/isp/zone', ['name' => 'WP Zone', 'code' => 'WPZ'])->assertOk();
        $this->api('/area', ['name' => 'WP Area', 'zone_id' => DB::table('zones')->where('name', 'WP Zone')->value('id')])->assertOk();
        $this->api('/customer', ['name' => 'WP Customer', 'phone' => '01799998101', 'area_id' => DB::table('areas')->where('name', 'WP Area')->value('id')])->assertOk();
        $customer = Customer::where('phone', '01799998101')->firstOrFail();
        $customer->forceFill(['reseller_id' => $reseller->id])->save();

        $this->api('/isp/connection', [
            'customer_id' => $customer->id, 'package_id' => $package->id, 'connection_type' => 'pppoe', 'pppoe_username' => 'wp_user_1',
            'activate_now' => true, 'activation_date' => '2026-09-01',
        ])->assertOk()->assertJsonPath('message', fn ($m) => str_contains($m, 'Tk 500.00 due'));
        $invoice = Invoice::where('customer_id', $customer->id)->whereNotNull('service_months')->firstOrFail();
        $this->assertEquals('suspended', DB::table('connections')->where('pppoe_username', 'wp_user_1')->value('status'));
        $this->assertEquals(500.0, (float) $invoice->due);

        // empty wallet: cannot pay
        $portal = fn () => $this->actingAs($reseller, 'reseller');
        $portal()->postJson('/reseller/payment', ['customer_id' => $customer->id, 'amount' => 500, 'method' => 'wallet'])->assertStatus(422);

        // after a deposit the reseller pays the bill from the wallet; no company cash book row
        $this->api('/isp/reseller-deposit', ['reseller_id' => $reseller->id, 'amount' => 600, 'method' => 'cash'])->assertOk();
        $portal()->postJson('/reseller/payment', ['customer_id' => $customer->id, 'amount' => 500, 'method' => 'wallet'])->assertOk();
        $this->assertEquals('paid', $invoice->fresh()->status);
        $this->assertEquals(0.0, (float) $customer->fresh()->ledger_balance);
        // the month starts the moment the reseller pays
        $this->assertEquals('active', DB::table('connections')->where('pppoe_username', 'wp_user_1')->value('status'));
        $this->assertEquals('2026-10-01 10:00:00', DB::table('connections')->where('pppoe_username', 'wp_user_1')->value('expire_at'));
        $paymentId = DB::table('customer_payments')->where('customer_id', $customer->id)->value('id');
        $this->assertEquals(0, DB::table('receives')->where('customer_payment_id', $paymentId)->count());

        $wallet = ResellerWalletService::summary($reseller->id);
        $this->assertEquals(100.0, $wallet['balance']);
        $this->assertEquals($wallet['balance'], ResellerLedgerService::statement($reseller->id)['closing']);
        $portal()->postJson('/reseller/payment', ['customer_id' => $customer->id, 'amount' => 150, 'method' => 'wallet'])->assertStatus(422);
    }

    public function test_reseller_package_billing_collection_and_withdrawal(): void
    {
        $this->api('/isp/package', ['name' => 'W Hidden 20', 'download_mbps' => 20, 'upload_mbps' => 10, 'price' => 800, 'billing_cycle' => 'monthly', 'visibility' => 'hidden'])->assertOk();
        $base = Package::where('name', 'W Hidden 20')->whereNull('reseller_id')->firstOrFail();
        $this->assertEquals('hidden', $base->visibility);

        $reseller = $this->makeReseller('w_reseller_1');
        $other = $this->makeReseller('w_reseller_2');

        $this->api('/isp/zone', ['name' => 'W Zone', 'code' => 'WZ'])->assertOk();
        $this->api('/area', ['name' => 'W Area', 'zone_id' => DB::table('zones')->where('name', 'W Zone')->value('id')])->assertOk();
        $areaId = DB::table('areas')->where('name', 'W Area')->value('id');
        $this->api('/customer', ['name' => 'W Customer', 'phone' => '01799998001', 'area_id' => $areaId])->assertOk();
        $customer = Customer::where('phone', '01799998001')->firstOrFail();
        $customer->forceFill(['reseller_id' => $reseller->id])->save();

        // hidden package is not for a reseller's customer as-is
        $this->api('/isp/connection', ['customer_id' => $customer->id, 'package_id' => $base->id, 'connection_type' => 'pppoe', 'pppoe_username' => 'w_user_1'])
            ->assertStatus(422);

        // reseller customizes it (not below the company price); live at once, no company approval
        $portal = fn () => $this->actingAs($reseller, 'reseller');
        $portal()->postJson('/reseller/package', ['base_package_id' => $base->id, 'name' => 'My 20', 'price' => 700])->assertStatus(422);
        $portal()->postJson('/reseller/package', ['base_package_id' => $base->id, 'name' => 'My 20', 'price' => 1000])->assertOk();
        $mine = Package::where('reseller_id', $reseller->id)->where('name', 'My 20')->firstOrFail();
        $this->assertEquals(20, $mine->download_mbps);
        $this->assertEquals(800.0, (float) $mine->base_price);
        $this->api('/isp/connection', [
            'customer_id' => $customer->id, 'package_id' => $mine->id, 'connection_type' => 'pppoe', 'pppoe_username' => 'w_user_1',
            'activate_now' => true, 'activation_date' => '2026-09-01',
        ])->assertOk();

        $invoice = Invoice::where('customer_id', $customer->id)->whereNotNull('service_months')->firstOrFail();
        $this->assertEquals(1000.0, (float) $invoice->total);
        $this->assertEquals($reseller->id, $invoice->reseller_id);
        $this->assertEquals(800.0, (float) $invoice->reseller_cost);

        // another reseller cannot touch this customer
        $this->actingAs($other, 'reseller')->postJson('/reseller/payment', ['customer_id' => $customer->id, 'amount' => 10, 'method' => 'cash'])->assertNotFound();

        // reseller collects: customer is cleared, company cash book untouched, reseller holds the cash
        $portal()->postJson('/reseller/payment', ['customer_id' => $customer->id, 'amount' => 600, 'method' => 'cash'])->assertOk();
        $portal()->postJson('/reseller/payment', ['customer_id' => $customer->id, 'amount' => 400, 'method' => 'bkash'])->assertStatus(422); // needs TrxID
        $portal()->postJson('/reseller/payment', ['customer_id' => $customer->id, 'amount' => 400, 'method' => 'bkash', 'transaction_id' => 'WTRX1'])->assertOk();
        $this->assertEquals(0.0, (float) $customer->fresh()->ledger_balance);
        $paymentIds = DB::table('customer_payments')->where('customer_id', $customer->id)->pluck('id');
        $this->assertEquals(0, DB::table('receives')->whereIn('customer_payment_id', $paymentIds)->count());

        $wallet = ResellerWalletService::summary($reseller->id);
        $this->assertEquals(200.0, $wallet['earned']);
        $this->assertEquals(1000.0, $wallet['collected']);
        $this->assertEquals(-800.0, $wallet['balance']);
        $this->assertEquals(0.0, $wallet['available']);
        $portal()->postJson('/reseller/withdrawal', ['amount' => 1, 'method' => 'cash'])->assertStatus(422);

        // reseller hands over the cash -> company cash book gets it, wallet shows the margin
        $this->api('/isp/reseller-deposit', ['reseller_id' => $reseller->id, 'amount' => 1000, 'method' => 'cash'])->assertOk();
        $this->assertEquals(1, DB::table('receives')->where('reseller_id', $reseller->id)->where('type', 'reseller')->count());
        $this->assertEquals(200.0, ResellerWalletService::summary($reseller->id)['available']);

        // withdraw: can't exceed available, pending amounts are held, cancel releases them
        $portal()->postJson('/reseller/withdrawal', ['amount' => 250, 'method' => 'bkash', 'account_details' => '01700000000'])->assertStatus(422);
        $portal()->postJson('/reseller/withdrawal', ['amount' => 150, 'method' => 'bkash', 'account_details' => '01700000000'])->assertOk();
        $portal()->postJson('/reseller/withdrawal', ['amount' => 100, 'method' => 'bkash', 'account_details' => '01700000000'])->assertStatus(422);
        $portal()->postJson('/reseller/withdrawal', ['amount' => 50, 'method' => 'cash'])->assertOk();
        $cancelId = DB::table('reseller_transactions')->where('reseller_id', $reseller->id)->where('amount', 50)->value('id');
        $this->actingAs($other, 'reseller')->postJson('/reseller/withdrawal-cancel', ['id' => $cancelId])->assertNotFound();
        $portal()->postJson('/reseller/withdrawal-cancel', ['id' => $cancelId])->assertOk();

        $wdId = DB::table('reseller_transactions')->where('reseller_id', $reseller->id)->where('amount', 150)->value('id');
        $this->api('/isp/reseller-withdrawal-pay', ['id' => $wdId, 'method' => 'cash'])->assertOk();
        $this->api('/isp/reseller-withdrawal-pay', ['id' => $wdId, 'method' => 'cash'])->assertStatus(422);
        $this->assertEquals(1, DB::table('payments')->where('reseller_transaction_id', $wdId)->count());
        $wallet = ResellerWalletService::summary($reseller->id);
        $this->assertEquals(50.0, $wallet['balance']);
        $this->assertEquals(50.0, $wallet['available']);

        // reseller edits go live at once
        $portal()->postJson('/reseller/package', ['id' => $mine->id, 'base_package_id' => $base->id, 'name' => 'My 20', 'price' => 1100, 'price_change_reason' => 'Upgrade'])->assertOk();
        $this->assertEquals(1100.0, (float) $mine->fresh()->price);
        $this->assertEquals(1, DB::table('package_price_histories')->where('package_id', $mine->id)->count());

        // company changes the base package: the reseller copy keeps its terms until reviewed
        $this->api('/isp/package', ['id' => $base->id, 'name' => 'W Hidden 20', 'download_mbps' => 25, 'upload_mbps' => 10, 'price' => 900, 'billing_cycle' => 'monthly', 'visibility' => 'hidden'])->assertOk();
        $mine->refresh();
        $this->assertEquals(20, $mine->download_mbps);
        $this->assertEquals(800.0, (float) $mine->base_price);
        $portal()->postJson('/reseller/get-packages')->assertOk()->assertJsonPath('0.base_changes.company_price', [800, 900]);
        $this->api('/isp/get-reseller-package-requests', ['status' => 'waiting'])->assertOk()->assertJsonCount(1);

        // the renewal bill still uses the company price the reseller accepted
        Carbon::setTestNow('2026-09-29 10:00:00');
        $this->api('/isp/invoice-generate')->assertOk();
        $october = Invoice::where('customer_id', $customer->id)->whereNotNull('service_months')->latest('id')->firstOrFail();
        $this->assertNotEquals($invoice->id, $october->id);
        $this->assertEquals(1100.0, (float) $october->total);
        $this->assertEquals(800.0, (float) $october->reseller_cost);

        // reseller reviews: can't stay below the new company price; accepting pulls the new terms
        $portal()->postJson('/reseller/package', ['id' => $mine->id, 'base_package_id' => $base->id, 'name' => 'My 20', 'price' => 850])->assertStatus(422);
        $portal()->postJson('/reseller/package', ['id' => $mine->id, 'base_package_id' => $base->id, 'name' => 'My 20', 'price' => 1150])->assertOk();
        $mine->refresh();
        $this->assertEquals(25, $mine->download_mbps);
        $this->assertEquals(900.0, (float) $mine->base_price);
        $portal()->postJson('/reseller/get-packages')->assertOk()->assertJsonPath('0.base_changes', null);
        $this->api('/isp/delete-package', ['id' => $base->id])->assertStatus(422);

        // the reseller ledger always closes at the wallet balance, also after a reversal
        $this->assertEquals(ResellerWalletService::summary($reseller->id)['balance'], ResellerLedgerService::statement($reseller->id)['closing']);
        $cashId = DB::table('customer_payments')->where('customer_id', $customer->id)->where('amount', 600)->value('id');
        $this->api('/isp/payment-reverse', ['id' => $cashId, 'reason' => 'Test reversal'])->assertOk();
        $wallet = ResellerWalletService::summary($reseller->id);
        $statement = ResellerLedgerService::statement($reseller->id);
        $this->assertEquals($wallet['balance'], $statement['closing']);
        $this->assertContains('collection_reversed', array_column($statement['rows'], 'type'));
        $ranged = ResellerLedgerService::statement($reseller->id, '2026-10-01', '2026-12-31');
        $this->assertEquals($statement['closing'], $ranged['closing']);
        $this->assertEquals(round($ranged['opening'] + $ranged['credit'] - $ranged['debit'], 2), $ranged['closing']);
        $this->assertEquals($wallet['balance'], (float) $portal()->postJson('/reseller/get-ledger')->assertOk()->json('closing'));
        $this->api('/isp/get-reseller-ledger', ['resellerId' => $reseller->id])->assertOk();
        $this->api('/isp/get-reseller-ledger', ['resellerId' => $other->id + 999999])->assertNotFound();

        $this->artisan('isp:ledger-check')->assertSuccessful();

        foreach (['/reseller/dashboard', '/reseller/connections', '/reseller/packages', '/reseller/payments', '/reseller/withdrawals', '/reseller/ledger'] as $uri) {
            $portal()->get($uri)->assertOk();
        }
        $portal()->postJson('/reseller/get-payments', ['dateFrom' => '2026-09-01', 'dateTo' => '2026-09-30'])->assertOk()->assertJsonPath('totals.amount', '400.00'); // the 600 cash was reversed above
        $portal()->postJson('/reseller/get-withdrawals')->assertOk();
        foreach (['/isp/reseller-requests', '/isp/reseller-packages', '/isp/reseller-ledger', '/isp/packages'] as $uri) {
            $this->actingAs($this->admin)->withSession(['branch' => $this->branch])->get($uri)->assertOk();
        }
        $this->api('/isp/get-packages', ['owner' => 'company'])->assertOk()->assertJsonMissing(['reseller_id' => $reseller->id]);
        $this->api('/isp/get-reseller-wallets')->assertOk();
        $this->api('/isp/get-reseller-transactions')->assertOk();
        $this->api('/isp/get-reseller-package-requests')->assertOk();
    }
}
