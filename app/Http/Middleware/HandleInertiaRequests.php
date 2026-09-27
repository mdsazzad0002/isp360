<?php

namespace App\Http\Middleware;

use App\Support\Money;
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
                'title', 'logo', 'favicon', 'phone', 'email', 'address', 'url', 'multi_branch_status', 'tax_label', 'tax_number',
            ]),
            'appVersion' => config('app.version', '1.0.0'),
            'menuGroups' => fn () => $user ? $menuGroups : [],
            'currentBranch' => fn () => $user ? $request->session()->get('branch') : null,
            'canSwitchBranch' => fn () => $user && in_array($user->role, ['Superadmin', 'admin']),
            'canUserSwitch' => fn () => $user && checkAccess('userSwitch'),
            'canCustomerLoginAs' => fn () => $user && checkAccess('customerLoginAs'),
            'canResellerLoginAs' => fn () => $user && checkAccess('resellerLoginAs'),
            // the company's billing currency, the same for every branch and portal
            'currency' => fn () => Money::currency(),
            // the company's timezone: server times are its wall-clock time, and "today" is its date
            'timezone' => fn () => \App\Support\Region::timezone(),
            // the company's country pack, for forms: phone example, address labels, number/date style
            'region' => function () {
                $pack = \App\Support\CountryPack::current();
                return [
                    'country' => $pack['code'],
                    'calling_code' => $pack['phone']['calling_code'],
                    'phone_example' => \App\Support\Phone::example($pack['code']),
                    'state_label' => $pack['address']['state_label'],
                    'postcode_label' => $pack['address']['postcode_label'],
                    'postcode_required' => (bool) $pack['address']['postcode_required'],
                    'number_locale' => $pack['number_locale'],
                    'date_format' => $pack['date_format'],
                ];
            },
            // default UI language from the country pack; a user's own pick in the switcher wins
            // (in the customer portal: the customer's own language, when set)
            'defaultLocale' => fn () => ($request->is('customer-portal/*') ? Auth::guard('customer')->user()?->language : null) ?: (company()?->language ?? 'en'),
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
