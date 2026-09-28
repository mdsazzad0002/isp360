<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Reseller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

// "Login as reseller": needs the resellerLoginAs permission, reaches only resellers of the
// current branch, and is audited.
class ResellerLoginAsTest extends TestCase
{
    use DatabaseTransactions;

    private function reseller(Branch $branch, string $username): Reseller
    {
        return Reseller::forceCreate(['code' => 'R-' . $username, 'name' => $username, 'phone' => '0171' . random_int(1000000, 9999999),
            'username' => $username, 'password' => Hash::make('secret-pass'), 'status' => 'a', 'branch_id' => $branch->id]);
    }

    public function test_staff_log_in_as_a_reseller_of_their_branch_only(): void
    {
        $branch = Branch::firstOrFail();
        $other = Branch::forceCreate(['code' => 'B-RLA', 'name' => 'Login-as branch', 'title' => 'RLA', 'status' => 'a']);
        $own = $this->reseller($branch, 'rla_own');
        $foreign = $this->reseller($other, 'rla_foreign');
        $admin = User::where('role', 'Superadmin')->firstOrFail();

        $this->actingAs($admin, 'web')->withSession(['branch' => $branch])->get("/reseller/{$foreign->id}/login-as")->assertRedirect('/reseller');
        $this->assertFalse(Auth::guard('reseller')->check());

        $this->actingAs($admin, 'web')->withSession(['branch' => $branch])->get("/reseller/{$own->id}/login-as")->assertRedirect('/reseller/dashboard');
        $this->assertSame($own->id, Auth::guard('reseller')->id());
        $this->assertTrue(AuditLog::where('action', 'reseller.login_as')->where('auditable_id', $own->id)->exists());
    }

    public function test_it_needs_the_permission(): void
    {
        $branch = Branch::firstOrFail();
        $own = $this->reseller($branch, 'rla_perm');
        Role::forceCreate(['name' => 'RlaClerk', 'access' => json_encode(['customer']), 'status' => 'a', 'branch_id' => $branch->id]);
        $clerk = User::forceCreate(['code' => 'U-rla', 'name' => 'Clerk', 'username' => 'rla_clerk', 'email' => 'rla@example.test', 'phone' => '1',
            'role' => 'RlaClerk', 'password' => Hash::make('x'), 'status' => 'a', 'ipAddress' => '127.0.0.1', 'branch_id' => $branch->id]);

        $this->actingAs($clerk, 'web')->withSession(['branch' => $branch])->get("/reseller/{$own->id}/login-as")->assertOk(); // the Forbidden page
        $this->assertFalse(Auth::guard('reseller')->check());
    }
}
