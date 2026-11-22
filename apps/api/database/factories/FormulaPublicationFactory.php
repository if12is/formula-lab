<?php

namespace Database\Factories;

use App\Models\FormulaCostApproval;
use App\Models\FormulaPublication;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FormulaPublication> */
class FormulaPublicationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'cost_approval_id' => FormulaCostApproval::factory(),
            'tenant_id' => fn (array $attributes) => FormulaCostApproval::query()->findOrFail($attributes['cost_approval_id'])->tenant_id,
            'formula_id' => fn (array $attributes) => FormulaCostApproval::query()->findOrFail($attributes['cost_approval_id'])->formula_id,
            'sanad_product_uuid' => fake()->uuid(),
            'mode' => 'outbox',
            'outbox_id' => null,
            'details' => [],
            'decision' => [],
            'decision_hash' => hash('sha256', fake()->unique()->uuid()),
            'published_by' => null,
        ];
    }
}
