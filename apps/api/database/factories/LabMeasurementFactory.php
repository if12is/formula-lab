<?php

namespace Database\Factories;

use App\Enums\Confidence;
use App\Enums\LabProperty;
use App\Models\LabMeasurement;
use App\Models\LabTrial;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LabMeasurement> */
class LabMeasurementFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'lab_trial_id' => LabTrial::factory(),
            'property' => LabProperty::Ph,
            'value' => '7.2',
            'unit' => 'pH',
            'lab_protocol_id' => null,
            'method' => 'Method A',
            'instrument' => 'Meter 1',
            'measured_at' => '2026-11-20 09:00:00',
            'confidence' => Confidence::L1CoaBatch,
            'note' => null,
        ];
    }
}
