<?php

namespace Tests\Concerns;

use App\Enums\Confidence;
use App\Models\ExchangeRate;
use App\Models\Price;
use App\Models\PropertyValue;
use App\Models\Source;
use App\Models\TradeMaterial;

/**
 * Materials with hand-computable prices and active matter (TEST-FIXTURE numbers): water at 1 EGP/kg with 0 % active matter, and a surfactant
 * quoted 2 USD/kg with the dollar at 50 EGP (so 100 EGP/kg) and 50 % active matter. Active matter >= 10 % costs 20 % surfactant + 80 % water = 20.8 EGP/kg.
 */
trait BuildsTierMaterials
{
    protected TradeMaterial $water;

    protected TradeMaterial $surfactant;

    protected Source $source;

    protected function tierMaterials(): void
    {
        $this->source = Source::factory()->create(['title' => 'Test decision']);
        $this->water = TradeMaterial::factory()->active()->create(['trade_name' => 'Water']);
        $this->surfactant = TradeMaterial::factory()->active()->create(['trade_name' => 'Surfactant']);
        Price::factory()->create(['trade_material_id' => $this->water->id, 'amount' => '1', 'currency' => 'EGP', 'quoted_on' => '2026-11-01', 'source_id' => $this->source->id, 'confidence' => Confidence::L2SupplierTds]);
        Price::factory()->create(['trade_material_id' => $this->surfactant->id, 'amount' => '2', 'currency' => 'USD', 'quoted_on' => '2026-11-01', 'source_id' => $this->source->id, 'confidence' => Confidence::L2SupplierTds]);
        ExchangeRate::factory()->create(['from_currency' => 'USD', 'rate' => '50', 'rate_date' => '2026-10-31', 'confidence' => Confidence::L2SupplierTds]);
        $this->activeMatter($this->water, '0');
        $this->activeMatter($this->surfactant, '50');
    }

    protected function activeMatter(TradeMaterial $material, string $value): PropertyValue
    {
        return PropertyValue::factory()->create([
            'entity_type' => 'trade_material', 'entity_id' => $material->id, 'property' => 'active_matter', 'value' => $value, 'unit' => '%w/w',
            'source_id' => $this->source->id, 'confidence' => Confidence::L2SupplierTds, 'valid_from' => '2026-11-01',
        ]);
    }
}
