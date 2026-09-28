<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// One row per signed-in browser (login_sessions). The session keeps a random token; its hash is
// the row. Revoking the row signs that browser out on its next request (TrackLoginSession).
class LoginSessions
{
    public const GUARDS = ['web', 'reseller', 'customer'];
    // last_seen_at is written at most this often per session
    private const TOUCH_SECONDS = 300;

    private static function key(string $guard): string
    {
        return "login_session.{$guard}";
    }

    public static function start(Request $request, string $guard, $account): void
    {
        $token = Str::random(40);
        DB::table('login_sessions')->insert([
            'guard' => $guard,
            'account_id' => $account->getKey(),
            'token' => hash('sha256', $token),
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            'last_seen_at' => now(),
            'created_at' => now(),
        ]);
        $request->session()->put(self::key($guard), ['account' => $account->getKey(), 'token' => $token]);
    }

    // false = this browser's session for $guard was revoked (the caller signs it out)
    public static function check(Request $request, string $guard, $account): bool
    {
        $held = $request->session()->get(self::key($guard));
        if (! $held || (int) $held['account'] !== (int) $account->getKey()) {
            // signed in before sessions were tracked, or "Login as": start tracking now
            self::start($request, $guard, $account);
            return true;
        }
        $row = DB::table('login_sessions')->where('token', hash('sha256', $held['token']))->first(['id', 'revoked_at', 'last_seen_at']);
        if (! $row || $row->revoked_at) {
            $request->session()->forget(self::key($guard));
            return false;
        }
        if (! $row->last_seen_at || now()->diffInSeconds($row->last_seen_at, true) >= self::TOUCH_SECONDS) {
            DB::table('login_sessions')->where('id', $row->id)->update(['last_seen_at' => now(), 'ip_address' => $request->ip()]);
        }
        return true;
    }

    public static function end(Request $request, string $guard): void
    {
        if ($held = $request->session()->pull(self::key($guard))) {
            DB::table('login_sessions')->where('token', hash('sha256', $held['token']))->update(['revoked_at' => now()]);
        }
    }

    public static function currentId(Request $request, string $guard): ?int
    {
        $held = $request->session()->get(self::key($guard));
        return $held ? DB::table('login_sessions')->where('token', hash('sha256', $held['token']))->value('id') : null;
    }

    public static function list(string $guard, int $accountId, ?int $currentId): array
    {
        return DB::table('login_sessions')->where('guard', $guard)->where('account_id', $accountId)->whereNull('revoked_at')
            ->orderByDesc('last_seen_at')->limit(50)->get(['id', 'ip_address', 'user_agent', 'last_seen_at', 'created_at'])
            ->map(fn ($s) => (array) $s + ['current' => $s->id === $currentId])->all();
    }

    // Revokes one session, every other one ($exceptId), or all of them; returns how many.
    public static function revoke(string $guard, int $accountId, ?int $id = null, ?int $exceptId = null): int
    {
        return DB::table('login_sessions')->where('guard', $guard)->where('account_id', $accountId)->whereNull('revoked_at')
            ->when($id, fn ($q) => $q->where('id', $id))
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->update(['revoked_at' => now()]);
    }

    // Sessions nobody used for 60 days are only history.
    public static function prune(): int
    {
        return DB::table('login_sessions')->where(fn ($q) => $q->whereNotNull('revoked_at')->orWhere('last_seen_at', '<', now()->subDays(60)))
            ->where('created_at', '<', now()->subDays(60))->delete();
    }

    public static function guardsSignedIn(): array
    {
        return array_values(array_filter(self::GUARDS, fn ($g) => Auth::guard($g)->check()));
    }
}
