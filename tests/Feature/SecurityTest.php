<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CompanyProfile;
use App\Models\Reseller;
use App\Models\SmsGateway;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

// Login lockout, two-factor login (TOTP + recovery codes) for staff and resellers, the company
// 2FA policy, and SMS gateway secrets encrypted at rest.
class SecurityTest extends TestCase
{
    use DatabaseTransactions;

    private Branch $branch;
    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::firstOrFail();
        $this->superadmin = User::where('role', 'Superadmin')->firstOrFail();
        CompanyProfile::query()->update(['two_factor_policy' => 'off']);
        clearCompanyCache();
        Carbon::setTestNow('2026-09-28 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        clearCompanyCache();
        parent::tearDown();
    }

    private function user(string $role = 'admin', string $username = 'sec_admin'): User
    {
        return User::forceCreate([
            'code' => 'U-' . $username, 'name' => 'Security ' . $role, 'username' => $username, 'email' => "$username@example.test",
            'phone' => '01700000000', 'role' => $role, 'password' => Hash::make('secret-pass'), 'status' => 'a',
            'ipAddress' => '127.0.0.1', 'branch_id' => $this->branch->id,
        ]);
    }

    private function reseller(): Reseller
    {
        return Reseller::forceCreate([
            'code' => 'R-SEC', 'name' => 'Security reseller', 'phone' => '01700000001', 'username' => 'sec_reseller',
            'password' => Hash::make('secret-pass'), 'status' => 'a', 'branch_id' => $this->branch->id,
        ]);
    }

    private function login(string $username, string $password, string $portal = 'admin')
    {
        return $this->postJson('/login', compact('username', 'password', 'portal'));
    }

    // Turns 2FA on through the API and returns [secret, recovery codes].
    private function enableTwoFactor($account, string $base = '/two-factor', string $guard = 'web'): array
    {
        $secret = $this->actingAs($account, $guard)->postJson("$base/enable", ['password' => 'secret-pass'])->assertOk()->json('secret');
        $this->actingAs($account, $guard)->postJson("$base/confirm", ['code' => '000000'])->assertStatus(422);
        $codes = $this->actingAs($account, $guard)->postJson("$base/confirm", ['code' => Totp::code($secret, Totp::step())])->assertOk()->json('recovery_codes');
        $this->assertCount(8, $codes);
        Auth::guard($guard)->logout();
        Carbon::setTestNow(now()->addSeconds(Totp::PERIOD)); // the confirm code is used up
        return [$secret, $codes];
    }

