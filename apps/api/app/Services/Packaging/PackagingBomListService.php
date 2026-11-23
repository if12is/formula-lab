<?php

namespace App\Services\Packaging;

use App\Enums\Sanad\SanadItemKind;
use App\Http\Filters\ListQuery;
use App\Models\PackagingBom;
use App\Models\SanadProduct;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** Read side of the packaging BOMs: the filtered, sorted, paginated list of SKUs and the lookups of their forms. */
final class PackagingBomListService
{
    /**
     * The whole `list` prop for /packaging/boms.
     *
     * @return array<string, mixed>
     */
    public function list(Request $request): array
    {
        $yesNo = fn (string $prefix): array => [
            ['value' => '1', 'labelKey' => "{$prefix}.yes"],
            ['value' => '0', 'labelKey' => "{$prefix}.no"],
        ];

        return ListQuery::for(PackagingBom::query()->withCount('lines'), $request)
            ->search(['name', 'sku_code', 'note'], 'packaging.bom.search_placeholder')
            ->select('has_image', $yesNo('packaging.bom.has_image'), labelKey: 'packaging.bom.filters.has_image', using: fn (Builder $q, string $value) => $value === '1' ? $q->whereNotNull('image_path') : $q->whereNull('image_path'))
            ->select('has_lines', $yesNo('packaging.bom.has_lines'), labelKey: 'packaging.bom.filters.has_lines', using: fn (Builder $q, string $value) => $value === '1' ? $q->has('lines') : $q->doesntHave('lines'))
            ->select('sanad_linked', $yesNo('packaging.bom.sanad_linked_filter'), labelKey: 'packaging.bom.filters.sanad_linked', using: fn (Builder $q, string $value) => $value === '1' ? $q->whereNotNull('sanad_product_uuid') : $q->whereNull('sanad_product_uuid'))
            ->numberRange('units_per_carton', labelKey: 'packaging.bom.filters.units_per_carton')
            ->dateRange('updated_at', labelKey: 'packaging.bom.filters.updated_at')
            ->sortable(['name', 'sku_code', 'lines_count', 'units_per_carton', 'updated_at'], default: 'name', labelPrefix: 'packaging.bom.sorts')
            ->perPage(25, [10, 25, 50, 100])
            ->paginate(fn (PackagingBom $bom): array => [
                'id' => $bom->getKey(),
                'name' => $bom->name,
                'sku_code' => $bom->sku_code,
                'lines_count' => $bom->lines_count,
                'units_per_carton' => $bom->units_per_carton,
                'cartons_per_pallet' => $bom->cartons_per_pallet,
                'has_image' => $bom->hasImage(),
                'has_sanad_link' => $bom->sanad_product_uuid !== null,
                'updated_at' => $bom->updated_at?->toDateString(),
            ]);
    }

    /** @return list<array{value: string, label: string, description: string|null}> The finished goods cached from Sanad that a SKU can point at. */
    public function sanadProductOptions(): array
    {
        return SanadProduct::query()->where('deleted', false)->where('item_kind', SanadItemKind::FinishedGood->value)->orderBy('name')->get()
            ->map(fn (SanadProduct $product): array => ['value' => $product->uuid, 'label' => $product->name, 'description' => $product->sku])
            ->all();
    }
}
