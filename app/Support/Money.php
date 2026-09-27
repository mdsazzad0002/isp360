<?php

namespace App\Support;

use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\LedgerEntry;

// The company's billing currency (company_profiles.currency_code, see config/currencies.php)
// and how amounts are printed in it. One installation = one company in one country, so every
// branch bills in this currency. Every user-facing amount outside the Vue pages goes through format().
class Money
{
    public const DEFAULT = 'BDT';

    // Memoised per request; clearCompanyCache() resets it.
    private static ?array $current = null;

    public static function code(): string
    {
        return self::currency()['code'];
    }

    // ['code' => 'BDT', 'name' => ..., 'symbol' => 'Tk', 'decimals' => 2]
    public static function currency(): array
    {
        if (self::$current === null) {
            $code = (string) (company()?->currency_code ?? self::DEFAULT);
            $code = config("currencies.$code") ? $code : self::DEFAULT;
            self::$current = ['code' => $code] + config("currencies.$code");
        }
        return self::$current;
    }

    public static function flush(): void
    {
        self::$current = null;
    }

    // Digits after the point: 2 (BDT, USD), 0 (JPY), 3 (KWD). Money columns store up to STORAGE_DECIMALS.
    public const STORAGE_DECIMALS = 3;

    public static function decimals(): int
    {
        return (int) self::currency()['decimals'];
    }

    // Rounds to the currency's smallest unit. Every stored or compared amount goes through this.
    public static function round($amount): float
    {
        return round((float) $amount, self::decimals());
    }

    // The smallest unit: 0.01, 1 (JPY), 0.001 (KWD).
    public static function unit(): float
    {
        return 1 / (10 ** self::decimals());
    }

    // True when two amounts are the same once rounded to the currency.
    public static function equals($a, $b): bool
    {
        return abs(self::round($a) - self::round($b)) < self::unit() / 2;
    }

    // "Tk 1,200.00", "$1,200.00", "-Tk 50.00".
    public static function format($amount): string
    {
        $currency = self::currency();
        $amount = (float) $amount;
        $symbol = $currency['symbol'];
        $space = preg_match('/\p{L}$/u', $symbol) ? ' ' : '';
        return ($amount < 0 ? '-' : '') . $symbol . $space . number_format(abs($amount), $currency['decimals']);
    }

    // The number alone, "1,200.00", for SMS templates that print {currency} themselves.
    public static function number($amount): string
    {
        return number_format((float) $amount, self::currency()['decimals']);
    }

    // Every stored amount is in the current currency, so it is fixed once any money is recorded.
    public static function locked(): bool
    {
        return Invoice::query()->exists() || CustomerPayment::query()->exists() || LedgerEntry::query()->exists();
    }
}
