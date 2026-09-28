<?php

namespace Tests\Feature;

use App\Models\Bank;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

// Audit C2: the older controllers only reach records of the current branch, take only the fields
// they list, and refuse related ids (bank, account head...) from another branch.
class BranchIsolationTest extends TestCase
{
    use DatabaseTransactions;

    private Branch $branch;
    private Branch $other;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::firstOrFail();
        $this->other = Branch::forceCreate(['code' => 'B-ISO', 'name' => 'Isolation branch', 'title' => 'Iso', 'status' => 'a']);
        Role::forceCreate(['name' => 'IsoClerk', 'access' => json_encode(['customer', 'bank', 'receive', 'expense', 'accounthead', 'branch']), 'status' => 'a', 'branch_id' => $this->branch->id]);
        $this->staff = User::forceCreate([
            'code' => 'U-iso', 'name' => 'Iso clerk', 'username' => 'iso_clerk', 'email' => 'iso@example.test', 'phone' => '01700000000',
            'role' => 'IsoClerk', 'password' => Hash::make('secret-pass'), 'status' => 'a', 'ipAddress' => '127.0.0.1', 'branch_id' => $this->branch->id,
        ]);
    }

    private function send(string $uri, array $data)
    {
        return $this->actingAs($this->staff)->withSession(['branch' => $this->branch])->postJson($uri, $data);
    }

    private function customer(Branch $branch, string $name): Customer
    {
        return Customer::forceCreate(['code' => 'CI-' . $name, 'name' => $name, 'phone' => '0171' . random_int(1000000, 9999999), 'branch_id' => $branch->id, 'status' => 'a']);
    }

    private function bank(Branch $branch, string $name): Bank
    {
        return Bank::forceCreate(['name' => $name, 'number' => (string) random_int(100000, 999999), 'type' => 'bank', 'bank_name' => $name, 'balance' => 0, 'status' => 'a', 'branch_id' => $branch->id]);
    }

    public function test_a_customer_of_another_branch_can_not_be_edited_moved_or_deleted(): void
    {
        // the audit's proof: editing a branch-32 customer from branch 1 renamed it and moved it into branch 1
        $foreign = $this->customer($this->other, 'Foreign customer');
        $this->send('/update-customer', ['id' => $foreign->id, 'name' => 'Hijacked', 'phone' => '01719999999'])->assertStatus(404);
        $this->send('/delete-customer', ['id' => $foreign->id])->assertStatus(404);
        $foreign->refresh();
        $this->assertSame(['Foreign customer', $this->other->id], [$foreign->name, (int) $foreign->branch_id]);
        $this->assertNull($foreign->deleted_at);
    }

    public function test_only_listed_fields_are_written(): void
    {
        $own = $this->customer($this->branch, 'Own customer');
        $this->send('/update-customer', ['id' => $own->id, 'name' => 'Renamed', 'phone' => $own->phone,
            'ledger_balance' => 999999, 'erased_at' => '2026-01-01 00:00:00', 'code' => 'HACK', 'created_by' => 12345])->assertOk();
        $own->refresh();
        $this->assertSame('Renamed', $own->name);
        $this->assertNotSame('HACK', $own->code);
        $this->assertNull($own->erased_at);
        $this->assertNotEquals(12345, $own->created_by);
        $this->assertNotEquals(999999, (float) $own->ledger_balance);
    }

    public function test_money_records_of_another_branch_are_out_of_reach(): void
    {
        $foreignBank = $this->bank($this->other, 'Foreign bank');
        $this->send('/update-bank', ['id' => $foreignBank->id, 'name' => 'x', 'number' => '1', 'type' => 'bank', 'balance' => 1000000])->assertStatus(404);
        $this->send('/delete-bank', ['id' => $foreignBank->id])->assertStatus(404);
        $this->assertSame(0.0, (float) $foreignBank->fresh()->balance);

        // a receive can't be booked into another branch's bank
        $customer = $this->customer($this->branch, 'Payer');
        $this->send('/receive', ['type' => 'customer', 'customer_id' => $customer->id, 'date' => '2026-09-28', 'amount' => 100,
            'payment_method' => 'bank', 'bank_id' => $foreignBank->id])->assertStatus(422);
        // nor for another branch's customer
        $foreignCustomer = $this->customer($this->other, 'Foreign payer');
        $this->send('/receive', ['type' => 'customer', 'customer_id' => $foreignCustomer->id, 'date' => '2026-09-28', 'amount' => 100, 'payment_method' => 'cash'])->assertStatus(422);
        // the own branch works
        $this->send('/receive', ['type' => 'customer', 'customer_id' => $customer->id, 'date' => '2026-09-28', 'amount' => 100, 'payment_method' => 'cash'])->assertOk();
    }

    public function test_only_head_office_manages_branches(): void
    {
        $this->send('/branch', ['name' => 'Rogue branch'])->assertStatus(403);
        $this->send('/update-branch', ['id' => $this->other->id, 'name' => 'Renamed'])->assertStatus(403);
        $this->send('/delete-branch', ['id' => $this->other->id])->assertStatus(403);
        $this->assertSame('Isolation branch', $this->other->fresh()->name);
    }
}
