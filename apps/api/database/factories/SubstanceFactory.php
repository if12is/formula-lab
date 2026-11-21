<?php

namespace Database\Factories;

use App\Models\Substance;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Substance> */
class SubstanceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = 'Substance '.fake()->unique()->bothify('??##');

        return [
            'cas' => null,
            'inci' => strtoupper($name),
            'name_en' => $name,
            'name_ar' => null,
            'mw' => null,
            'formula' => null,
        ];
    }
}
