<?php

namespace App\Support;

/** The shape rule of a sourced property value, shared by every request that writes one: a single value, or a min/max range, never both and never neither. */
final class PropertyValueShape
{
    /** The plain decimal text a value, a minimum or a maximum is typed as. */
    public const DECIMAL = '/^-?\d+(\.\d+)?$/';

    /**
     * @return string|null The lang key of what is wrong, or null when the shape is valid. Each argument must already be a valid decimal text or null.
     */
    public static function violation(?string $value, ?string $min, ?string $max): ?string
    {
        if ($value === null && $min === null && $max === null) {
            return 'materials.errors.value_or_range_required';
        }
        if ($value !== null && ($min !== null || $max !== null)) {
            return 'materials.errors.value_or_range_exclusive';
        }
        if ($min !== null && $max !== null && DecimalCompare::compare($min, $max) > 0) {
            return 'materials.errors.range_order';
        }

        return null;
    }
}
