<?php

namespace App\Services\Packaging;

use App\Enums\ItemKind;
use App\Models\PackagingBom;
use App\Models\PackagingBomLine;
use App\Models\SanadProduct;
use App\Models\TradeMaterial;
use App\Support\DecimalNormalizer;

/** What the BOM editor shows: the BOM, its lines as typed (a figure not recorded stays null), and the lookups of the line form. */
final class PackagingBomDetailService
{
    /**
     * @return array<string, mixed>
     */
    public function bom(PackagingBom $bom): array
    {
        return [
            'id' => $bom->getKey(),
            'name' => $bom->name,
            'sku_code' => $bom->sku_code,
            'sanad_product_uuid' => $bom->sanad_product_uuid,
            'sanad_product_name' => $bom->sanad_product_uuid === null ? null : SanadProduct::query()->whereKey($bom->sanad_product_uuid)->value('name'),
            'units_per_carton' => $bom->units_per_carton,
            'cartons_per_pallet' => $bom->cartons_per_pallet,
            'note' => $bom->note,
            'image' => $bom->hasImage() ? [
                'name' => $bom->image_name,
                'mime' => $bom->image_mime,
                'size' => $bom->image_size,
                'version' => $bom->updated_at?->getTimestamp(),
            ] : null,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function lines(PackagingBom $bom): array
    {
        $number = fn (?string $value): ?string => $value === null ? null : DecimalNormalizer::normalize($value);

        return $bom->lines()->with(['tradeMaterial', 'source'])->get()
            ->map(fn (PackagingBomLine $line): array => [
                'id' => $line->getKey(),
                'trade_material_id' => $line->trade_material_id,
                'name' => $line->tradeMaterial->trade_name,
                'item_kind' => $line->tradeMaterial->item_kind->value,
                'neck_finish' => $line->tradeMaterial->neck_finish,
                'level' => $line->level->value,
                'qty_per_unit' => $number($line->qty_per_unit),
                'reject_pct' => $number($line->reject_pct),
                'price_egp' => $number($line->price_egp),
                'source_id' => $line->source_id,
                'source_title' => $line->source?->title,
                'confidence' => $line->confidence?->value,
            ])
            ->all();
    }

    /** @return list<array{value: string, label: string, description: string}> Every packaging component the library has, to pick a line from. */
    public function componentOptions(): array
    {
        return TradeMaterial::query()->packaging()->orderBy('trade_name')->get()
            ->map(fn (TradeMaterial $item): array => [
                'value' => $item->getKey(),
                'label' => $item->trade_name,
                'description' => collect([$item->item_kind->label(), $item->item_kind === ItemKind::Container || $item->neck_finish !== null ? $item->neck_finish : null])->filter()->implode(' · '),
            ])
            ->all();
    }
}
