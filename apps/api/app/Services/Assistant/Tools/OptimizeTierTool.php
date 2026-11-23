<?php

namespace App\Services\Assistant\Tools;

use App\Enums\Assistant\AssistantDataCategory;
use App\Enums\ProductTier;
use App\Models\Price;
use App\Models\TradeMaterial;
use App\Services\Material\MaterialDetailService;
use App\Support\Assistant\AssistantTool;
use App\Support\Assistant\Concerns\BuildsToolAnswers;
use App\Support\Assistant\ToolContext;
use App\Support\Engine\EngineRunner;
use Illuminate\Validation\Rule;

/**
 * The tier optimizer (least-cost LP, HiGHS) for a product type and tier. The tier profile comes from config/assistant.php, which is
 * empty until the chemist decides the profiles (J-02): without one the tool answers `missing: tier_profile`. The materials are the
 * ones the person names, with their dated EGP-per-kg price and sourced active matter; a value that is not sourced is a gap, not a default.
 * Whatever it returns is a DRAFT formula that only the lab can promote.
 */
final class OptimizeTierTool implements AssistantTool
{
    use BuildsToolAnswers;

    private const PER = ['L', 'kg', 'pack'];

    public function __construct(
        private readonly EngineRunner $engine,
        private readonly MaterialDetailService $materials,
    ) {}

    public function name(): string
    {
        return 'optimize_tier';
    }

    public function description(): string
    {
        return 'Least-cost LP (HiGHS) for a product type and tier (economy, mid, premium) from the stored tier profile and the listed available materials. Deterministic. Returns a DRAFT formula with cost per kg and the binding constraints, or why it is infeasible, or what is missing (profile, prices, active matter). The formula stays draft until it is tested in the lab.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'productType' => ['type' => 'string', 'description' => 'A product family code.'],
                'tier' => ['type' => 'string', 'enum' => ProductTier::values()],
                'targetRetailPriceEGP' => ['type' => 'string', 'description' => 'The retail price the user wants, a decimal string, per `per`.'],
                'per' => ['type' => 'string', 'enum' => self::PER],
                'availableMaterialIds' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Ids of the trade materials the optimizer may use.'],
            ],
            'required' => ['productType', 'tier'],
            'additionalProperties' => false,
        ];
    }

    public function dataCategories(array $input): array
    {
        return [AssistantDataCategory::FormulaComposition, AssistantDataCategory::FormulaCost];
    }

    public function __invoke(array $input, ToolContext $context): array
    {
        $valid = $this->validated($input, [
            'productType' => ['required', 'string', 'max:100'],
            'tier' => ['required', Rule::enum(ProductTier::class)],
            'targetRetailPriceEGP' => ['nullable', 'string', 'regex:/^\d+(\.\d+)?$/'],
            'per' => ['nullable', Rule::in(self::PER)],
            'availableMaterialIds' => ['nullable', 'array', 'max:100'],
            'availableMaterialIds.*' => ['uuid'],
        ]);

        $profile = config("assistant.tier_profiles.{$valid['productType']}.{$valid['tier']}");
        if (! is_array($profile)) {
            return $this->missing([['property' => 'tier_profile', 'reason' => 'optimizer.profile_not_defined']]);
        }

        $ids = array_values(array_unique($valid['availableMaterialIds'] ?? []));
        if ($ids === []) {
            return $this->missing([['property' => 'availableMaterialIds', 'reason' => 'input.missing']]);
        }

        if (isset($valid['targetRetailPriceEGP'])) {
            // The price the user asked for is the target; its source is the request itself.
            $profile['targetRetailPrice'] = ['per' => $valid['per'] ?? ($profile['targetRetailPrice']['per'] ?? 'pack'), 'value' => $valid['targetRetailPriceEGP']];
            $profile['sources']['targetRetailPrice'] = 'user_request';
        }

        $materials = TradeMaterial::query()->whereIn('id', $ids)->get();
        $unknown = array_values(array_diff($ids, $materials->pluck('id')->all()));
        if ($unknown !== []) {
            return $this->missing(array_map(fn (string $id): array => ['materialId' => $id, 'property' => 'material', 'reason' => 'material.not_found'], $unknown));
        }

        $answer = $this->engine->run('optimize-tier', [
            'profile' => $profile,
            'materials' => $materials->map(fn (TradeMaterial $material): array => $this->optimizerMaterial($material))->values()->all(),
        ]);

        $data = array_intersect_key($answer, array_flip(['status', 'formula', 'costPerKg', 'binding', 'conflicts', 'errors', 'reversePricing', 'measuredRequirements', 'sensitivity']));
        $data['draft'] = true;
        $missing = (array) ($answer['missing'] ?? []);

        return ($answer['ok'] ?? false) === true
            ? $this->answer($data, (array) ($answer['warnings'] ?? []))
            : $this->missing($missing, $data, (array) ($answer['warnings'] ?? []));
    }

    /**
     * The optimizer's view of a material: the newest EGP-per-kg quote and the newest sourced active matter. A missing part is left out,
     * so the optimizer reports it as missing.
     *
     * @return array<string, mixed>
     */
    private function optimizerMaterial(TradeMaterial $material): array
    {
        $row = ['materialId' => $material->getKey()];

        $price = Price::query()
            ->where('trade_material_id', $material->getKey())
            ->where('currency', 'EGP')
            ->where('per_unit', 'kg')
            ->orderByDesc('quoted_on')
            ->orderBy('confidence')
            ->first();
        if ($price !== null) {
            $row['price'] = ['value' => $price->amount, 'unit' => 'EGP', 'sourceId' => $price->source_id, 'confidence' => $price->confidence->value, 'validFrom' => $price->quoted_on->toDateString()];
        }

        foreach ($this->materials->properties($material) as $value) {
            if ($value['property'] === 'active_matter' && $value['value'] !== null && $value['unit'] === '%w/w') {
                $row['activeMatterPct'] = ['value' => $value['value'], 'unit' => '%w/w', 'sourceId' => $value['source_id'], 'confidence' => $value['confidence'], 'validFrom' => $value['valid_from']];
                break;
            }
        }

        return $row;
    }
}
