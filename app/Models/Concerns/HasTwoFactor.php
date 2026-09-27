<?php

namespace App\Models\Concerns;

use App\Support\Totp;
use Illuminate\Support\Str;

// Two-factor login with an authenticator app (TOTP) plus one-time recovery codes, for staff
// (users) and resellers. The secret and the recovery codes are encrypted at rest.
trait HasTwoFactor
{
    public function initializeHasTwoFactor(): void
    {
        $this->mergeCasts([
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ]);
        $this->makeHidden(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_last_step']);
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret;
    }

    // Starts setup: a new secret, not used at login until confirmed with a code.
    public function startTwoFactor(): string
    {
        $secret = Totp::secret();
        $this->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null, 'two_factor_last_step' => null])->save();
        return $secret;
    }

    // Confirms setup with a code from the app; returns the new recovery codes, or null.
    public function confirmTwoFactor(string $code): ?array
    {
        if (! $this->two_factor_secret || ! $this->acceptTotp($code)) {
            return null;
        }
        $this->two_factor_confirmed_at = now();
        return $this->regenerateRecoveryCodes();
    }

    public function disableTwoFactor(): void
    {
        $this->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null, 'two_factor_last_step' => null])->save();
    }

    public function regenerateRecoveryCodes(): array
    {
        $codes = collect(range(1, 8))->map(fn () => Str::lower(Str::random(5) . '-' . Str::random(5)))->all();
        $this->forceFill(['two_factor_recovery_codes' => $codes])->save();
        return $codes;
    }

    // A code from the app, or an unused recovery code (used up once accepted).
    public function verifyTwoFactor(?string $code, ?string $recoveryCode = null): bool
    {
        if (! $this->hasTwoFactor()) {
            return false;
        }
        if ($recoveryCode !== null && $recoveryCode !== '') {
            $codes = $this->two_factor_recovery_codes ?? [];
            $given = Str::lower(trim($recoveryCode));
            foreach ($codes as $i => $stored) {
                if (hash_equals($stored, $given)) {
                    unset($codes[$i]);
                    $this->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();
                    return true;
                }
            }
            return false;
        }
        return $code !== null && $this->acceptTotp($code);
    }

    public function recoveryCodesLeft(): int
    {
        return count($this->two_factor_recovery_codes ?? []);
    }

    private function acceptTotp(string $code): bool
    {
        $step = Totp::verify($this->two_factor_secret, $code, $this->two_factor_last_step);
        if ($step === null) {
            return false;
        }
        $this->forceFill(['two_factor_last_step' => $step])->save();
        return true;
    }
}
