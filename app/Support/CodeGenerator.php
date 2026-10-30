<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Builds technical codes (`[a-z0-9_-]`, at most MAX_LENGTH characters) from display
 * names. Uniqueness is decided by the caller's scope and enforced by the database.
 */
final class CodeGenerator
{
    public const MAX_LENGTH = 50;

    public static function base(string $name, string $fallback): string
    {
        $base = trim(substr(Str::slug($name, '-', 'ru'), 0, self::MAX_LENGTH), '-');

        return $base === '' ? $fallback : $base;
    }

    /**
     * Every candidate for $base starts with this prefix, so one LIKE query finds all
     * codes that can collide with any of them.
     */
    public static function prefix(string $base): string
    {
        return substr($base, 0, self::MAX_LENGTH - 12);
    }

    /**
     * @param  Collection<array-key, mixed>  $taken
     */
    public static function firstFree(string $base, Collection $taken): string
    {
        for ($number = 1; ; $number++) {
            $candidate = self::candidate($base, $number);

            if (! $taken->containsStrict($candidate)) {
                return $candidate;
            }
        }
    }

    public static function candidate(string $base, int $number): string
    {
        if ($number === 1) {
            return $base;
        }

        $suffix = '-'.$number;

        return rtrim(substr($base, 0, self::MAX_LENGTH - strlen($suffix)), '-').$suffix;
    }
}
