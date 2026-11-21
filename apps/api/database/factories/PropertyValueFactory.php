<?php

namespace Database\Factories;

use App\Enums\Confidence;
use App\Models\PropertyValue;
use App\Models\Source;
use App\Models\Substance;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PropertyValue> */
class PropertyValueFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'entity_type' => 'substance',
            'entity_id' => Substance::factory(),
            'property' => 'property_a',
            'value' => '1.5',
            'min' => null,
            'max' => null,
            'unit' => 'unit_a',
            'source_id' => Source::factory(),
            'confidence' => Confidence::L5Reference,
            'valid_from' => '2026-01-01',
            'lot_no' => null,
            'created_by' => null,
        ];
    }

    public function range(string $min, string $max): static
    {
        return $this->state(['value' => null, 'min' => $min, 'max' => $max]);
    }
}
