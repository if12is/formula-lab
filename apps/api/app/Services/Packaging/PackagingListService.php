<?php

namespace App\Services\Packaging;

use App\Enums\Confidence;
use App\Enums\ItemKind;
use App\Enums\PackagingMaterial;
use App\Http\Filters\ListQuery;
use App\Models\SanadSupplier;
use App\Models\TradeMaterial;
use App\Support\DecimalNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** Read side of the packaging library: the filtered, sorted, paginated list and its option lookups. */
final class PackagingListService
{
    /**
     * The whole `list` prop for /packaging.
     *
     * @return array<string, mixed>
     */
    public function list(Request $request, string $neckOptionsUrl): array
    {
        $query = TradeMaterial::query()->packaging()->with('packagingSource');

        return ListQuery::for($query, $request)
            ->search(['trade_name', 'neck_finish'], 'packaging.search_placeholder')
            ->select('item_kind', ItemKind::packagingOptions(), labelKey: 'packaging.filters.item_kind')
            ->select('packaging_material', PackagingMaterial::class, labelKey: 'packaging.filters.packaging_material')
            ->select('neck_finish', optionsUrl: $neckOptionsUrl, labelKey: 'packaging.filters.neck_finish')
            ->select('packaging_confidence', Confidence::class, labelKey: 'packaging.filters.confidence')
            ->numberRange('nominal_capacity_ml', labelKey: 'packaging.filters.nominal_capacity_ml')
            ->numberRange('brimful_capacity_ml', labelKey: 'packaging.filters.brimful_capacity_ml')
            ->numberRange('unit_weight_g', labelKey: 'packaging.filters.unit_weight_g')
            ->select('has_spec', [
                ['value' => '1', 'labelKey' => 'packaging.has_spec.yes'],
                ['value' => '0', 'labelKey' => 'packaging.has_spec.no'],
            ], labelKey: 'packaging.filters.has_spec', using: fn (Builder $q, string $value) => $value === '1' ? $q->whereNotNull('packaging_source_id') : $q->whereNull('packaging_source_id'))
            ->sortable(['trade_name', 'item_kind', 'nominal_capacity_ml', 'brimful_capacity_ml', 'unit_weight_g', 'updated_at'], default: 'trade_name', labelPrefix: 'packaging.sorts')
            ->perPage(25, [10, 25, 50, 100])
            ->paginate(fn (TradeMaterial $item): array => $this->row($item));
    }

    /** @return list<array{value: string, label: string}> */
    public function neckFinishOptions(): array
    {
        return TradeMaterial::query()->packaging()->whereNotNull('neck_finish')->distinct()->orderBy('neck_finish')->pluck('neck_finish')
            ->map(fn (string $finish): array => ['value' => $finish, 'label' => $finish])
            ->values()
            ->all();
    }

    /** @return list<array{value: string, label: string}> The cached Sanad suppliers a component can come from. */
    public function supplierOptions(): array
    {
        return SanadSupplier::query()->where('deleted', false)->orderBy('name')->get()
            ->map(fn (SanadSupplier $supplier): array => ['value' => $supplier->uuid, 'label' => $supplier->name])
            ->all();
    }

    /**
     * One component as the screen reads it. A value that was never recorded is null (not zero).
     *
     * @return array<string, mixed>
     */
    public function row(TradeMaterial $item): array
    {
        $number = fn (?string $value): ?string => $value === null ? null : DecimalNormalizer::normalize($value);

        return [
            'id' => $item->getKey(),
            'trade_name' => $item->trade_name,
            'item_kind' => $item->item_kind->value,
            'packaging_material' => $item->packaging_material?->value,
            'nominal_capacity_ml' => $number($item->nominal_capacity_ml),
            'brimful_capacity_ml' => $number($item->brimful_capacity_ml),
            'unit_weight_g' => $number($item->unit_weight_g),
            'neck_finish' => $item->neck_finish,
            'sanad_supplier_uuid' => $item->sanad_supplier_uuid,
            'supplier_name' => $item->sanad_supplier_uuid === null ? null : SanadSupplier::query()->whereKey($item->sanad_supplier_uuid)->value('name'),
            'packaging_source_id' => $item->packaging_source_id,
            'source_title' => $item->packagingSource?->title,
            'packaging_confidence' => $item->packaging_confidence?->value,
            'has_sanad_link' => $item->sanad_product_uuid !== null,
            'updated_at' => $item->updated_at?->toDateString(),
        ];
    }
}
