<?php

namespace App\Services\Material;

use App\Enums\Confidence;
use App\Enums\FunctionalClass;
use App\Enums\TradeMaterialStatus;
use App\Http\Filters\ListQuery;
use App\Models\Price;
use App\Models\TradeMaterial;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** Read side of the materials library: the filtered, sorted, paginated list and its option lookups. */
final class MaterialListService
{
    /**
     * The whole `list` prop for /materials.
     *
     * @return array<string, mixed>
     */
    public function list(Request $request, string $functionOptionsUrl): array
    {
        return ListQuery::for($this->listing(), $request)
            ->search(['trade_name', 'grade', 'substance.inci', 'substance.cas'], 'materials.search_placeholder')
            ->select('status', TradeMaterialStatus::class, labelKey: 'materials.filters.status')
            ->select('function', optionsUrl: $functionOptionsUrl, labelKey: 'materials.filters.function')
            ->select('functional_class', FunctionalClass::class, labelKey: 'materials.filters.functional_class', using: fn (Builder $query, string $class) => $query->whereJsonContains('trade_materials.functional_classes', $class))
            ->boolean('has_sanad_link', labelKey: 'materials.filters.has_sanad_link')
            ->select('worst_price_confidence', Confidence::class, labelKey: 'materials.filters.worst_price_confidence')
            ->boolean('is_mixture', labelKey: 'materials.filters.is_mixture')
            ->sortable(['trade_name', 'updated_at'], default: 'trade_name', labelPrefix: 'materials.sorts')
            ->perPage(25, [10, 25, 50, 100])
            ->paginate(fn (TradeMaterial $material): array => [
                'id' => $material->getKey(),
                'trade_name' => $material->trade_name,
                'grade' => $material->grade,
                'function' => $material->function,
                'functional_classes' => $material->functional_classes ?? [],
                'status' => $material->status->value,
                'is_mixture' => $material->is_mixture,
                'has_sanad_link' => (bool) $material->getAttribute('has_sanad_link'),
                'worst_price_confidence' => $material->getAttribute('worst_price_confidence'),
                'substance' => $material->substance === null ? null : [
                    'inci' => $material->substance->inci,
                    'cas' => $material->substance->cas,
                    'name_en' => $material->substance->name_en,
                ],
                'updated_at' => $material->updated_at?->toDateString(),
            ]);
    }

    /** @return list<array{value: string, label: string}> */
    public function functionOptions(): array
    {
        return TradeMaterial::query()->whereNotNull('function')->distinct()->orderBy('function')->pluck('function')
            ->map(fn (string $function): array => ['value' => $function, 'label' => $function])
            ->values()
            ->all();
    }

    /**
     * Trade materials with two derived columns the filters need: whether a Sanad product is linked, and the worst
     * confidence among the material's price quotes (L1 ... L7 sort alphabetically, so max() is the worst).
     *
     * @return Builder<TradeMaterial>
     */
    private function listing(): Builder
    {
        $derived = TradeMaterial::query()
            ->ingredients()
            ->select('trade_materials.*')
            ->selectRaw('(trade_materials.sanad_product_uuid is not null) as has_sanad_link')
            ->selectSub(
                Price::query()->selectRaw('max(prices.confidence)')->whereColumn('prices.trade_material_id', 'trade_materials.id'),
                'worst_price_confidence',
            );

        return TradeMaterial::query()->fromSub($derived, 'trade_materials')->with('substance');
    }
}
