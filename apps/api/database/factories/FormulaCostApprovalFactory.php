<?php

namespace Database\Factories;

use App\Enums\Confidence;
use App\Models\Formula;
use App\Models\FormulaCostApproval;
use App\Models\FormulaVersion;
use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FormulaCostApproval> */
class FormulaCostApprovalFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'formula_id' => Formula::factory(),
            'tenant_id' => fn (array $attributes) => Formula::query()->findOrFail($attributes['formula_id'])->tenant_id,
            'formula_version_id' => fn (array $attributes) => FormulaVersion::factory()->create(['formula_id' => $attributes['formula_id']])->getKey(),
            'unit_cost' => '18.4573',
            'unit_cost_source_id' => Source::factory(),
            'unit_cost_confidence' => Confidence::L2SupplierTds,
            'material_prices' => [],
            'report_input_hash' => hash('sha256', fake()->unique()->uuid()),
            'report_output_hash' => hash('sha256', fake()->unique()->uuid()),
            'comment' => null,
            'approved_by' => null,
        ];
    }
}
