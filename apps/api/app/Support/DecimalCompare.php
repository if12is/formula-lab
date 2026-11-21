<?php

namespace App\Support;

/** Orders plain decimal strings without float or int arithmetic (bcmath is not required). */
final class DecimalCompare
{
    /**
     * @return int -1, 0 or 1 as $a is smaller than, equal to or greater than $b.
     *
     * @throws \InvalidArgumentException When either value is not a plain decimal string.
     */
    public static function compare(string $a, string $b): int
    {
        $a = DecimalNormalizer::normalize($a);
        $b = DecimalNormalizer::normalize($b);

        if ($a === $b) {
            return 0;
        }

        $negativeA = str_starts_with($a, '-');
        $negativeB = str_starts_with($b, '-');

        if ($negativeA !== $negativeB) {
            return $negativeA ? -1 : 1;
        }

        $magnitude = self::compareMagnitude(ltrim($a, '-'), ltrim($b, '-'));

        return $negativeA ? -$magnitude : $magnitude;
    }

    private static function compareMagnitude(string $a, string $b): int
    {
        [$intA, $fracA] = array_pad(explode('.', $a, 2), 2, '');
        [$intB, $fracB] = array_pad(explode('.', $b, 2), 2, '');

        if (strlen($intA) !== strlen($intB)) {
            return strlen($intA) <=> strlen($intB);
        }

        if ($intA !== $intB) {
            return strcmp($intA, $intB) <=> 0;
        }

        $length = max(strlen($fracA), strlen($fracB));

        return strcmp(str_pad($fracA, $length, '0'), str_pad($fracB, $length, '0')) <=> 0;
    }
}
