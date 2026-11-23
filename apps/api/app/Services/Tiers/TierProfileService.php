<?php

namespace App\Services\Tiers;

use App\Actions\Tenancy\EnsureDefaultTenantAction;
use App\Actions\Tiers\SaveTierProfileAction;
use App\Enums\ProductTier;
use App\Models\TierProfile;
use App\Models\TradeMaterial;
use App\Support\DecimalNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * Reads and writes the profile of a tier as the screen's form and as the optimizer's profile body. The form keeps the figures a
 * person types (active matter limits, a cost cap, a target retail price, forbidden materials, a bound per material, which materials
 * are required); the optimizer's own validator judges the result, so no limit is checked here beyond "is a decimal".
 */
final class TierProfileService
{
    public function __construct(
        private readonly EnsureDefaultTenantAction $ensureDefaultTenant,
        private readonly SaveTierProfileAction $saveProfile,
    ) {}

    public function find(string $productType, ProductTier $tier): ?TierProfile
    {
        return TierProfile::query()
            ->with('source')
            ->where('tenant_id', ($this->ensureDefaultTenant)()->getKey())
            ->where('product_type', $productType)
            ->where('tier', $tier)
            ->first();
    }

    /**
     * The form of the profile; an empty one when nothing was saved (every figure empty, never a default).
     *
     * @return array<string, mixed>
     */
    public function form(string $productType, ProductTier $tier): array
    {
        $record = $this->find($productType, $tier);
        $profile = $record?->profile ?? [];
        $constraints = $profile['constraints'] ?? [];
        $bounds = collect($constraints['materialBounds'] ?? []);
        $required = $constraints['requiredMaterials'] ?? [];
        $names = TradeMaterial::query()->whereIn('id', [...$bounds->pluck('materialId')->all(), ...($constraints['forbiddenMaterials'] ?? [])])->pluck('trade_name', 'id');

        return [
            'saved' => $record !== null,
            'total_active_min_pct' => $constraints['totalActiveMinPct'] ?? '',
            'total_active_max_pct' => $constraints['totalActiveMaxPct'] ?? '',
            'max_cost_egp_per_kg' => $constraints['maxCostEGPperKg'] ?? '',
            'target_per' => $profile['targetRetailPrice']['per'] ?? 'kg',
            'target_value' => $profile['targetRetailPrice']['value'] ?? '',
            'forbidden' => array_map(fn (string $id): array => ['value' => $id, 'label' => $names[$id] ?? $id], $constraints['forbiddenMaterials'] ?? []),
            'bounds' => $bounds->map(fn (array $bound): array => [
                'material_id' => $bound['materialId'],
                'label' => $names[$bound['materialId']] ?? $bound['materialId'],
                'min' => $bound['min'] ?? '',
                'max' => $bound['max'] ?? '',
                'required' => in_array($bound['materialId'], $required, true),
            ])->values()->all(),
            'source' => $record?->source === null ? null : ['value' => $record->source->getKey(), 'label' => $record->source->title],
        ];
    }

    /**
     * @param  array<string, mixed>  $data  The validated form.
     */
    public function save(string $productType, ProductTier $tier, array $data): TierProfile
    {
        return DB::transaction(fn (): TierProfile => ($this->saveProfile)(
            ($this->ensureDefaultTenant)()->getKey(),
            $productType,
            $tier,
            $this->body($data),
            $data['source_id'] ?? null,
        ));
    }

    /**
     * The profile body the optimizer validates, from the form: an empty field is null (no constraint), never 0.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function body(array $data): array
    {
        $number = fn (mixed $value): ?string => ($value === null || $value === '') ? null : DecimalNormalizer::normalize((string) $value);
        $bounds = collect($data['bounds'] ?? []);

        return [
            'targetRetailPrice' => ['per' => (string) ($data['target_per'] ?? 'kg'), 'value' => $number($data['target_value'] ?? null)],
            'constraints' => [
                'totalActiveMinPct' => $number($data['total_active_min_pct'] ?? null),
                'totalActiveMaxPct' => $number($data['total_active_max_pct'] ?? null),
                'functionBounds' => [],
                'requiredMaterials' => $bounds->filter(fn (array $bound): bool => (bool) ($bound['required'] ?? false))->pluck('material_id')->values()->all(),
                'forbiddenMaterials' => array_values(array_unique($data['forbidden'] ?? [])),
                'maxCostEGPperKg' => $number($data['max_cost_egp_per_kg'] ?? null),
                'materialBounds' => $bounds->map(fn (array $bound): array => ['materialId' => $bound['material_id'], 'min' => $number($bound['min'] ?? null), 'max' => $number($bound['max'] ?? null)])->values()->all(),
                'measuredRequirements' => [],
            ],
        ];
    }
}
