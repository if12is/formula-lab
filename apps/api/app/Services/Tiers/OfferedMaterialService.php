<?php

namespace App\Services\Tiers;

use App\Actions\Tenancy\EnsureDefaultTenantAction;
use App\Enums\TradeMaterialStatus;
use App\Models\PropertyValue;
use App\Models\TradeMaterial;
use App\Services\Material\MaterialOptionService;
use App\Support\Engine\PriceQuotes;
use Illuminate\Support\Collection;

/**
 * The materials the tier optimizer may choose from: the active ingredients, each with its price quotes (with the dated exchange rate of
 * the quote day) and its active-matter values, exactly as stored. Nothing is chosen or converted here: the engine picks the quote and
 * the value, converts to EGP, and leaves out (and says so) a material that has no usable price or no active matter on file.
 */
final class OfferedMaterialService
{
    public function __construct(
        private readonly EnsureDefaultTenantAction $ensureDefaultTenant,
        private readonly MaterialOptionService $materialOptions,
    ) {}

    /**
     * @return array{materials: list<array<string, mixed>>, fxRates: list<array<string, mixed>>, names: array<string, string>}
     */
    public function offered(): array
    {
        $tenantId = ($this->ensureDefaultTenant)()->getKey();
        /** @var Collection<int, TradeMaterial> $materials */
        $materials = TradeMaterial::query()
            ->ingredients()
            ->where('status', TradeMaterialStatus::Active)
            ->where(fn ($query) => $query->whereNull('tenant_id')->orWhere('tenant_id', $tenantId))
            ->orderBy('trade_name')
            ->get();

        $options = $this->materialOptions->forMaterials($materials);
        $active = PropertyValue::query()
            ->where('entity_type', 'trade_material')
            ->where('property', 'active_matter')
            ->whereNotNull('value')
            ->whereIn('entity_id', $materials->map(fn (TradeMaterial $material) => $material->getKey())->all())
            ->get()
            ->groupBy('entity_id');

        $items = [];
        $fxRates = [];
        foreach ($materials as $material) {
            $prices = $options[$material->getKey()]['prices'];
            $items[] = [
                'materialId' => $material->getKey(),
                'quotes' => PriceQuotes::candidates($prices),
                'activeMatter' => $active->get($material->getKey(), collect())->map(fn (PropertyValue $row): array => [
                    'value' => $row->value,
                    'unit' => $row->unit,
                    'sourceId' => $row->source_id,
                    'confidence' => $row->confidence->value,
                    'validFrom' => $row->valid_from->toDateString(),
                ])->values()->all(),
            ];
            $fxRates = [...$fxRates, ...PriceQuotes::fxRates($prices)];
        }

        return ['materials' => $items, 'fxRates' => $fxRates, 'names' => $materials->pluck('trade_name', 'id')->all()];
    }
}
