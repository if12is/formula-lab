<?php

namespace Database\Factories;

use App\Enums\PriceListMode;
use App\Models\Formula;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PriceListItem> */
class PriceListItemFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'price_list_id' => PriceList::factory(),
            'formula_id' => Formula::factory(),
            'mode' => PriceListMode::Rule,
            'rule_key' => 'merchants',
            'override_price' => null,
            'override_source_id' => null,
            'override_confidence' => null,
        ];
    }

    /** An explicit agreed price with its source and confidence. */
    public function override(string $price = '40.00'): static
    {
        return $this->state(fn (): array => [
            'mode' => PriceListMode::Override,
            'rule_key' => null,
            'override_price' => $price,
            'override_source_id' => Source::factory(),
            'override_confidence' => 'L3_standard',
        ]);
    }
}
