<?php

namespace App\Support;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumber;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

// Phone numbers for any country (libphonenumber), read against the company's country.
// Stored form: a number of the company's country as its national digits (BD "01712345678",
// US "2015550123": what local SMS gateways and people expect, and what BD installs always had);
// any other country's number in E.164 ("+447400123456"). e164() gives the international form
// for providers that need it.
class Phone
{
    private static function util(): PhoneNumberUtil
    {
        return PhoneNumberUtil::getInstance();
    }

    public static function parse(?string $input, ?string $country = null): ?PhoneNumber
    {
        $input = trim((string) $input);
        if ($input === '') {
            return null;
        }
        try {
            $number = self::util()->parse($input, $country ?? Region::countryCode());
            return self::util()->isValidNumber($number) ? $number : null;
        } catch (NumberParseException) {
            return null;
        }
    }

    public static function isValid(?string $input, ?string $country = null): bool
    {
        return self::parse($input, $country) !== null;
    }

    // The stored form, or null when it isn't a valid number.
    public static function normalize(?string $input, ?string $country = null): ?string
    {
        $country ??= Region::countryCode();
        $number = self::parse($input, $country);
        if (! $number) {
            return null;
        }
        if (self::util()->getRegionCodeForNumber($number) === $country) {
            return preg_replace('/\D/', '', self::util()->format($number, PhoneNumberFormat::NATIONAL));
        }
        return self::util()->format($number, PhoneNumberFormat::E164);
    }

    // "+8801712345678", or null when it isn't a valid number.
    public static function e164(?string $input, ?string $country = null): ?string
    {
        $number = self::parse($input, $country);
        return $number ? self::util()->format($number, PhoneNumberFormat::E164) : null;
    }

    // The example number of a country, national form ("01812-345678"), for placeholders.
    public static function example(?string $country = null): string
    {
        $number = self::util()->getExampleNumberForType($country ?? Region::countryCode(), \libphonenumber\PhoneNumberType::MOBILE);
        return $number ? self::util()->format($number, PhoneNumberFormat::NATIONAL) : '';
    }
}
