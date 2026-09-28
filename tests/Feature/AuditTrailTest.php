<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Bank;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

// Branches and the older accounting records (banks, bank transactions, income/expense, receives,
// payments, account heads) write every change to the audit log (Concerns\Audited).
class AuditTrailTest extends TestCase
{
    use DatabaseTransactions;

    private function send(string $uri, array $data)
    {
        return $this->actingAs(User::where('role', 'Superadmin')->firstOrFail())
            ->withSession(['branch' => Branch::firstOrFail()])->postJson($uri, $data);
    }

    public function test_bank_changes_are_audited_with_old_and_new_values(): void
    {
        $this->send('/bank', ['name' => 'Audit bank', 'number' => '778899', 'type' => 'bank', 'bank_name' => 'Audit bank', 'balance' => 100])->assertOk();
        $bank = Bank::where('number', '778899')->firstOrFail();
        $this->send('/update-bank', ['id' => $bank->id, 'name' => 'Audit bank', 'number' => '778899', 'type' => 'bank', 'bank_name' => 'Audit bank', 'balance' => 250])->assertOk();
        $this->send('/delete-bank', ['id' => $bank->id])->assertOk();

        $logs = AuditLog::where('auditable_type', 'Bank')->where('auditable_id', $bank->id)->orderBy('id')->get();
        $this->assertSame(['bank.created', 'bank.updated', 'bank.updated', 'bank.deleted'], $logs->pluck('action')->all());
        $this->assertEquals(100, $logs[1]->old_values['balance']);
        $this->assertEquals(250, $logs[1]->new_values['balance']);
        $this->assertNotNull($logs[0]->user_id);
    }

    public function test_branch_changes_are_audited(): void
    {
        $this->send('/branch', ['name' => 'Audited branch'])->assertOk();
        $branch = Branch::where('name', 'Audited branch')->firstOrFail();
        $this->send('/update-branch', ['id' => $branch->id, 'name' => 'Audited branch 2'])->assertOk();
        $this->assertSame(['branch.created', 'branch.updated'], AuditLog::where('auditable_type', 'Branch')->where('auditable_id', $branch->id)->orderBy('id')->pluck('action')->all());
    }
}
