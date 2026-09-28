<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Isp\AuditLogger;
use App\Support\LoginSessions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

// "Where you're signed in" for the signed-in staff user, reseller or customer (the route's "guard"
// default says whose): the list, and signing one or all other browsers out.
class LoginSessionController extends Controller
{
    private function guard(Request $request): string
    {
        return $request->route('guard') ?? 'web';
    }

    public function index(Request $request)
    {
        $guard = $this->guard($request);
        $account = Auth::guard($guard)->user();
        return response()->json(LoginSessions::list($guard, $account->id, LoginSessions::currentId($request, $guard)));
    }

    public function revoke(Request $request)
    {
        $guard = $this->guard($request);
        $account = Auth::guard($guard)->user();
        $current = LoginSessions::currentId($request, $guard);
        if ($request->boolean('others')) {
            $count = LoginSessions::revoke($guard, $account->id, null, $current);
        } else {
            $id = (int) $request->id;
            if (!$id || $id === $current) {
                return send_error('Use Log out to end this session', null, 422);
            }
            $count = LoginSessions::revoke($guard, $account->id, $id);
        }
        AuditLogger::log('auth.sessions_revoked', $account, null, ['guard' => $guard, 'count' => $count], null, $account->branch_id ?? null);
        return response()->json(['status' => true, 'message' => $count === 1 ? '1 session signed out' : "{$count} sessions signed out"]);
    }
}
