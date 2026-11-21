<?php

namespace Database\Factories;

use App\Models\Formula;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Formula> */
class FormulaFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'code' => 'F-'.fake()->unique()->numerify('####'),
            'name' => 'Formula '.fake()->unique()->bothify('??##'),
            'product_type' => 'product_type_a',
            'tier' => null,
        ];
    }
}
