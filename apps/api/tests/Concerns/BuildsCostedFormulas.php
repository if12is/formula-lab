<?php

namespace Tests\Concerns;

use App\Enums\Confidence;
use App\Enums\FormulaStatus;
use App\Enums\SourceKind;
use App\Models\Formula;
use App\Models\FormulaLine;
use App\Models\FormulaVersion;
use App\Models\Price;
use App\Models\Source;
use App\Models\TradeMaterial;

/**
 * A product with hand-computable costs (TEST-FIXTURE numbers): material A 60 % at 10 EGP/kg (an invoice) and material B 40 % at
 * 25 EGP/kg (a manual entry) cost 6 + 10 = 16 EGP per kg. The unit cost kept on the product is 18.4573.
 */
trait BuildsCostedFormulas
{
    protected TradeMaterial $materialA;

    protected TradeMaterial $materialB;

    protected Price $priceA;

    protected Price $priceB;

    /** @param  array<string, mixed>  $attributes */
    protected function costedProduct(string $tenantId, array $attributes = [], bool $approved = true): Formula
    {
        $formula = Formula::factory()->create($attributes + [
            'tenant_id' => $tenantId,
            'unit_cost' => '18.4573',
            'unit_cost_source_id' => Source::factory()->create(['title' => 'Cost sheet 2026-11'])->id,
            'unit_cost_confidence' => Confidence::L2SupplierTds,
        ]);

        $this->materialA = TradeMaterial::factory()->create(['trade_name' => 'Material A']);
        $this->materialB = TradeMaterial::factory()->create(['trade_name' => 'Material B']);
        $invoice = Source::factory()->create(['kind' => SourceKind::SupplierInvoice->value, 'title' => 'INV-2026-0042']);
        $manual = Source::factory()->create(['kind' => SourceKind::SupplierTds->value, 'title' => 'Phone quote']);
        $this->priceA = Price::factory()->create(['trade_material_id' => $this->materialA->id, 'amount' => '10', 'source_id' => $invoice->id, 'confidence' => Confidence::L1CoaBatch, 'quoted_on' => '2026-11-01']);
        $this->priceB = Price::factory()->create(['trade_material_id' => $this->materialB->id, 'amount' => '25', 'source_id' => $manual->id, 'confidence' => Confidence::L2SupplierTds, 'quoted_on' => '2026-11-02']);

        $this->version($formula, 1, [[$this->materialA, '60'], [$this->materialB, '40']], $approved);

        return $formula;
    }

    /**
     * A version with its lines; approved last, because an approved version and its lines can never change.
     *
     * @param  list<array{0: TradeMaterial, 1: string}>  $lines
     */
    protected function version(Formula $formula, int $number, array $lines, bool $approved = true): FormulaVersion
    {
        $version = FormulaVersion::factory()->create(['formula_id' => $formula->id, 'version' => $number, 'reason' => $number > 1 ? 'Test change' : null]);
        foreach ($lines as $index => [$material, $percent]) {
            FormulaLine::factory()->create(['formula_version_id' => $version->id, 'line_no' => $index + 1, 'trade_material_id' => $material->id, 'percent_ww' => $percent]);
        }
        if ($approved) {
            $version->update(['status' => FormulaStatus::Approved]);
        }

        return $version;
    }
}
