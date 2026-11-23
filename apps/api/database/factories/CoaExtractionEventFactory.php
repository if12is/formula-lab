<?php

namespace Database\Factories;

use App\Enums\Coa\CoaExtractionStatus;
use App\Models\CoaExtraction;
use App\Models\CoaExtractionEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CoaExtractionEvent> */
class CoaExtractionEventFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'coa_extraction_id' => CoaExtraction::factory(),
            'status' => CoaExtractionStatus::Extracted,
            'user_id' => null,
            'occurred_at' => now(),
            'reason' => null,
            'decisions' => null,
            'property_value_ids' => null,
        ];
    }
}
