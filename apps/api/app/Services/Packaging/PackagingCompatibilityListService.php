<?php

namespace App\Services\Packaging;

use App\Enums\Confidence;
use App\Enums\ItemKind;
use App\Http\Filters\ListQuery;
use App\Models\PackagingCompatibility;
use App\Models\TradeMaterial;
use Illuminate\Http\Request;

/** Read side of the declared neck finish pairs, and the component options of the BOM check. */
final class PackagingCompatibilityListService
{
    /**
     * The whole `list` prop for /packaging/compatibility.
     *
     * @return array<string, mixed>
     */
    public function list(Request $request, string $containerNeckOptionsUrl, string $closureNeckOptionsUrl): array
    {
        return ListQuery::for(PackagingCompatibility::query()->with('source'), $request)
            ->search(['container_neck_finish', 'closure_neck_finish', 'note'], 'packaging.compat.search_placeholder')
            ->select('container_neck_finish', optionsUrl: $containerNeckOptionsUrl, labelKey: 'packaging.compat.filters.container_neck_finish')
            ->select('closure_neck_finish', optionsUrl: $closureNeckOptionsUrl, labelKey: 'packaging.compat.filters.closure_neck_finish')
            ->select('confidence', Confidence::class, labelKey: 'packaging.compat.filters.confidence')
            ->dateRange('created_at', labelKey: 'packaging.compat.filters.created_at')
            ->sortable(['container_neck_finish', 'closure_neck_finish', 'confidence', 'created_at'], default: 'container_neck_finish', labelPrefix: 'packaging.compat.sorts')
            ->perPage(25, [10, 25, 50, 100])
            ->paginate(fn (PackagingCompatibility $pair): array => [
                'id' => $pair->getKey(),
                'container_neck_finish' => $pair->container_neck_finish,
                'closure_neck_finish' => $pair->closure_neck_finish,
                'source_title' => $pair->source?->title,
                'confidence' => $pair->confidence->value,
                'note' => $pair->note,
                'created_at' => $pair->created_at?->toDateString(),
            ]);
    }

    /** @return list<array{value: string, label: string}> The finishes already declared for containers (or for closures). */
    public function finishOptions(string $side): array
    {
        $column = $side === 'closure' ? 'closure_neck_finish' : 'container_neck_finish';

        return PackagingCompatibility::query()->distinct()->orderBy($column)->pluck($column)
            ->map(fn (string $finish): array => ['value' => $finish, 'label' => $finish])
            ->values()
            ->all();
    }

    /**
     * Containers or closures the BOM check can pick: the packaging library with the neck finish each one has.
     *
     * @param  list<ItemKind>  $kinds
     * @return list<array{value: string, label: string, description: string}>
     */
    public function componentOptions(array $kinds): array
    {
        return TradeMaterial::query()->packaging()->whereIn('item_kind', array_map(fn (ItemKind $kind): string => $kind->value, $kinds))->orderBy('trade_name')->get()
            ->map(fn (TradeMaterial $item): array => [
                'value' => $item->getKey(),
                'label' => $item->trade_name,
                'description' => $item->item_kind->label().' · '.($item->neck_finish ?? __('packaging.not_recorded')),
            ])
            ->all();
    }
}