    public function test_totp_matches_the_rfc_6238_test_vectors(): void
    {
        $secret = Totp::base32Encode('12345678901234567890');
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $secret);
        foreach ([59 => '287082', 1111111109 => '081804', 1234567890 => '005924', 2000000000 => '279037'] as $time => $code) {
            $this->assertSame($code, Totp::code($secret, Totp::step($time)));
        }
        // one step of clock drift either way, never an already used step
        $step = Totp::step();
        $this->assertSame($step - 1, Totp::verify($secret, Totp::code($secret, $step - 1)));
        $this->assertNull(Totp::verify($secret, Totp::code($secret, $step), $step));
        $this->assertNull(Totp::verify($secret, Totp::code($secret, $step - 2)));
        $this->assertNull(Totp::verify($secret, 'abcdef'));
    }

    public function test_repeated_wrong_passwords_lock_the_username(): void
    {
        $this->user();
        for ($i = 0; $i < 5; $i++) {
            $this->login('sec_admin', 'wrong')->assertStatus(401);
        }
        // locked: even the right password is refused
        $this->login('sec_admin', 'secret-pass')->assertStatus(429)->assertJsonPath('errors.username', 'Too many failed attempts. Try again in 15 minutes.');
        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login_locked']);

        // the lock ends after 15 minutes
        Carbon::setTestNow(now()->addMinutes(16));
        $this->login('sec_admin', 'secret-pass')->assertOk()->assertJsonPath('redirect', '/panel/dashboard');
        $this->assertAuthenticated('web');
    }

    public function test_staff_login_with_two_factor(): void
    {
        $user = $this->user();
        [$secret, $codes] = $this->enableTwoFactor($user);
        $this->assertNotSame($secret, DB::table('users')->where('id', $user->id)->value('two_factor_secret')); // encrypted at rest
        $this->assertArrayNotHasKey('two_factor_secret', $user->fresh()->toArray());

        // the password alone doesn't log in
        $this->login('sec_admin', 'secret-pass')->assertOk()->assertJsonPath('two_factor', true);
        $this->assertGuest('web');
        $this->postJson('/login/two-factor', ['code' => '000000'])->assertStatus(422);
        $this->assertGuest('web');
        $code = Totp::code($secret, Totp::step());
        $this->postJson('/login/two-factor', ['code' => $code])->assertOk()->assertJsonPath('redirect', '/panel/dashboard');
        $this->assertAuthenticatedAs($user, 'web');

        // the same code can't be used again
        Auth::guard('web')->logout();
        $this->login('sec_admin', 'secret-pass')->assertJsonPath('two_factor', true);
        $this->postJson('/login/two-factor', ['code' => $code])->assertStatus(422);

        // a recovery code works once
        $this->postJson('/login/two-factor', ['recovery_code' => strtoupper($codes[0])])->assertOk();
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertSame(7, $user->fresh()->recoveryCodesLeft());
        Auth::guard('web')->logout();
        $this->login('sec_admin', 'secret-pass')->assertJsonPath('two_factor', true);
        $this->postJson('/login/two-factor', ['recovery_code' => $codes[0]])->assertStatus(422);
        $this->assertGuest('web');
    }

    public function test_the_code_step_times_out_and_locks_after_wrong_codes(): void
    {
        $user = $this->user();
        [$secret] = $this->enableTwoFactor($user);

        $this->login('sec_admin', 'secret-pass')->assertJsonPath('two_factor', true);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/login/two-factor', ['code' => '000000'])->assertStatus(422);
        }
        $this->postJson('/login/two-factor', ['code' => Totp::code($secret, Totp::step())])->assertStatus(429);
        $this->assertGuest('web');

        // no second step without a fresh password
        $this->postJson('/login/two-factor', ['code' => '123456'])->assertStatus(429);
        Carbon::setTestNow(now()->addMinutes(20));
        $this->postJson('/login/two-factor', ['code' => Totp::code($secret, Totp::step())])->assertStatus(401);
    }

    public function test_turning_two_factor_off_and_new_recovery_codes_need_the_password(): void
    {
        $user = $this->user();
        [, $codes] = $this->enableTwoFactor($user);
        $this->actingAs($user)->postJson('/two-factor/recovery-codes', ['password' => 'wrong'])->assertStatus(422);
        $new = $this->actingAs($user)->postJson('/two-factor/recovery-codes', ['password' => 'secret-pass'])->assertOk()->json('recovery_codes');
        $this->assertEmpty(array_intersect($codes, $new));

        $this->actingAs($user)->postJson('/two-factor/disable', ['password' => 'wrong'])->assertStatus(422);
        $this->actingAs($user)->postJson('/two-factor/disable', ['password' => 'secret-pass'])->assertOk();
        $this->assertFalse($user->fresh()->hasTwoFactor());
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.two_factor_disabled']);
    }

    public function test_the_company_policy_sends_staff_to_set_up_two_factor(): void
    {
        $admin = $this->user();
        $staff = $this->user('Manager', 'sec_staff');

        // requiring it needs the admin's own 2FA first
        $settings = $this->actingAs($this->superadmin)->withSession(['branch' => $this->branch])->postJson('/isp/get-settings')->assertOk()->json();
        $settings['two_factor_policy'] = 'admins';
        $this->actingAs($this->superadmin)->withSession(['branch' => $this->branch])->postJson('/isp/settings', $settings)->assertStatus(422);
        $this->assertSame('off', CompanyProfile::first()->two_factor_policy);

        CompanyProfile::query()->update(['two_factor_policy' => 'admins']);
        clearCompanyCache();
        $session = ['branch' => $this->branch];
        $this->actingAs($admin)->withSession($session)->get('/panel/dashboard')->assertRedirect('/two-factor/setup');
        $this->actingAs($admin)->withSession($session)->postJson('/isp/get-settings')->assertStatus(403)->assertJsonPath('two_factor_setup', '/two-factor/setup');
        $this->actingAs($admin)->withSession($session)->get('/two-factor/setup')->assertOk();
        $this->actingAs($admin)->withSession($session)->postJson('/two-factor/status')->assertOk()->assertJsonPath('required', true);
        // other roles are not covered by "admins"
        $this->actingAs($staff)->withSession($session)->get('/panel/dashboard')->assertOk();

        $this->enableTwoFactor($admin);
        $this->actingAs($admin)->withSession($session)->get('/panel/dashboard')->assertOk();
        // required accounts can't turn it off
        $this->actingAs($admin)->withSession($session)->postJson('/two-factor/disable', ['password' => 'secret-pass'])->assertStatus(422);

        CompanyProfile::query()->update(['two_factor_policy' => 'staff']);
        clearCompanyCache();
        $this->actingAs($staff)->withSession($session)->get('/panel/dashboard')->assertRedirect('/two-factor/setup');
        // an admin using "Login as" already passed their own 2FA
        $this->actingAs($staff)->withSession($session + ['impersonator_id' => $admin->id])->get('/panel/dashboard')->assertOk();
    }

    public function test_resellers_use_two_factor_and_the_policy_can_require_it(): void
    {
        $reseller = $this->reseller();
        CompanyProfile::query()->update(['two_factor_policy' => 'staff']);
        clearCompanyCache();
        $this->actingAs($reseller, 'reseller')->get('/reseller/dashboard')->assertOk();

        CompanyProfile::query()->update(['two_factor_policy' => 'staff_resellers']);
        clearCompanyCache();
        $this->actingAs($reseller, 'reseller')->get('/reseller/dashboard')->assertRedirect('/reseller/two-factor/setup');

        [$secret] = $this->enableTwoFactor($reseller, '/reseller/two-factor', 'reseller');
        $this->actingAs($reseller, 'reseller')->get('/reseller/dashboard')->assertOk();
        Auth::guard('reseller')->logout();

        $this->login('sec_reseller', 'secret-pass', 'reseller')->assertJsonPath('two_factor', true);
        $this->assertGuest('reseller');
        $this->postJson('/login/two-factor', ['code' => Totp::code($secret, Totp::step())])->assertOk()->assertJsonPath('redirect', '/reseller/dashboard');
        $this->assertAuthenticatedAs($reseller, 'reseller');
    }

    public function test_sms_gateway_keys_are_encrypted_and_never_sent_back(): void
    {
        $session = ['branch' => $this->branch];
        $this->actingAs($this->superadmin)->withSession($session)->postJson('/sms-gateway', [
            'name' => 'Sec SMS', 'provider_type' => 'mram', 'api_key' => 'KEY-12345', 'sender_id' => 'ISP', 'sms_type' => 'text', 'label' => 'transactional',
        ])->assertOk();
        $gateway = SmsGateway::where('name', 'Sec SMS')->firstOrFail();
        $this->assertSame('KEY-12345', $gateway->api_key);
        $this->assertNotSame('KEY-12345', DB::table('sms_gateways')->where('id', $gateway->id)->value('api_key'));

        $row = collect($this->actingAs($this->superadmin)->withSession($session)->postJson('/get-sms-gateway')->assertOk()->json())->firstWhere('id', $gateway->id);
        $this->assertArrayNotHasKey('api_key', $row);
        $this->assertTrue($row['has_api_key']);

        // editing without a key keeps the saved one
        $this->actingAs($this->superadmin)->withSession($session)->postJson('/update-sms-gateway', [
            'id' => $gateway->id, 'name' => 'Sec SMS 2', 'provider_type' => 'mram', 'api_key' => '', 'sender_id' => 'ISP', 'sms_type' => 'text', 'label' => 'transactional',
        ])->assertOk();
        $this->assertSame('KEY-12345', $gateway->fresh()->api_key);
        $this->assertSame('Sec SMS 2', $gateway->fresh()->name);

        // users without SMS settings access can't read the list
        $this->actingAs($this->user('Collector', 'sec_collector'))->withSession($session)->postJson('/get-sms-gateway')->assertStatus(403);
    }
}
