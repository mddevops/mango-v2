<?php

namespace App\Support;

/**
 * The single phone format of the system: eleven digits starting with 7 («79515346783»).
 * Russian «8…», «+7…» and «7…» numbers with spaces, brackets and dashes become the same
 * value. Anything else (short numbers, letters, «+8…», foreign codes) is rejected rather
 * than guessed, so matching always compares complete numbers.
 */
final class Phone
{
    public const INVALID = 'Укажите полный российский номер, например +7 951 534-67-83.';

    public static function normalize(?string $raw): ?string
    {
        $raw = trim((string) $raw);

        if ($raw === '' || preg_match('/^\+?[\d\s()\-]+$/', $raw) !== 1) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $raw) ?? '';

        if (strlen($digits) !== 11) {
            return null;
        }

        if (str_starts_with($raw, '+')) {
            return $digits[0] === '7' ? $digits : null;
        }

        return match ($digits[0]) {
            '7' => $digits,
            '8' => '7'.substr($digits, 1),
            default => null,
        };
    }

    /**
     * A full number typed into a search in any form, also without the leading 7 or 8:
     * «9991234567», «(999) 123-45-67».
     */
    public static function normalizeSearch(?string $raw): ?string
    {
        $phone = self::normalize($raw);

        if ($phone !== null) {
            return $phone;
        }

        $raw = trim((string) $raw);
        $digits = preg_replace('/\D/', '', $raw) ?? '';

        return strlen($digits) === 10 && ! str_starts_with($raw, '+') ? self::normalize('+7'.$raw) : null;
    }

    public static function format(string $phone): string
    {
        if (preg_match('/^7(\d{3})(\d{3})(\d{2})(\d{2})$/', $phone, $parts) !== 1) {
            return $phone;
        }

        return "+7 {$parts[1]} {$parts[2]}-{$parts[3]}-{$parts[4]}";
    }
}
