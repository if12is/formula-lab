<?php

namespace Database\Factories;

use App\Enums\Coa\CoaReadingConfidence;
use App\Models\CoaExtraction;
use App\Models\CoaExtractionField;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CoaExtractionField> */
class CoaExtractionFieldFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'coa_extraction_id' => CoaExtraction::factory(),
            'position' => 1,
            'property_as_printed' => 'Density',
            'property_key' => 'density',
            'value' => '1.0',
            'min' => null,
            'max' => null,
            'unit' => 'g/mL',
            'as_printed' => ['value' => '1.0', 'min' => null, 'max' => null, 'unit' => 'g/mL'],
            'converted_from' => null,
            'method' => null,
            'quote' => null,
            'page' => null,
            'reading_confidence' => CoaReadingConfidence::High,
            'issues' => [],
        ];
    }
}
