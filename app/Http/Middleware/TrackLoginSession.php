<?php

namespace App\Http\Middleware;

use App\Support\LoginSessions;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

// Keeps login_sessions current and signs a browser out once its session was revoked (from
// "Where you're signed in", a password change, or an admin deactivating the account).
class TrackLoginSession
{
    private static ?bool $ready = null;

    public function handle(Request $request, Closure $next)
    {
        if (self::$ready ??= Schema::hasTable('login_sessions')) {
            foreach (LoginSessions::guardsSignedIn() as $guard) {
                if (! LoginSessions::check($request, $guard, Auth::guard($guard)->user())) {
                    Auth::guard($guard)->logout();
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                    $message = 'You were signed out. Please log in again.';
                    return $request->expectsJson() && ! $request->header('X-Inertia')
                        ? response()->json(['status' => false, 'message' => $message], 401)
                        : redirect('/')->with('error', $message);
                }
            }
        }
        return $next($request);
    }
}
