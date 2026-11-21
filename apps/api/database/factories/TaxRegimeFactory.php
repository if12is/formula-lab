<?php

namespace Database\Factories;

use App\Models\Source;
use App\Models\TaxRegime;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TaxRegime> */
class TaxRegimeFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tax_type' => config('tax.type'),
            'rate_pct' => '14',
            'valid_from' => '2026-01-01',
            'valid_to' => null,
            'source_id' => Source::factory(),
            'confidence' => 'L6_community',
            'verification' => 'pending',
        ];
    }
}
