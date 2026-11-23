<?php

namespace Database\Factories;

use App\Enums\Production\DeviationApprovalStatus;
use App\Enums\Production\DeviationKind;
use App\Enums\Production\DeviationVerdict;
use App\Models\ProductionBatch;
use App\Models\ProductionDeviation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductionDeviation> */
class ProductionDeviationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'production_batch_id' => ProductionBatch::factory(),
            'kind' => DeviationKind::Other,
            'subject' => 'note',
            'verdict' => DeviationVerdict::NotEvaluated,
            'requires_approval' => false,
            'approval_status' => DeviationApprovalStatus::NotRequired,
            'reason' => 'Operator note',
            'recorded_by_name' => 'Operator A',
            'recorded_at' => '2026-11-25 10:00:00',
        ];
    }

    public function pending(): static
    {
        return $this->state(['kind' => DeviationKind::Weight, 'subject' => 'line:1', 'planned' => '100', 'actual' => '110', 'unit' => 'g', 'deviation_pct' => '10', 'verdict' => DeviationVerdict::Exceeds, 'requires_approval' => true, 'approval_status' => DeviationApprovalStatus::Pending]);
    }
}
