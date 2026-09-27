<?php

namespace App\Http\Middleware;

use App\Support\TwoFactorPolicy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

// Sends a staff user or reseller who must use two-factor login (TwoFactorPolicy) but hasn't set
// it up to the setup page; every other request is refused until they do.
class RequireTwoFactor
{
    public function handle(Request $request, Closure $next)
    {
        [$guard, $setup, $allowed, $impersonator] = $request->is('reseller/*')
            ? ['reseller', '/reseller/two-factor/setup', ['reseller/two-factor/*', 'reseller/logout'], 'reseller_impersonator_id']
            : ['web', '/two-factor/setup', ['two-factor/*', 'logout', 'switch-back'], 'impersonator_id'];

        if ($request->is('customer-portal/*', 'login', 'login/*', 'api/*')) {
            return $next($request);
        }
        $account = Auth::guard($guard)->user();
        // an admin looking at someone else's panel with "Login as" already passed their own 2FA
        if (! $account || $account->hasTwoFactor() || $request->session()->has($impersonator)
            || $request->is(...$allowed) || ! TwoFactorPolicy::requiredFor($account, $guard)) {
            return $next($request);
        }

        $message = 'Set up two-factor login to continue.';
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['status' => false, 'message' => $message, 'two_factor_setup' => $setup], 403);
        }
        return $request->header('X-Inertia') ? \Inertia\Inertia::location($setup) : redirect($setup);
    }
}
