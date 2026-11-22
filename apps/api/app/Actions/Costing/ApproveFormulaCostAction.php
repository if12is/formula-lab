<?php

namespace App\Actions\Costing;

use App\Models\Formula;
use App\Models\FormulaCostApproval;
use App\Models\FormulaVersion;

/** Records one signed approval of a product's unit cost on an approved formula version, with the prices it rests on. */
final class ApproveFormulaCostAction
{
    /**
     * @param  array<string, array{egp_per_kg: string, quote_id: string, confidence: string}>  $materialPrices  Each ingredient's price in EGP per kg, as the engine costed it.
     */
    public function __invoke(Formula $formula, FormulaVersion $version, array $materialPrices, string $reportInputHash, string $reportOutputHash, ?int $userId, ?string $comment): FormulaCostApproval
    {
        return FormulaCostApproval::query()->create([
            'tenant_id' => $formula->tenant_id,
            'formula_id' => $formula->getKey(),
            'formula_version_id' => $version->getKey(),
            'unit_cost' => $formula->unit_cost,
            'unit_cost_source_id' => $formula->unit_cost_source_id,
            'unit_cost_confidence' => $formula->unit_cost_confidence,
            'material_prices' => $materialPrices,
            'report_input_hash' => $reportInputHash,
            'report_output_hash' => $reportOutputHash,
            'comment' => $comment,
            'approved_by' => $userId,
        ]);
    }
}
