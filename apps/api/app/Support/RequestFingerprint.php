<?php

namespace App\Support;

/** Stable sha256 of a request body: object keys sorted recursively, lists keep their order (contract section 6). */
final class RequestFingerprint
{
    public static function hash(mixed $body): string
    {
        return hash('sha256', (string) json_encode(self::canonical($body), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(fn (mixed $item): mixed => self::canonical($item), $value);
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
