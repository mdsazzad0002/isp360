<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = Auth::user();

        $menuGroups = collect(appMenuGroups())
            ->map(function ($group) {
                $group['items'] = collect($group['items'])
                    ->filter(fn ($item) => empty($item['access']) || checkAccess($item['access']))
                    ->values()
                    ->all();
                return $group;
            })
            ->filter(fn ($group) => count($group['items']) > 0)
            ->values()
            ->all();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'role' => $user->role,
                    'image' => $user->image,
                    'actions' => array_filter(explode(',', (string) $user->action)),
                    'is_employee' => (bool) $user->is_employee,
                ] : null,
            ],
            'company' => fn () => optional(company())->only([
                'title', 'logo', 'favicon', 'phone', 'email', 'address', 'url', 'multi_branch_status',
            ]),
            'appVersion' => config('app.version', '1.0.0'),
            'menuGroups' => fn () => $user ? $menuGroups : [],
            'currentBranch' => fn () => $user ? $request->session()->get('branch') : null,
            'canSwitchBranch' => fn () => $user && in_array($user->role, ['Superadmin', 'admin']),
            'canUserSwitch' => fn () => $user && checkAccess('userSwitch'),
            'canCustomerLoginAs' => fn () => $user && checkAccess('customerLoginAs'),
            'canResellerLoginAs' => fn () => $user && checkAccess('resellerLoginAs'),
            'portalUser' => function () use ($request) {
                // pick the guard from the URL, since one browser can hold both portal sessions
                $guard = $request->is('customer-portal/*') ? 'customer' : ($request->is('reseller/*') ? 'reseller' : null);
                $account = $guard ? Auth::guard($guard)->user() : null;
                return $account ? ['type' => $guard, 'name' => $account->name, 'code' => $account->code, 'email' => $account->email] : null;
            },
            // admin used "Login as" to open the portal of the current URL
            'portalImpersonating' => fn () => ($request->is('customer-portal/*') && $request->session()->has('customer_impersonator_id') && Auth::guard('customer')->check())
                || ($request->is('reseller/*') && $request->session()->has('reseller_impersonator_id') && Auth::guard('reseller')->check()),
            'canViewCustomerLedger' => fn () => $user && checkAccess('customerLedger'),
            'impersonating' => fn () => $request->session()->has('impersonator_id'),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
