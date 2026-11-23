<?php

namespace Database\Factories;

use App\Enums\Confidence;
use App\Enums\ItemKind;
use App\Enums\PackLevel;
use App\Models\PackagingBom;
use App\Models\PackagingBomLine;
use App\Models\Source;
use App\Models\TradeMaterial;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PackagingBomLine> */
class PackagingBomLineFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'packaging_bom_id' => PackagingBom::factory(),
            'trade_material_id' => TradeMaterial::factory()->packaging(ItemKind::Container),
            'level' => PackLevel::Unit,
            'qty_per_unit' => null,
            'reject_pct' => null,
            'price_egp' => null,
            'source_id' => null,
            'confidence' => null,
            'position' => 0,
        ];
    }

    /** A line with every figure filled and the source it came from. */
    public function sourced(string $price, string $qty = '1', string $reject = '0'): static
    {
        return $this->state(fn () => [
            'price_egp' => $price,
            'qty_per_unit' => $qty,
            'reject_pct' => $reject,
            'source_id' => Source::factory(),
            'confidence' => Confidence::L2SupplierTds,
        ]);
    }
}
