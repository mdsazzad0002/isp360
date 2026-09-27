<?php

namespace App\Rules;

use App\Support\Phone;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

// A valid phone number of the company's country, or any valid number written with its "+" code.
class PhoneNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! Phone::isValid((string) $value)) {
            $example = Phone::example();
            $fail("Enter a valid phone number" . ($example ? ", e.g. {$example}" : '') . ', or an international number starting with +.');
        }
    }
}
