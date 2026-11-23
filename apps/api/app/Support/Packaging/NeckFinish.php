<?php

namespace App\Support\Packaging;

/** How two neck finish texts are compared: trimmed, one space between words, lower case. The engine's `neckKey` does the same. */
final class NeckFinish
{
    public static function key(string $finish): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($finish)));
    }
}
