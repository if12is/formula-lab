<?php

namespace App\Actions\Template;

use App\Exceptions\Domain\EngineException;
use App\Exceptions\Domain\TemplateUseRejectedException;
use App\Models\FormulaTemplate;
use App\Models\RuleSet;
use App\Models\TradeMaterial;
use App\Services\Material\MaterialCompositionService;
use App\Support\Compliance\RuleSetAssembler;
use App\Support\Engine\EngineRunner;
use Illuminate\Database\Eloquent\Collection;

/**
 * Asks the engine to validate a filled template and run the compliance engine over it (engine checkTemplateUse). PHP only
 * gathers what the engine reads (the materials' compositions and the rule sets scoped to the family) and never decides a sum or a limit.
 */
final class CheckTemplateUseAction
{
    public function __construct(
        private EngineRunner $engine,
        private MaterialCompositionService $compositionService,
        private RuleSetAssembler $assembler,
    ) {}

    /**
     * @param  array<string, array{material_id?: string|null, percent_ww?: string|null}>  $fill  By template line key.
     * @return array{instance: array{lines: list<array{key: string, role: string, materialId: string, percentWW: string, qs: bool}>, sum: string, deviation: string}, compliance: list<array<string, mixed>>, overall: string}
     *
     * @throws TemplateUseRejectedException When the engine finds something missing or invalid, or a rule FAILs.
     * @throws EngineException When the engine cannot answer.
     */
    public function __invoke(FormulaTemplate $template, array $fill): array
    {
        $family = $template->family;
        $materialIds = array_values(array_filter(array_column($fill, 'material_id')));
        /** @var Collection<int, TradeMaterial> $materials */
        $materials = TradeMaterial::query()->whereIn('id', $materialIds)->get();
        $compositions = $this->compositionService->enginePayloads($materials);

        $engineFill = [];
        foreach ($fill as $key => $line) {
            $engineFill[$key] = array_filter([
                'materialId' => $line['material_id'] ?? null,
                'percentWW' => $line['percent_ww'] ?? null,
            ], fn (?string $value): bool => $value !== null && $value !== '');
        }

        $answer = $this->engine->run('check-template', [
            'template' => $this->engineTemplate($template),
            'fill' => (object) array_map(fn (array $line): object => (object) $line, $engineFill),
            'materials' => array_values(array_map(
                fn (string $id, ?array $composition): array => array_filter(['materialId' => $id, 'composition' => $composition], fn ($value): bool => $value !== null),
                array_keys($compositions),
                $compositions,
            )),
            'ruleSets' => $this->ruleSetsFor($family->code),
            // The family's IFRA category and rinse-off nature are unverified research, so they are not given to the rules:
            // a rule that needs them stays UNKNOWN instead of being decided on a guess.
            'context' => ['productType' => $family->code],
        ]);

        if (($answer['ok'] ?? false) !== true) {
            throw TemplateUseRejectedException::fromEngine($answer['missing'] ?? []);
        }

        return $answer['report'];
    }

    /** @return array<string, mixed> The template as the engine's FormulaTemplate reads it. */
    private function engineTemplate(FormulaTemplate $template): array
    {
        return [
            'code' => $template->code,
            'familyCode' => $template->family->code,
            'name' => ['ar' => $template->name_ar, 'en' => $template->name_en],
            'verification' => $template->verification_status->value,
            'sourceId' => (string) $template->source_id,
            'confidence' => $template->confidence->value,
            'lines' => $template->lines,
        ];
    }

    /** @return list<array<string, mixed>> Every rule set with a rule scoped to the family's code, assembled as the engine reads it. */
    private function ruleSetsFor(string $familyCode): array
    {
        return RuleSet::query()
            ->whereHas('rules', fn ($rules) => $rules->where('active', true)->whereRaw("jsonb_exists(rule -> 'scope' -> 'productTypes', ?)", [$familyCode]))
            ->with('rules')
            ->orderBy('code')
            ->get()
            ->map(fn (RuleSet $ruleSet): array => $this->assembler->assemble($ruleSet))
            ->all();
    }
}
