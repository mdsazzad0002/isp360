<?php

namespace App\Casts;

use App\Support\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

// Like 'decimal:2', but with the company currency's decimals: "600.00" (BDT), "600" (JPY),
// "12.345" (KWD). Values are rounded to the currency before they are stored.
class MoneyCast implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes)
    {
        return $value === null ? null : number_format((float) $value, Money::decimals(), '.', '');
    }

    public function set($model, string $key, $value, array $attributes)
    {
        return $value === null || $value === '' ? $value : number_format(Money::round($value), Money::decimals(), '.', '');
    }
}
