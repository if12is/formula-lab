<?php

namespace App\Services\Template;

use App\Actions\Template\CheckTemplateUseAction;
use App\Exceptions\Domain\EngineException;
use App\Exceptions\Domain\TemplateUseRejectedException;
use App\Models\Formula;
use App\Models\FormulaTemplate;
use App\Models\FormulaVersion;
use App\Models\User;
use App\Services\Formula\FormulaService;

/**
 * Starts a formula from a template (K-09). The engine validates the filled template first (every line has a material and a share,
 * q.s. balanced, sum 100) and runs the compliance engine over it; only an answer without a failure becomes a draft formula,
 * linked to its family and template. A theoretical template is usable the same way, but the link keeps its status visible.
 */
final class TemplateUseService
{
    public function __construct(
        private CheckTemplateUseAction $checkTemplateUse,
        private FormulaService $formulaService,
    ) {}

    /**
     * @param  array{code: string, name: string, tier?: string|null, fill: array<string, array{material_id?: string|null, percent_ww?: string|null}>}  $data
     * @return array{formula: Formula, version: FormulaVersion, overall: string}
     *
     * @throws TemplateUseRejectedException With the engine's gaps when the template cannot be used as filled.
     * @throws EngineException When the engine cannot answer.
     */
    public function use(FormulaTemplate $template, array $data, ?User $user = null): array
    {
        $report = ($this->checkTemplateUse)($template, $data['fill']);

        $created = $this->formulaService->create([
            'code' => $data['code'],
            'name' => $data['name'],
            'product_type' => $template->family->code,
            'product_family_id' => $template->family->getKey(),
            'formula_template_id' => $template->getKey(),
            'tier' => $data['tier'] ?? null,
        ], array_map(fn (array $line): array => [
            'trade_material_id' => $line['materialId'],
            // The q.s. line carries the share the engine balanced (100 minus the others), as the editor saves it.
            'percent_ww' => $line['percentWW'],
            'is_qs' => $line['qs'],
            'phase' => null,
        ], $report['instance']['lines']), $user);

        return [...$created, 'overall' => $report['overall']];
    }
}
