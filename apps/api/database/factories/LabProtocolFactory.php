<?php

namespace Database\Factories;

use App\Enums\LabProperty;
use App\Models\LabProtocol;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LabProtocol> */
class LabProtocolFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => 'protocol_'.fake()->unique()->bothify('??##'),
            'property' => LabProperty::Ph,
            'unit' => 'pH',
            'instrument' => 'ph_meter',
            'steps' => ['calibrate', 'measure'],
            'position' => 0,
        ];
    }
}
