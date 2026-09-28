<?php

namespace Tests\Feature;

use App\Models\AccountHead;
use App\Models\Bank;
use App\Models\Branch;
use App\Models\CompanyProfile;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Audit H3: the older cash / bank books and the reports built on them (day book, balance sheet).
// A fresh branch holds a small known set of entries, so every figure below can be checked by hand:
//
//   cash in:  customer 70 (01-05, entered last), customer 500 (01-10), income 50 (01-12), bank withdraw 100 (01-15)
//   cash out: supplier 120 (01-12), expense 30 (01-15), bank deposit 200 (01-15), customer refund 40 (01-15)
//   bank:     opening balance 1000, customer 300 (01-12), deposit 200, withdraw 100
//   → cash 330, bank 1400; customer A has paid 870 in advance, customer B owes 40.
class LegacyReportsTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private Branch $branch;
    private Bank $bank;
    private Customer $alice;
    private Customer $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::where('role', 'Superadmin')->firstOrFail();
        $this->branch = Branch::forceCreate(['code' => 'B-LRT', 'name' => 'Report branch', 'title' => 'Reports', 'status' => 'a']);
        $this->bank = $this->bankIn($this->branch, 1000);
        $this->alice = $this->customerIn($this->branch, 'Alice');
        $this->bob = $this->customerIn($this->branch, 'Bob');
        $head = AccountHead::forceCreate(['name' => 'LRT head', 'type' => 'income', 'status' => 'a', 'branch_id' => $this->branch->id]);

        $b = $this->branch->id;
        $this->receive($b, 'customer', 'cash', 500, '2026-01-10', '2026-01-10 10:00:00', $this->alice->id);
        $this->receive($b, 'customer', 'bank', 300, '2026-01-12', '2026-01-12 09:00:00', $this->alice->id, $this->bank->id);
        $this->payment($b, 'supplier', 'cash', 120, '2026-01-12', '2026-01-12 11:00:00');
        $this->transaction($b, 'income', 50, '2026-01-12', '2026-01-12 12:00:00', $head->id);
        $this->transaction($b, 'expense', 30, '2026-01-15', '2026-01-15 09:00:00', $head->id);
        $this->bankTransaction($b, 'credit', 200, '2026-01-15', '2026-01-15 10:00:00');
        $this->bankTransaction($b, 'debit', 100, '2026-01-15', '2026-01-15 11:00:00');
        $this->payment($b, 'customer', 'cash', 40, '2026-01-15', '2026-01-15 12:00:00', $this->bob->id);
        // back-dated: entered after everything else, but its business date is the earliest
        $this->receive($b, 'customer', 'cash', 70, '2026-01-05', '2026-01-20 08:00:00', $this->alice->id);

        // another branch's money never shows up in this branch's books
        $other = Branch::forceCreate(['code' => 'B-LRT2', 'name' => 'Other report branch', 'title' => 'Other', 'status' => 'a']);
        $otherBank = $this->bankIn($other, 5000);
        $this->receive($other->id, 'customer', 'cash', 9999, '2026-01-12', '2026-01-12 10:00:00', $this->customerIn($other, 'Carol')->id);
        $this->receive($other->id, 'customer', 'bank', 7777, '2026-01-12', '2026-01-12 10:00:00', null, $otherBank->id);
    }

    protected function tearDown(): void
    {
        clearCompanyCache();
        parent::tearDown();
    }

    private function bankIn(Branch $branch, float $balance): Bank
    {
        return Bank::forceCreate(['name' => 'LRT ' . $branch->code, 'number' => (string) random_int(100000, 999999), 'type' => 'bank',
            'bank_name' => 'LRT Bank', 'balance' => $balance, 'status' => 'a', 'branch_id' => $branch->id]);
    }

    private function customerIn(Branch $branch, string $name): Customer
    {
        return Customer::forceCreate(['code' => 'C-LRT-' . $name, 'name' => $name, 'phone' => '0171' . random_int(1000000, 9999999), 'branch_id' => $branch->id, 'status' => 'a']);
    }

    private function row(int $branchId, string $date, string $createdAt, array $data): array
    {
        return $data + ['invoice' => 'LRT-' . random_int(10000, 99999), 'date' => $date, 'status' => 'a', 'created_by' => $this->admin->id,
            'created_at' => $createdAt, 'ipAddress' => '127.0.0.1', 'branch_id' => $branchId];
    }

    private function receive(int $branchId, string $type, string $method, float $amount, string $date, string $createdAt, ?int $customerId = null, ?int $bankId = null): void
    {
        DB::table('receives')->insert($this->row($branchId, $date, $createdAt, ['type' => $type, 'payment_method' => $method, 'amount' => $amount, 'customer_id' => $customerId, 'bank_id' => $bankId]));
    }

    private function payment(int $branchId, string $type, string $method, float $amount, string $date, string $createdAt, ?int $customerId = null): void
    {
        DB::table('payments')->insert($this->row($branchId, $date, $createdAt, ['type' => $type, 'payment_method' => $method, 'amount' => $amount, 'customer_id' => $customerId]));
    }

    private function transaction(int $branchId, string $type, float $amount, string $date, string $createdAt, int $accountId): void
    {
        DB::table('transactions')->insert($this->row($branchId, $date, $createdAt, ['type' => $type, 'amount' => $amount, 'account_id' => $accountId]));
    }

    private function bankTransaction(int $branchId, string $type, float $amount, string $date, string $createdAt): void
    {
        DB::table('bank_transactions')->insert($this->row($branchId, $date, $createdAt, ['type' => $type, 'amount' => $amount, 'bank_id' => $this->bank->id]));
    }

    private function report(string $uri, array $data = [])
    {
        return $this->actingAs($this->admin)->withSession(['branch' => $this->branch])->postJson($uri, $data)->assertOk();
    }

    public function test_the_day_book_balances_and_splits_opening_movement_and_closing(): void
    {
        $book = $this->report('/get-dayBook', ['dateFrom' => '2026-01-12', 'dateTo' => '2026-01-15'])->json();

        $this->assertEquals(570, $book['openingCash']);
        $this->assertEquals(330, $book['closingCash']);
        $this->assertEquals(1000, $book['totalOpeningBank']);
        $this->assertEquals(1400, $book['totalClosingBank']);
        $this->assertEquals(240, $book['paymentCash']);
        $this->assertEquals(400, $book['totalReceipt']);
        $this->assertEquals(240, $book['totalPayment']);
        $this->assertEquals(1970, $book['leftTotal']);
        $this->assertEquals($book['leftTotal'], $book['rightTotal']);
    }

    public function test_the_day_book_drill_downs_add_up_to_the_cells_they_explain(): void
    {
        $range = ['dateFrom' => '2026-01-12', 'dateTo' => '2026-01-15'];

        $closing = $this->report('/get-dayBook-cash-detail', $range + ['section' => 'closing'])->json();
        $this->assertEquals(330, $closing['total']);
        $net = collect($closing['rows'])->sum(fn ($r) => $r['direction'] === 'in' ? $r['amount'] : -$r['amount']);
        $this->assertEquals(330, $net);

        $this->assertEquals(150, collect($this->report('/get-dayBook-cash-detail', $range + ['section' => 'receipt'])->json('rows'))->sum('amount'));
        $this->assertEquals(390, collect($this->report('/get-dayBook-cash-detail', $range + ['section' => 'payment'])->json('rows'))->sum('amount'));

        $bank = $this->report('/get-dayBook-bank-detail', $range + ['section' => 'closing', 'bankId' => $this->bank->id])->json();
        $this->assertEquals(1400, $bank['total']);
        $net = collect($bank['rows'])->sum(fn ($r) => $r['direction'] === 'in' ? $r['amount'] : -$r['amount']);
        $this->assertEquals(1400, $net);
        $this->assertEquals(500, collect($this->report('/get-dayBook-bank-detail', $range + ['section' => 'receipt', 'bankId' => $this->bank->id])->json('rows'))->sum('amount'));
    }

    public function test_the_cash_ledger_runs_by_business_date_so_back_dated_entries_count_in_the_opening(): void
    {
        $ledger = $this->report('/get-cash-ledger', ['dateFrom' => '2026-01-12', 'dateTo' => '2026-01-15'])->json();

        // 70 (01-05, entered last) + 500 (01-10)
        $this->assertEquals(570, $ledger['previousBalance']);
        $rows = collect($ledger['ledgers']);
        $this->assertCount(6, $rows);
        $this->assertTrue($rows->every(fn ($r) => $r['date'] >= '2026-01-12' && $r['date'] <= '2026-01-15'));
        $this->assertEquals(330, $rows->last()['balance']);
        $this->assertEquals($ledger['previousBalance'] + $rows->sum('in_amount') - $rows->sum('out_amount'), $rows->last()['balance']);
    }

    public function test_the_bank_ledger_starts_from_the_account_opening_balance(): void
    {
        $ledger = $this->report('/get-bank-ledger', ['bankId' => $this->bank->id, 'dateFrom' => '2026-01-12', 'dateTo' => '2026-01-15'])->json();

        $this->assertEquals(1000, $ledger['previousBalance']);
        $rows = collect($ledger['ledgers']);
        $this->assertCount(3, $rows);
        $this->assertEquals(1400, $rows->last()['balance']);

        // all banks of the branch: the other branch's 5000 opening and 7777 receipt stay out
        $all = $this->report('/get-bank-ledger', ['dateFrom' => '2026-01-01', 'dateTo' => '2026-01-31'])->json();
        $this->assertEquals(1400, collect($all['ledgers'])->last()['balance']);
    }

    public function test_the_balance_sheet_balances_and_its_details_match_the_summary(): void
    {
        $sheet = $this->report('/get-balanceSheet', ['date' => '2026-01-31'])->json();

        $this->assertEquals(330, $sheet['assets']['cashInHand']);
        $this->assertEquals(1400, $sheet['assets']['totalBank']);
        $this->assertEquals(40, $sheet['assets']['accountsReceivable']);
        $this->assertEquals(1770, $sheet['totalAssets']);
        $this->assertEquals(870, $sheet['liabilities']['customerAdvance']);
        $this->assertEquals(900, $sheet['equity']['retainedEarnings']);
        $this->assertEquals($sheet['totalAssets'], $sheet['totalLiabilitiesAndEquity']);

        $receivable = $this->report('/get-balanceSheet-detail', ['date' => '2026-01-31', 'section' => 'receivable'])->json('rows');
        $this->assertSame(['Bob'], array_column($receivable, 'label'));
        $advance = $this->report('/get-balanceSheet-detail', ['date' => '2026-01-31', 'section' => 'customerAdvance'])->json('rows');
        $this->assertEquals([['Alice', 870]], array_map(fn ($r) => [$r['label'], $r['amount']], $advance));

        // as of a date before any entry: only the bank's opening balance
        $early = $this->report('/get-balanceSheet', ['date' => '2026-01-01'])->json();
        $this->assertEquals([0, 1000, 1000], [$early['assets']['cashInHand'], $early['assets']['totalBank'], $early['totalAssets']]);
    }

    public function test_reports_keep_three_decimal_currencies_exact(): void
    {
        CompanyProfile::query()->update(['currency_code' => 'KWD']);
        clearCompanyCache();
        $this->receive($this->branch->id, 'customer', 'cash', 0.125, '2026-01-15', '2026-01-15 13:00:00', $this->alice->id);

        $book = $this->report('/get-dayBook', ['dateFrom' => '2026-01-12', 'dateTo' => '2026-01-15'])->json();
        $this->assertEquals(330.125, $book['closingCash']);
        $this->assertEquals($book['leftTotal'], $book['rightTotal']);

        $sheet = $this->report('/get-balanceSheet', ['date' => '2026-01-31'])->json();
        $this->assertEquals(870.125, $sheet['liabilities']['customerAdvance']);
        $this->assertEquals($sheet['totalAssets'], $sheet['totalLiabilitiesAndEquity']);
    }

    public function test_a_user_without_the_report_permission_is_refused(): void
    {
        \App\Models\Role::forceCreate(['name' => 'LrtNoReports', 'access' => json_encode(['connection']), 'status' => 'a', 'branch_id' => $this->branch->id]);
        $clerk = User::forceCreate(['code' => 'U-lrt', 'name' => 'Clerk', 'username' => 'lrt_clerk', 'email' => 'lrt@example.test', 'phone' => '01700000000',
            'role' => 'LrtNoReports', 'password' => bcrypt('secret-pass'), 'status' => 'a', 'ipAddress' => '127.0.0.1', 'branch_id' => $this->branch->id]);

        foreach (['/get-dayBook', '/get-balanceSheet', '/get-cash-ledger', '/get-bank-ledger', '/get-dayBook-cash-detail'] as $uri) {
            $this->actingAs($clerk)->withSession(['branch' => $this->branch])->postJson($uri, [])->assertStatus(403);
        }
    }
}
