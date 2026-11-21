<?php

namespace Database\Factories;

use App\Enums\Confidence;
use App\Enums\LabProperty;
use App\Enums\TestedValueKind;
use App\Models\FormulaVersion;
use App\Models\LabMeasurement;
use App\Models\LabTrial;
use App\Models\TestedValueMark;
use App\Models\TradeMaterial;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TestedValueMark> */
class TestedValueMarkFactory extends Factory
{
    public function configure(): static
    {
        // The measurement exists before the trial closes (a closed trial takes no more readings).
        return $this->afterCreating(function (TestedValueMark $mark): void {
            LabTrial::query()->whereKey($mark->lab_trial_id)->update(['status' => 'completed', 'completed_at' => now()]);
        });
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'lab_trial_id' => fn (): string => LabTrial::factory()->create(['formula_version_id' => FormulaVersion::factory()->create()->id])->id,
            'tenant_id' => fn (array $attributes): string => LabTrial::query()->findOrFail($attributes['lab_trial_id'])->tenant_id,
            'formula_version_id' => fn (array $attributes): string => LabTrial::query()->findOrFail($attributes['lab_trial_id'])->formula_version_id,
            'lab_measurement_id' => fn (array $attributes): int => LabMeasurement::factory()->create([
                'lab_trial_id' => $attributes['lab_trial_id'], 'property' => LabProperty::Stability4w, 'unit' => '%', 'value' => '98',
            ])->id,
            'trade_material_id' => TradeMaterial::factory(),
            'kind' => TestedValueKind::Dose,
            'value' => '12',
            'source_id' => null,
            'confidence' => Confidence::L1CoaBatch,
            'tested_on' => '2026-11-20',
        ];
    }
}
