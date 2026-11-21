<?php

namespace App\Support\Dev;

/** Exact arithmetic on non-negative plain decimal strings for the Sanad mock (no floats, no bcmath). */
final class StringDecimal
{
    /** @return array{0: string, 1: string} integer digits and fraction digits */
    private static function split(string $value): array
    {
        [$int, $frac] = array_pad(explode('.', $value, 2), 2, '');

        return [ltrim($int, '0') ?: '0', $frac];
    }

    public static function compare(string $a, string $b): int
    {
        [$aInt, $aFrac] = self::split($a);
        [$bInt, $bFrac] = self::split($b);
        $width = max(strlen($aFrac), strlen($bFrac));
        $left = $aInt.str_pad($aFrac, $width, '0');
        $right = $bInt.str_pad($bFrac, $width, '0');

        return strlen($left) <=> strlen($right) ?: strcmp($left, $right) <=> 0;
    }

    public static function multiply(string $a, string $b): string
    {
        [$aInt, $aFrac] = self::split($a);
        [$bInt, $bFrac] = self::split($b);
        $x = str_split(strrev($aInt.$aFrac));
        $y = str_split(strrev($bInt.$bFrac));

        $digits = array_fill(0, count($x) + count($y) + 1, 0);
        foreach ($x as $i => $dx) {
            foreach ($y as $j => $dy) {
                $digits[$i + $j] += (int) $dx * (int) $dy;
            }
        }
        for ($i = 0; $i < count($digits) - 1; $i++) {
            $digits[$i + 1] += intdiv($digits[$i], 10);
            $digits[$i] %= 10;
        }

        $scale = strlen($aFrac) + strlen($bFrac);
        $text = str_pad(strrev(implode('', $digits)), $scale + 1, '0', STR_PAD_LEFT);
        $int = ltrim(substr($text, 0, strlen($text) - $scale), '0') ?: '0';

        return $scale === 0 ? $int : $int.'.'.substr($text, -$scale);
    }

    /** Half-up rounding to $scale fraction digits, padded with zeros. */
    public static function round(string $value, int $scale): string
    {
        [$int, $frac] = self::split($value);
        if (strlen($frac) <= $scale) {
            return $scale === 0 ? $int : $int.'.'.str_pad($frac, $scale, '0');
        }

        $keep = $int.substr($frac, 0, $scale);
        if ((int) $frac[$scale] >= 5) {
            $keep = self::increment($keep);
        }
        $keep = str_pad($keep, $scale + 1, '0', STR_PAD_LEFT);

        return $scale === 0 ? $keep : substr($keep, 0, -$scale).'.'.substr($keep, -$scale);
    }

    private static function increment(string $digits): string
    {
        $chars = str_split($digits);
        for ($i = count($chars) - 1; $i >= 0; $i--) {
            if ($chars[$i] !== '9') {
                $chars[$i] = (string) ((int) $chars[$i] + 1);

                return implode('', $chars);
            }
            $chars[$i] = '0';
        }

        return '1'.implode('', $chars);
    }
}
