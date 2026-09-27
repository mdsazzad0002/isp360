<?php

namespace App\Support;

// Time-based one-time passwords (RFC 6238: HMAC-SHA1, 6 digits, 30-second steps), the codes
// authenticator apps (Google Authenticator, Microsoft Authenticator, Authy, 1Password) show.
class Totp
{
    public const DIGITS = 6;
    public const PERIOD = 30;
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    // A new random secret, base32 (160 bits, as RFC 4226 recommends).
    public static function secret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function step(?int $time = null): int
    {
        return intdiv($time ?? now()->getTimestamp(), self::PERIOD);
    }

    public static function code(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), self::base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0f;
        $number = ((ord($hash[$offset]) & 0x7f) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
        return str_pad((string) ($number % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    // The step the code belongs to (now, or one step either side for clock drift), or null.
    // Steps at or before $afterStep are refused, so an accepted code can't be replayed.
    public static function verify(string $secret, string $code, ?int $afterStep = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code);
        if (! preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return null;
        }
        $now = self::step();
        foreach ([$now, $now - 1, $now + 1] as $step) {
            if (($afterStep === null || $step > $afterStep) && hash_equals(self::code($secret, $step), $code)) {
                return $step;
            }
        }
        return null;
    }

    // otpauth:// link for the QR code an authenticator app scans.
    public static function uri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);
        return "otpauth://totp/$label?" . http_build_query(['secret' => $secret, 'issuer' => $issuer, 'digits' => self::DIGITS, 'period' => self::PERIOD]);
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function base32Decode(string $text): string
    {
        $text = strtoupper(preg_replace('/[\s=]+/', '', $text));
        $bits = '';
        foreach (str_split($text) as $c) {
            $i = strpos(self::ALPHABET, $c);
            if ($i === false) {
                throw new \InvalidArgumentException('Invalid base32 secret');
            }
            $bits .= str_pad(decbin($i), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }
}
