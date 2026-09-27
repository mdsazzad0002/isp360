<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use App\Models\Branch;
use App\Models\Reseller;
use App\Models\Customer;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Services\Isp\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

class LoginController extends Controller
{
    // guard => [model class, redirect path once logged in]
    protected $portals = [
        'web' => [User::class, '/panel/dashboard'],
        'reseller' => [Reseller::class, '/reseller/dashboard'],
        'customer' => [Customer::class, '/customer-portal/dashboard'],
    ];

    // login-page tab => guard
    protected $tabs = ['admin' => 'web', 'reseller' => 'reseller', 'customer' => 'customer'];

    protected $notFound = [
        'web' => 'No admin account found with this username',
        'reseller' => 'No reseller account found with this username',
        'customer' => 'No customer account found with this username',
    ];

    public function __construct()
    {
        $this->middleware('guest:web,reseller,customer')->except('logout');
    }

    public function showLoginForm()
    {
        return \Inertia\Inertia::render('Auth/Login');
    }

    // Failed passwords per username + IP before the account is locked for LOCK_SECONDS on that IP.
    public const MAX_ATTEMPTS = 5;
    public const LOCK_SECONDS = 900;
    // A second step (two-factor code) must follow the password within this time.
    public const TWO_FACTOR_SECONDS = 300;

    public function login(Request $request)
    {
        $this->validate($request, [
            "username" => "required",
            "password" => "required",
            "portal" => "nullable|in:admin,reseller,customer",
        ], ['username.required' => 'Username is required', 'password.required' => 'Password is required']);

        $key = 'login:' . Str::lower($request->username) . '|' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return $this->locked(RateLimiter::availableIn($key));
        }

        try {
            $only = $request->portal ? $this->tabs[$request->portal] : null;
            [$guard, $account] = $this->findAccount($request->username, $only);
            if (empty($account)) {
                RateLimiter::hit($key, self::LOCK_SECONDS);
                return send_error("Unauthorized", ['username' => $only ? $this->notFound[$only] : 'User not found'], 401);
            }
            if ($account->status == 'p') {
                return send_error("Unauthorized", ['username' => 'User Deactive'], 401);
            }

            if (! Auth::guard($guard)->validate(credentials($request->username, $request->password))) {
                RateLimiter::hit($key, self::LOCK_SECONDS);
                if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
                    AuditLogger::log('auth.login_locked', $account, null, ['guard' => $guard, 'ip' => $request->ip()], null, $account->branch_id ?? null);
                }
                return send_error("Unauthorized", ['username' => 'Username or password not valid'], 401);
            }
            RateLimiter::clear($key);

            // the password is right; staff and resellers with 2FA on still need a code from their app
            if (method_exists($account, 'hasTwoFactor') && $account->hasTwoFactor()) {
                Session::put('login.two_factor', ['guard' => $guard, 'id' => $account->id, 'at' => now()->getTimestamp()]);
                return response()->json(['status' => true, 'two_factor' => true, 'message' => 'Enter the code from your authenticator app']);
            }
            return $this->completeLogin($guard, $account);
        } catch (\Throwable $th) {
            return send_error("Something went wrong", $th->getMessage());
        }
    }

    // Second step of a login with 2FA: a code from the authenticator app, or a recovery code.
    public function twoFactor(Request $request)
    {
        $pending = Session::get('login.two_factor');
        if (! $pending || now()->getTimestamp() - $pending['at'] > self::TWO_FACTOR_SECONDS) {
            Session::forget('login.two_factor');
            return send_error('Your login timed out. Enter your password again.', ['code' => 'Login timed out'], 401);
        }
        $account = $this->portals[$pending['guard']][0]::find($pending['id']);
        if (! $account || $account->status == 'p') {
            Session::forget('login.two_factor');
            return send_error("Unauthorized", ['code' => 'User Deactive'], 401);
        }

        $key = "two-factor:{$pending['guard']}:{$account->id}";
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return $this->locked(RateLimiter::availableIn($key), 'code');
        }
        if (! $account->verifyTwoFactor($request->code, $request->recovery_code)) {
            RateLimiter::hit($key, self::LOCK_SECONDS);
            return send_error('Validation Error', ['code' => $request->filled('recovery_code') ? 'That recovery code is not valid or was already used' : 'The code is not correct'], 422);
        }
        RateLimiter::clear($key);
        Session::forget('login.two_factor');
        if ($request->filled('recovery_code')) {
            AuditLogger::log('auth.recovery_code_used', $account, null, ['left' => $account->recoveryCodesLeft()], null, $account->branch_id ?? null);
        }
        return $this->completeLogin($pending['guard'], $account);
    }

    protected function completeLogin(string $guard, $account)
    {
        Auth::guard($guard)->login($account);
        Session::put('portal', $guard);
        if ($guard === 'web') {
            $this->branchset();
        }
        Session::flash('success', 'Login successfully');
        return response()->json(['status' => true, 'message' => "Successfully Login", 'redirect' => $this->portals[$guard][1]]);
    }

    protected function locked(int $seconds, string $field = 'username')
    {
        $minutes = max(1, (int) ceil($seconds / 60));
        return send_error('Too many attempts', [$field => "Too many failed attempts. Try again in $minutes minute" . ($minutes > 1 ? 's' : '') . '.'], 429);
    }

    // find which of the 3 portals (user / reseller / customer) a username (or email) belongs to;
    // $only limits the search to one guard when the user picked a tab on the login page
    protected function findAccount($username, $only = null)
    {
        $column = array_key_first(credentials($username, ''));
        foreach ($this->portals as $guard => [$model, $redirect]) {
            if ($only && $guard !== $only) {
                continue;
            }
            $account = $model::where($column, $username)->first();
            if ($account) {
                return [$guard, $account];
            }
        }

        return [null, null];
    }

    // branch set on session
    protected function branchset()
    {
        $user = Auth::user();

        $module = "dashboard";
        $branch = Branch::find($user->branch_id);
        Session::put('branch', $branch);
        Session::put('module', $module);

        // UserActivity::create([
        //     'user_id' => $user->id,
        //     'ip_address' => request()->ip(),
        //     'login_time' => Carbon::now(),
        //     'branch_id' => $user->branch_id,
        // ]);
        // return true;
    }
}
