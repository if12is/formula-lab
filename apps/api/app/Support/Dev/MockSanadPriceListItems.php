<?php

namespace App\Support\Dev;

use Illuminate\Validation\Validator;

/** The item rows shared by the price list upsert and the items-only upsert of the mock Sanad (contract 8.2 / 8.3). */
final class MockSanadPriceListItems
{
    private const DECIMAL = '/^\d+(\.\d+)?$/';

    /** @return array<string, mixed> */
    public static function rules(string $key): array
    {
        return [
            "{$key}.*" => ['array'],
            "{$key}.*.product_uuid" => ['required', 'uuid', 'distinct'],
            "{$key}.*.price" => ['present'],
        ];
    }

    /**
     * `price` is a decimal string above zero, or null to remove; anything else (a JSON number, "0", a negative, text) is a 422 on `{key}.N.price`.
     *
     * @param  array<int, mixed>  $items
     */
    public static function check(Validator $validator, array $items, string $key): void
    {
        foreach ($items as $index => $row) {
            if (! is_array($row) || ! array_key_exists('price', $row) || $row['price'] === null) {
                continue;
            }
            $price = $row['price'];
            $valid = is_string($price) && preg_match(self::DECIMAL, $price) === 1 && StringDecimal::compare($price, '0') > 0;
            if (! $valid) {
                $validator->errors()->add("{$key}.{$index}.price", __('sanad.mock.price_invalid'));
            }
        }
    }
}
