<?php

namespace App\Support;

use InvalidArgumentException;
use JsonException;
use stdClass;

/**
 * Canonical JSON, byte-identical to canonicalJson() in packages/engine/src/hash.ts:
 * object keys sorted recursively (UTF-16 code-unit order, like JS), no whitespace,
 * strings encoded like JSON.stringify, and numbers forbidden (decimals travel as strings).
 * Lists are PHP lists; objects are associative arrays or stdClass.
 */
final class CanonicalJson
{
    private const STRING_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR;

    public static function encode(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_string($value)) {
            return self::encodeString($value);
        }
        if (is_int($value) || is_float($value)) {
            throw new InvalidArgumentException('CanonicalJson: numbers are not allowed, use decimal strings');
        }
        if (is_array($value) && array_is_list($value)) {
            return '['.implode(',', array_map(self::encode(...), $value)).']';
        }
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
        }
        if (! is_array($value)) {
            throw new InvalidArgumentException('CanonicalJson: unsupported type '.get_debug_type($value));
        }

        // Keep (key, value) pairs: PHP would turn numeric-string keys back into ints.
        $pairs = [];
        foreach ($value as $key => $member) {
            $pairs[] = [(string) $key, $member];
        }
        usort($pairs, fn (array $x, array $y): int => self::compareUtf16($x[0], $y[0]));

        return '{'.implode(',', array_map(
            fn (array $pair): string => self::encodeString($pair[0]).':'.self::encode($pair[1]),
            $pairs,
        )).'}';
    }

    public static function hash(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }

    private static function encodeString(string $text): string
    {
        try {
            return json_encode($text, self::STRING_FLAGS);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('CanonicalJson: string is not valid UTF-8', 0, $e);
        }
    }

    /** JS sorts keys by UTF-16 code units, which differs from UTF-8 byte order for astral characters. */
    private static function compareUtf16(string $a, string $b): int
    {
        return strcmp(mb_convert_encoding($a, 'UTF-16BE', 'UTF-8'), mb_convert_encoding($b, 'UTF-16BE', 'UTF-8'));
    }
}
