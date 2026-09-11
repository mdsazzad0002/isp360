<?php

namespace App\Http\Middleware;

use App\Models\CompanyProfile;
use App\Providers\RouteServiceProvider;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RedirectIfAuthenticated
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @param  string|null  ...$guards
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    // guard => panel home path
    protected $portalHome = [
        'web' => '/panel/dashboard',
        'reseller' => '/reseller/dashboard',
        'customer' => '/customer-portal/dashboard',
    ];

    public function handle(Request $request, Closure $next, ...$guards)
    {
        $guards = empty($guards) ? [null] : $guards;

        foreach ($guards as $guard) {
            if (Auth::guard($guard)->check()) {
                return redirect($this->portalHome[$guard] ?? RouteServiceProvider::HOME);
            }
        }

        $company = CompanyProfile::first();
        if ($company && $company->url != request()->getHost()) {
            $company->url = request()->getHost();
            $company->update();
        }

        return $next($request);
    }
}
