<?php

namespace Database\Factories;

use App\Models\DatasetVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DatasetVersion> */
class DatasetVersionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'id' => hash('sha256', fake()->unique()->uuid()),
            'notes' => null,
            'manifest' => ['schema' => 'fl.dataset/1'],
        ];
    }
}
