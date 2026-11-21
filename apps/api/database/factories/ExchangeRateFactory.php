<?php

namespace Database\Factories;

use App\Enums\Confidence;
use App\Models\ExchangeRate;
use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExchangeRate> */
class ExchangeRateFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'from_currency' => 'USD',
            'to_currency' => config('currencies.base'),
            'rate' => '50',
            'rate_date' => '2026-01-01',
            'source_id' => Source::factory(),
            'confidence' => Confidence::L4RegulatoryDb,
            'created_by' => null,
        ];
    }
}
