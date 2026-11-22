<?php

namespace Database\Factories;

use App\Enums\Confidence;
use App\Models\ProductFamily;
use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductFamily> */
class ProductFamilyFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $code = 'family_'.fake()->unique()->bothify('??##');

        return [
            'code' => $code,
            'group' => 'detergents',
            'name_ar' => 'عائلة '.$code,
            'name_en' => 'Family '.$code,
            'regulator' => null,
            'ifra_category' => null,
            'required_calculations' => [],
            'measured_properties' => [],
            'tax_regime' => null,
            'notes_key' => null,
            'source_id' => Source::factory(),
            'confidence' => Confidence::L7Estimate,
            'verification' => 'pending',
            'position' => 0,
        ];
    }
}
