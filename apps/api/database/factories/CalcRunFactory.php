<?php

namespace Database\Factories;

use App\Models\CalcRun;
use App\Models\DatasetVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CalcRun> */
class CalcRunFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'input_hash' => hash('sha256', fake()->unique()->uuid()),
            'output_hash' => hash('sha256', fake()->unique()->uuid()),
            'engine_version' => '0.1.0',
            'dataset_version' => DatasetVersion::factory(),
        ];
    }
}
