<?php

namespace Tests\Concerns;

use App\Enums\LabProperty;
use App\Models\Formula;
use App\Models\FormulaVersion;
use App\Models\LabMeasurement;
use App\Models\LabTrial;
use App\Models\TradeMaterial;
use App\Services\Formula\FormulaVersionService;

/** Real formula versions (lines and a true hash) and trials with measurements for the lab and workflow tests. */
trait BuildsLabFixtures
{
    /** @param  array<string, mixed>  $formula */
    protected function versionWithLines(array $formula = []): FormulaVersion
    {
        $formula = Formula::factory()->create($formula);

        return app(FormulaVersionService::class)->createVersion($formula, [
            ['trade_material_id' => TradeMaterial::factory()->create()->id, 'percent_ww' => '10'],
            ['trade_material_id' => TradeMaterial::factory()->create()->id, 'percent_ww' => null, 'is_qs' => true],
        ], null);
    }

    /**
     * @param  list<LabProperty>  $properties  Measured, one reading each.
     * @param  array<string, mixed>  $trial
     */
    protected function trialWith(FormulaVersion $version, array $properties, bool $completed = true, array $trial = []): LabTrial
    {
        $record = LabTrial::factory()->create([...$trial, 'formula_version_id' => $version->id]);

        foreach ($properties as $property) {
            LabMeasurement::factory()->create([
                'lab_trial_id' => $record->id,
                'property' => $property,
                'unit' => $property->units()[0],
                'value' => '1.5',
            ]);
        }

        if ($completed) {
            $record->update(['status' => 'completed', 'completed_at' => now()]);
        }

        return $record->fresh();
    }

    /** @return list<LabProperty> The measurements the default configuration requires. */
    protected function requiredProperties(): array
    {
        return array_map(fn (string $key): LabProperty => LabProperty::from($key), config('lab.required_measurements.default'));
    }
}
