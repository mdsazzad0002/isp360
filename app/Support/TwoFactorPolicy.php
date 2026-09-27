<?php

namespace App\Support;

// Who must use two-factor login, set company-wide (company_profiles.two_factor_policy).
// Off by default, so current installations log in as before.
class TwoFactorPolicy
{
    public const POLICIES = [
        'off' => 'Optional for everyone',
        'admins' => 'Required for Superadmin and admin users',
        'staff' => 'Required for every staff user',
        'staff_resellers' => 'Required for every staff user and reseller',
    ];

    public const ADMIN_ROLES = ['Superadmin', 'admin'];

    public static function current(): string
    {
        $policy = (string) (company()?->two_factor_policy ?? 'off');
        return array_key_exists($policy, self::POLICIES) ? $policy : 'off';
    }

    // $guard: 'web' (staff) or 'reseller'. Customers are never required.
    public static function requiredFor($account, string $guard, ?string $policy = null): bool
    {
        return match ($policy ?? self::current()) {
            'admins' => $guard === 'web' && in_array($account->role, self::ADMIN_ROLES, true),
            'staff' => $guard === 'web',
            'staff_resellers' => in_array($guard, ['web', 'reseller'], true),
            default => false,
        };
    }
}
