<?php

namespace App\Actions\Formula;

use App\Enums\Confidence;
use App\Models\Formula;
use App\Support\DecimalNormalizer;

/**
 * Sets the unit cost (EGP per selling unit) the price lists check their prices against, with the source it comes from and a
 * confidence level. It is a sourced fact about the product, kept on the formula like its tax setting; the engine, not this
 * action, decides what a price is worth against it.
 */
final class UpdateFormulaUnitCostAction
{
    public function __invoke(Formula $formula, string $unitCost, string $sourceId, Confidence $confidence): Formula
    {
        $formula->update([
            'unit_cost' => DecimalNormalizer::normalize($unitCost),
            'unit_cost_source_id' => $sourceId,
            'unit_cost_confidence' => $confidence,
        ]);

        return $formula;
    }
}
