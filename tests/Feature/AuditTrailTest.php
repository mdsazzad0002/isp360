<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Bank;
use App\Models\Branch;
use App\Models\SmsGateway;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

// Branches and the older accounting records (banks, bank transactions, income/expense, receives,
// payments, account heads) and SMS gateways write every change to the audit log (Concerns\Audited).
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

    public function test_sms_gateway_changes_are_audited_without_the_api_key(): void
    {
        $gateway = ['name' => 'Audit SMS', 'provider_type' => 'mram', 'api_key' => 'SECRET-KEY-111', 'sender_id' => 'ISP', 'sms_type' => 'text', 'label' => 'transactional'];
        $this->send('/sms-gateway', $gateway)->assertOk();
        $row = SmsGateway::where('name', 'Audit SMS')->firstOrFail();
        $this->send('/update-sms-gateway', ['id' => $row->id, 'api_key' => 'SECRET-KEY-222', 'sender_id' => 'ISP2'] + $gateway)->assertOk();
        $this->send('/delete-sms-gateway', ['id' => $row->id])->assertOk();

        $logs = AuditLog::where('auditable_type', 'SmsGateway')->where('auditable_id', $row->id)->orderBy('id')->get();
        $this->assertSame('sms_gateway.created', $logs->first()->action);
        $this->assertSame('sms_gateway.deleted', $logs->last()->action);
        $update = $logs->first(fn ($l) => ($l->new_values['sender_id'] ?? null) === 'ISP2');
        $this->assertSame(['ISP', '[secret]', '[secret]'], [$update->old_values['sender_id'], $update->old_values['api_key'], $update->new_values['api_key']]);

        // the key never reaches the audit log, in plain text or encrypted
        $stored = $logs->map(fn ($l) => json_encode([$l->old_values, $l->new_values]))->implode(' ');
        $this->assertStringNotContainsString('SECRET-KEY', $stored);
        $this->assertStringNotContainsString('eyJpdiI', $stored);
    }
}
