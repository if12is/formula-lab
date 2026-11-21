<?php

namespace App\Services\Formula;

use App\Models\Formula;
use App\Models\FormulaVersion;
use App\Services\Compliance\ComplianceEditorService;

/**
 * What the label page of one formula version needs: its lines and the composition of every material they use, and the
 * rule sets that may add label warnings. The INCI list itself is built by the engine in the browser.
 */
final class FormulaLabelService
{
    public function __construct(
        private FormulaEditorService $formulaEditorService,
        private ComplianceEditorService $complianceEditorService,
    ) {}

    /** @return array<string, mixed> */
    public function forVersion(Formula $formula, FormulaVersion $version): array
    {
        return [
            'formula' => [
                'id' => $formula->getKey(),
                'code' => $formula->code,
                'name' => $formula->name,
                'product_type' => $formula->product_type,
                'ifra_category' => $formula->ifra_category,
            ],
            'version' => [
                'number' => $version->version,
                'status' => $version->status->value,
                'hash' => $version->hash,
            ],
            ...$this->formulaEditorService->linesAndMaterials($version),
            'ruleSets' => $this->complianceEditorService->ruleSets(),
        ];
    }
}
