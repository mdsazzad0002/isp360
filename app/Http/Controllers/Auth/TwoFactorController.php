<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Isp\AuditLogger;
use App\Support\Totp;
use App\Support\TwoFactorPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

// A staff user's or reseller's own two-factor login: set up with an authenticator app, confirm,
// recovery codes, turn off. The route's "guard" default says whose ('web' or 'reseller').
class TwoFactorController extends Controller
{
    private function guard(Request $request): string
    {
        return $request->route('guard') ?? 'web';
    }

    private function account(Request $request)
    {
        return Auth::guard($this->guard($request))->user();
    }

    private function passwordOk(Request $request): bool
    {
        return Hash::check((string) $request->password, $this->account($request)->password);
    }

    private function audit(Request $request, string $action): void
    {
        $account = $this->account($request);
        AuditLogger::log($action, $account, null, ['guard' => $this->guard($request), 'id' => $account->id], null, $account->branch_id);
    }

    // The forced setup page, shown when the company requires 2FA and it isn't set up yet.
    public function setupPage(Request $request)
    {
        return \Inertia\Inertia::render('Auth/TwoFactorSetup', [
            'base' => $this->guard($request) === 'reseller' ? '/reseller/two-factor' : '/two-factor',
            'home' => $this->guard($request) === 'reseller' ? '/reseller/dashboard' : '/panel/dashboard',
            'logout' => $this->guard($request) === 'reseller' ? '/reseller/logout' : '/logout',
        ]);
    }

    public function status(Request $request)
    {
        $account = $this->account($request);
        return response()->json([
            'enabled' => $account->hasTwoFactor(),
            'required' => TwoFactorPolicy::requiredFor($account, $this->guard($request)),
            'recovery_codes_left' => $account->hasTwoFactor() ? $account->recoveryCodesLeft() : 0,
        ]);
    }

    // Step 1: a new secret and its QR link. Not used at login until confirmed.
    public function enable(Request $request)
    {
        if (! $this->passwordOk($request)) {
            return send_error('Validation Error', ['password' => 'The password is not correct'], 422);
        }
        $account = $this->account($request);
        if ($account->hasTwoFactor()) {
            return send_error('Two-factor login is already on. Turn it off first to use a new device.', null, 422);
        }
        $secret = $account->startTwoFactor();
        $issuer = company()?->title ?: config('app.name');
        return response()->json(['status' => true, 'secret' => $secret, 'uri' => Totp::uri($secret, $account->username ?: $account->email, $issuer)]);
    }

    // Step 2: a code from the app proves it was set up; the recovery codes are shown once.
    public function confirm(Request $request)
    {
        $account = $this->account($request);
        if ($account->hasTwoFactor()) {
            return send_error('Two-factor login is already on.', null, 422);
        }
        $codes = $account->confirmTwoFactor((string) $request->code);
        if ($codes === null) {
            return send_error('Validation Error', ['code' => 'The code is not correct. Check the time on your phone and try the newest code.'], 422);
        }
        $this->audit($request, 'auth.two_factor_enabled');
        return response()->json(['status' => true, 'message' => 'Two-factor login is on.', 'recovery_codes' => $codes]);
    }

    public function recoveryCodes(Request $request)
    {
        $account = $this->account($request);
        if (! $account->hasTwoFactor()) {
            return send_error('Two-factor login is off.', null, 422);
        }
        if (! $this->passwordOk($request)) {
            return send_error('Validation Error', ['password' => 'The password is not correct'], 422);
        }
        $codes = $account->regenerateRecoveryCodes();
        $this->audit($request, 'auth.two_factor_recovery_codes');
        return response()->json(['status' => true, 'message' => 'New recovery codes made. The old ones no longer work.', 'recovery_codes' => $codes]);
    }

    public function disable(Request $request)
    {
        $account = $this->account($request);
        if (TwoFactorPolicy::requiredFor($account, $this->guard($request))) {
            return send_error('Two-factor login is required for your account and can\'t be turned off.', null, 422);
        }
        if (! $this->passwordOk($request)) {
            return send_error('Validation Error', ['password' => 'The password is not correct'], 422);
        }
        $account->disableTwoFactor();
        $this->audit($request, 'auth.two_factor_disabled');
        return response()->json(['status' => true, 'message' => 'Two-factor login is off.']);
    }
}
