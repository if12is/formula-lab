<?php

namespace App\Support;

use InvalidArgumentException;

/** Pure string helpers for exact decimals. No float or int arithmetic ever touches a value. */
final class DecimalNormalizer
{
    private const PATTERN = '/^-?\d+(\.\d+)?$/';

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }

    /**
     * Canonical text of a decimal: no trailing fractional zeros, no "-0", no leading zeros.
     * Mirrors what decimal.js prints for plain (non-exponent) values, so PHP and the
     * TS engine hash the same text.
     */
    public static function normalize(string $value): string
    {
        if (! self::isValid($value)) {
            throw new InvalidArgumentException("Not a plain decimal string: '{$value}'");
        }

        $negative = str_starts_with($value, '-');
        [$int, $frac] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');

        $int = ltrim($int, '0');
        $int = $int === '' ? '0' : $int;
        $frac = rtrim($frac, '0');

        $text = $frac === '' ? $int : "{$int}.{$frac}";

        return ($negative && $text !== '0') ? "-{$text}" : $text;
    }
}
