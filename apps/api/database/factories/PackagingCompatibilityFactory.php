<?php

namespace Database\Factories;

use App\Enums\Confidence;
use App\Models\PackagingCompatibility;
use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PackagingCompatibility> */
class PackagingCompatibilityFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'container_neck_finish' => 'NECK-'.fake()->unique()->bothify('###'),
            'closure_neck_finish' => 'FIT-'.fake()->unique()->bothify('###'),
            'source_id' => Source::factory(),
            'confidence' => Confidence::L3Standard,
            'note' => null,
        ];
    }
}
