<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

// Route-level permission check: `access:user` or `access:expense,income` (any of them). The older
// controllers only checked the page permission, so their write endpoints were open to any signed-in
// staff user; every such route now names the permission it needs (routes/web.php).
class RequireAccess
{
    public function handle(Request $request, Closure $next, string ...$names)
    {
        // runs before the controllers' own `auth` middleware: a guest goes to the login page
        if (! $request->user()) {
            throw new AuthenticationException();
        }
        foreach ($names as $name) {
            if (checkAccess($name)) {
                return $next($request);
            }
        }
        if ($request->isMethod('GET') && ! $request->expectsJson()) {
            return \Inertia\Inertia::render('Error/Forbidden')->toResponse($request)->setStatusCode(403);
        }
        return send_error('You are not authorized for this action', null, 403);
    }
}
