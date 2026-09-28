<?php

namespace App\Support;

use DateTimeZone;

// The company's country and timezone (company_profiles). One installation = one company in one
// country, and the whole app runs in the company's timezone: now(), "today", every stored date and
// time (DATETIME columns hold the company's wall-clock time) and the scheduler.
//
// The MySQL session timezone is left alone on purpose. DATETIME values are not converted, and
// TIMESTAMP values round-trip unchanged under a fixed session zone, so what PHP writes is what it
// reads back. Columns that used MySQL's CURRENT_TIMESTAMP default are stamped from PHP instead
// (Models\Concerns\StampsCreatedAt).
class Region
{
    public const DEFAULT_TIMEZONE = 'Asia/Dhaka';

    public static function timezone(): string
    {
        try {
            $tz = (string) (company()?->timezone ?? '');
        } catch (\Throwable) {
            $tz = ''; // no database yet (fresh install, package discovery)
        }
        return self::isValidTimezone($tz) ? $tz : self::DEFAULT_TIMEZONE;
    }

    public static function countryCode(): string
    {
        $code = (string) (company()?->country_code ?? 'BD');
        return config("countries.$code") ? $code : 'BD';
    }

    // Runs the app in the given (or the company's) timezone. Called at boot.
    public static function apply(?string $timezone = null): void
    {
        $timezone ??= self::timezone();
        config(['app.timezone' => $timezone]);
        date_default_timezone_set($timezone);
    }

    public static function isValidTimezone(string $tz): bool
    {
        return $tz !== '' && in_array($tz, DateTimeZone::listIdentifiers(), true);
    }

    // IANA zones of a country, e.g. BD => ['Asia/Dhaka'], US => ['America/New_York', ...].
    public static function timezonesFor(string $country): array
    {
        if (! preg_match('/^[A-Z]{2}$/', $country)) {
            return []; // not a country code (e.g. a missing form field): no zones, not a crash
        }
        $zones = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $country);
        $main = config("countries.$country.timezone");
        // the country's main zone first
        usort($zones, fn ($a, $b) => ($b === $main) <=> ($a === $main) ?: strcmp($a, $b));
        return $zones;
    }
}
