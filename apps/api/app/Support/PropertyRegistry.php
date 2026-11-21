<?php

namespace App\Support;

/** Read side of config/properties.php: the known material property keys, their units and their lang labels. */
final class PropertyRegistry
{
    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(config('properties'));
    }

    /** @return list<string> Empty for a key the registry does not know. */
    public static function unitsFor(string $key): array
    {
        return config("properties.{$key}.units", []);
    }

    /**
     * What the frontend needs to build selectors: label text arrives through the shared translations.
     *
     * @return list<array{value: string, labelKey: string, units: list<string>}>
     */
    public static function options(): array
    {
        return collect(config('properties'))
            ->map(fn (array $property, string $key): array => ['value' => $key, 'labelKey' => $property['label_key'], 'units' => $property['units']])
            ->values()
            ->all();
    }
}
