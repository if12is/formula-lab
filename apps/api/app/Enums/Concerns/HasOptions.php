<?php

namespace App\Enums\Concerns;

use BackedEnum;
use Illuminate\Support\Str;

/**
 * Translated labels and select options for a string-backed enum, read from
 * lang/{ar,en}/enums.php under the snake_case enum name: `enums.<enum_snake>.<value>`.
 *
 * @mixin BackedEnum
 */
trait HasOptions
{
    public function label(): string
    {
        return __('enums.'.Str::snake(class_basename(static::class)).'.'.$this->value);
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
