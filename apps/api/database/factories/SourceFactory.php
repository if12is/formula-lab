<?php

namespace Database\Factories;

use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Source> */
class SourceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'kind' => 'document',
            'title' => 'Source '.fake()->unique()->bothify('??##'),
            'url' => null,
            'license' => null,
            'retrieved_at' => '2026-01-01',
        ];
    }
}
