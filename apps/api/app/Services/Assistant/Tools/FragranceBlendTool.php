<?php

namespace App\Services\Assistant\Tools;

use App\Enums\Assistant\AssistantDataCategory;
use App\Models\PropertyValue;
use App\Models\TradeMaterial;
use App\Support\Assistant\AssistantTool;
use App\Support\Assistant\Concerns\BuildsToolAnswers;
use App\Support\Assistant\ToolContext;
use App\Support\Engine\EngineRunner;
use App\Support\PropertyRegistry;

/**
 * The blend of an air-care product (reed diffuser, burner oil, gel, car freshener): fragrance oil, other additives and the q.s. carrier in %
 * and grams for a batch, with the IFRA check of the oil, by the engine's air-care blend (L-07). The IFRA limit is read from the oil's certificate
 * for the category the person names: no category or no sourced limit is an UNKNOWN check, reported in missing, never a pass.
 */
final class FragranceBlendTool implements AssistantTool
{
    use BuildsToolAnswers;

    public function __construct(private readonly EngineRunner $engine) {}

    public function name(): string
    {
        return 'fragrance_blend';
    }

    public function description(): string
    {
        return 'Deterministic engine: the blend of an air-care product for a batch: fragrance oil %, other additives %, the q.s. carrier, each in % w/w and grams, and the IFRA check of the oil against the limit on its certificate for ifraCategory. Give fragranceMaterialId, fragranceOilPct and batchMassG as the user wrote them. Without a certificate limit the IFRA status is UNKNOWN (never PASS) and the gap is in missing.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'fragranceMaterialId' => ['type' => 'string', 'description' => 'The id of the fragrance oil (a trade material).'],
                'fragranceOilPct' => ['type' => 'string', 'description' => 'Fragrance oil, % w/w of the product. A decimal string.'],
                'batchMassG' => ['type' => 'string', 'description' => 'Batch mass in grams. A decimal string.'],
                'otherPct' => ['type' => 'string', 'description' => 'Other additives (colour, preservative), % w/w. A decimal string; omit for none.'],
                'ifraCategory' => ['type' => 'string', 'description' => 'The IFRA category exactly as written on the oil certificate.'],
            ],
            'required' => ['fragranceMaterialId', 'fragranceOilPct', 'batchMassG'],
            'additionalProperties' => false,
        ];
    }

    public function dataCategories(array $input): array
    {
        return [AssistantDataCategory::FormulaComposition];
    }

    public function __invoke(array $input, ToolContext $context): array
    {
        $decimal = ['string', 'regex:/^\d+(\.\d+)?$/'];
        $valid = $this->validated($input, [
            'fragranceMaterialId' => ['required', 'uuid'],
            'fragranceOilPct' => ['required', ...$decimal],
            'batchMassG' => ['required', ...$decimal],
            'otherPct' => ['nullable', ...$decimal],
            'ifraCategory' => ['nullable', 'string', 'max:100'],
        ]);

        $oil = TradeMaterial::query()->find($valid['fragranceMaterialId']);
        if ($oil === null) {
            return $this->missing([['materialId' => $valid['fragranceMaterialId'], 'property' => 'material', 'reason' => 'material.not_found']]);
        }

        $answer = $this->engine->run('assistant', ['op' => 'air-care-blend', 'input' => array_filter([
            'fragranceId' => $oil->getKey(),
            'fragranceOilPct' => $valid['fragranceOilPct'],
            'otherPct' => $valid['otherPct'] ?? null,
            'batchMassG' => $valid['batchMassG'],
            'ifraLimitPct' => $this->ifraLimit($oil, $valid['ifraCategory'] ?? null),
        ], fn (mixed $part): bool => $part !== null)]);

        if (($answer['ok'] ?? false) !== true) {
            return $this->missing((array) ($answer['missing'] ?? []), [], (array) ($answer['warnings'] ?? []));
        }

        $data = (array) $answer['value'];
        $ifraMissing = (array) ($data['ifra']['missing'] ?? []);

        return $data['ifra']['status'] === 'UNKNOWN'
            ? $this->missing($ifraMissing === [] ? [['materialId' => $oil->getKey(), 'property' => 'ifraLimitPct', 'reason' => 'input.missing']] : $ifraMissing, $data, (array) $answer['warnings'])
            : $this->answer($data, (array) $answer['warnings']);
    }

    /**
     * The newest certificate limit of the oil for the category, as the engine's sourced value; null when none is recorded.
     *
     * @return array<string, string>|null
     */
    private function ifraLimit(TradeMaterial $oil, ?string $category): ?array
    {
        if ($category === null) {
            return null;
        }

        $limit = PropertyValue::query()
            ->where('entity_type', 'trade_material')
            ->where('entity_id', $oil->getKey())
            ->where('property', PropertyRegistry::IFRA_LIMIT)
            ->where('category', $category)
            ->whereNotNull('source_id')
            ->orderByDesc('valid_from')
            ->first();

        return $limit === null || $limit->unit !== '%w/w' ? null : [
            'value' => $limit->value,
            'unit' => '%w/w',
            'sourceId' => $limit->source_id,
            'confidence' => $limit->confidence->value,
            'validFrom' => $limit->valid_from->toDateString(),
        ];
    }
}
