<?php

namespace App\Casts;

use App\Support\DecimalNormalizer;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Exact decimal attribute: always a plain decimal string in PHP, never a float.
 * Floats are rejected on write (they would already be inexact); ints are accepted.
 *
 * @implements CastsAttributes<string|null, string|int|null>
 */
class DecimalString implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : (string) $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (! is_string($value) || ! DecimalNormalizer::isValid($value)) {
            throw new InvalidArgumentException("{$key} must be a plain decimal string, got ".get_debug_type($value));
        }

        return $value;
    }
}
