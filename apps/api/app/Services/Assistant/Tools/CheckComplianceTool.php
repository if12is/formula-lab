<?php

namespace App\Services\Assistant\Tools;

use App\Enums\Assistant\AssistantDataCategory;
use App\Models\FormulaVersion;
use App\Models\TradeMaterial;
use App\Services\Material\MaterialCompositionService;
use App\Support\Assistant\AssistantTool;
use App\Support\Assistant\Concerns\BuildsToolAnswers;
use App\Support\Assistant\ToolContext;
use App\Support\Compliance\RuleSetAssembler;
use App\Support\Engine\EngineRunner;
use Illuminate\Validation\Rule;

/** The compliance engine (ES / CosIng / IFRA rule sets) over a saved formula version: PASS, FAIL or UNKNOWN per rule, never a guess. */
final class CheckComplianceTool implements AssistantTool
{
    use BuildsToolAnswers;

    /** The only market the rule sets cover today. */
    private const MARKETS = ['EG'];

    public function __construct(
        private readonly EngineRunner $engine,
        private readonly MaterialCompositionService $compositions,
        private readonly RuleSetAssembler $assembler,
    ) {}

    public function name(): string
    {
        return 'check_compliance';
    }

    public function description(): string
    {
        return 'Run the compliance rules (ES, CosIng, IFRA) over a saved formula version. Returns PASS, FAIL or UNKNOWN per rule with the value, the limit and the rule source; UNKNOWN means data is missing (see missing), it is never a pass.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'formulaVersionId' => ['type' => 'string', 'description' => 'The id of a saved formula version.'],
                'market' => ['type' => 'string', 'enum' => self::MARKETS],
            ],
            'required' => ['formulaVersionId'],
            'additionalProperties' => false,
        ];
    }

    public function dataCategories(array $input): array
    {
        return [AssistantDataCategory::FormulaComposition];
    }

    public function __invoke(array $input, ToolContext $context): array
    {
        $valid = $this->validated($input, ['formulaVersionId' => ['required', 'uuid'], 'market' => ['nullable', Rule::in(self::MARKETS)]]);
        $version = FormulaVersion::query()->with(['lines', 'formula.productFamily'])->find($valid['formulaVersionId']);
        if ($version === null) {
            return $this->missing([['property' => 'formulaVersion', 'reason' => 'formula_version.not_found']]);
        }

        $formula = $version->formula;
        $productType = $formula->productFamily?->code ?? $formula->product_type;
        $materials = TradeMaterial::query()->whereIn('id', $version->lines->pluck('trade_material_id'))->get();
        $compositions = $this->compositions->enginePayloads($materials);

        // The product's rinse-off nature is not recorded on a formula, so a rule that needs it stays UNKNOWN. Its IFRA category is, when the person set one.
        $context = array_filter(['productType' => $productType, 'ifraCategory' => $formula->ifra_category], fn (?string $value): bool => $value !== null && $value !== '');

        $answer = $this->engine->run('assistant', ['op' => 'check-compliance', 'input' => [
            'lines' => $version->lines->map(fn ($line): array => array_filter([
                'materialId' => $line->trade_material_id,
                'percentWW' => $line->percent_ww,
            ], fn (?string $value): bool => $value !== null))->values()->all(),
            'materials' => array_values(array_map(
                fn (string $id, ?array $composition): array => array_filter(['materialId' => $id, 'composition' => $composition], fn ($value): bool => $value !== null),
                array_keys($compositions),
                $compositions,
            )),
            'ruleSets' => $this->assembler->forProductType($productType),
            'context' => $context,
        ]]);

        if (($answer['ok'] ?? false) !== true) {
            return $this->missing((array) ($answer['missing'] ?? []));
        }

        $value = (array) $answer['value'];
        $missing = array_merge(...array_map(fn (array $report): array => (array) ($report['missing'] ?? []), (array) $value['reports']));

        $data = ['market' => $valid['market'] ?? self::MARKETS[0], 'productType' => $productType, ...$value];

        // Whatever the rules could not decide is named, so an UNKNOWN is never taken for a pass.
        return $missing === []
            ? $this->answer($data, (array) ($answer['warnings'] ?? []))
            : $this->missing($missing, $data, (array) ($answer['warnings'] ?? []));
    }
}
