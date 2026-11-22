<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * What a trade-material row is: an ingredient (raw material) or one of the packaging component kinds of the data model
 * (plan/02-specs/data-model.sql `items.kind`). Packaging is additive columns on trade_materials, not a parallel table.
 */
enum ItemKind: string
{
    use HasOptions;

    case RawMaterial = 'raw_material';
    case Container = 'container';
    case Closure = 'closure';
    case PumpSprayer = 'pump_sprayer';
    case Label = 'label';
    case Sleeve = 'sleeve';
    case UnitBox = 'unit_box';
    case ShippingCarton = 'shipping_carton';
    case Divider = 'divider';
    case Tape = 'tape';
    case StretchFilm = 'stretch_film';
    case Pallet = 'pallet';
    case Consumable = 'consumable';

    public function isPackaging(): bool
    {
        return $this !== self::RawMaterial;
    }

    /** Only these kinds have a fill capacity and a neck finish (a bottle, jar or tube). */
    public function isContainer(): bool
    {
        return $this === self::Container;
    }

    /** @return list<self> */
    public static function packagingKinds(): array
    {
        return array_values(array_filter(self::cases(), fn (self $kind): bool => $kind->isPackaging()));
    }

    /** @return list<array{value: string, label: string}> */
    public static function packagingOptions(): array
    {
        return array_map(fn (self $kind): array => ['value' => $kind->value, 'label' => $kind->label()], self::packagingKinds());
    }

    /** @return list<string> */
    public static function packagingValues(): array
    {
        return array_map(fn (self $kind): string => $kind->value, self::packagingKinds());
    }
}
