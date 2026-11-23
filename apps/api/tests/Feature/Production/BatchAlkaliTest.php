<?php

namespace Tests\Feature\Production;

use App\Enums\Confidence;
use App\Enums\FormulaStatus;
use App\Enums\Production\ProductionOrderStatus;
use App\Models\Formula;
use App\Models\ProductionBatch;
use App\Models\ProductionBatchLine;
use App\Models\PropertyValue;
use App\Models\Source;
use App\Models\Substance;
use App\Models\Tenant;
use App\Models\TradeMaterial;
use App\Services\Formula\FormulaVersionService;
use App\Services\Production\BatchAlkaliService;
use App\Services\Production\ProductionBatchService;
use App\Services\Production\ProductionOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Q-03: the alkali recomputed with the COA values of the lots really weighed, by the engine's soap and neutralisation functions.
 * TEST-FIXTURE values (hand-computed in the engine tests as well): acid value 180 -> 190 mgKOH/g, KOH purity 90 -> 80 %.
 */
class BatchAlkaliTest extends TestCase
{
    use RefreshDatabase;

    private TradeMaterial $acid;

    private TradeMaterial $base;

    private TradeMaterial $oil;

    private Source $source;

    private Source $coa;

    private ProductionBatchService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ProductionBatchService::class);
        $this->source = Source::factory()->create(['title' => 'Supplier data sheet']);
        $this->coa = Source::factory()->create(['title' => 'Lot certificate']);
    }

    private function value(TradeMaterial $material, string $property, string $unit, string $value, ?string $lot = null): void
    {
        PropertyValue::factory()->create([
            'entity_type' => 'trade_material',
            'entity_id' => $material->id,
            'property' => $property,
            'unit' => $unit,
            'value' => $value,
            'lot_no' => $lot,
            'source_id' => $lot === null ? $this->source->id : $this->coa->id,
            'confidence' => $lot === null ? Confidence::L2SupplierTds : Confidence::L1CoaBatch,
        ]);
    }

    /** A 2.5 kg batch of a formula made of the given materials and a q.s. water line. */
    private function batchOf(array $lines): ProductionBatch
    {
        $formula = Formula::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);
        $water = TradeMaterial::factory()->create(['trade_name' => 'Water', 'substance_id' => null]);
        $version = app(FormulaVersionService::class)->createVersion($formula, [
            ...array_map(fn (array $line): array => ['trade_material_id' => $line[0]->id, 'percent_ww' => $line[1]], $lines),
            ['trade_material_id' => $water->id, 'percent_ww' => null, 'is_qs' => true],
        ], null);
        $version->update(['status' => FormulaStatus::Approved]);
        $order = app(ProductionOrderService::class)->create($version->refresh(), ['batch_size' => '2.5', 'batch_unit' => 'kg', 'scale_resolution_g' => '1', 'planned_on' => '2026-11-25']);
        $order->forceFill(['status' => ProductionOrderStatus::Approved, 'approved_at' => now()])->save();

        return $this->service->start($order, 'Operator A', null);
    }

    private function weigh(ProductionBatch $batch, TradeMaterial $material, string $actual, ?string $lot): void
    {
        $line = $batch->lines()->get()->first(fn (ProductionBatchLine $line): bool => $line->trade_material_id === $material->id);
        $this->service->weigh($batch, $line, ['actual_quantity' => $actual, 'lot_number' => $lot, 'weighed_by' => 'Operator A']);
    }

    private function neutralisationBatch(): ProductionBatch
    {
        $this->acid = TradeMaterial::factory()->create(['trade_name' => 'LABSA', 'substance_id' => null]);
        $this->base = TradeMaterial::factory()->create(['trade_name' => 'KOH', 'substance_id' => Substance::factory()->create(['cas' => '1310-58-3'])->id]);
        $this->value($this->acid, 'acid_value', 'mgKOH/g', '180');
        $this->value($this->base, 'purity', '%w/w', '90');

        // 2.5 kg: LABSA 40 % = 1000 g, KOH 6 % = 150 g.
        return $this->batchOf([[$this->acid, '40'], [$this->base, '6']]);
    }

    public function test_the_alkali_is_recomputed_with_the_acid_value_and_the_purity_of_the_lots(): void
    {
        $batch = $this->neutralisationBatch();
        $this->value($this->acid, 'acid_value', 'mgKOH/g', '190', 'L-ACID');
        $this->value($this->base, 'purity', '%w/w', '80', 'L-BASE');
        $this->weigh($batch, $this->acid, '1000', 'L-ACID');
        $this->weigh($batch, $this->base, '150', 'L-BASE');

        $alkali = app(BatchAlkaliService::class)->forBatch($batch->refresh(), null);

        // 1000 g x 180/1000 / 0.9 = 200 g at the reference values; 1000 x 190/1000 / 0.8 = 237.5 g at the lot values: +37.5 g, +18.75 %.
        $this->assertSame(['neutralize', 'KOH', []], [$alkali['kind'], $alkali['base'], $alkali['missing']]);
        $this->assertSame(['200', '237.5', '37.5', '18.750'], [$alkali['result']['plannedG'], $alkali['result']['lotG'], $alkali['result']['deviation']['difference']['value'], $alkali['result']['deviation']['differencePctDisplay']]);
        $this->assertEqualsCanonicalizing(['acid_value'], collect($alkali['lots'])->firstWhere('lot', 'L-ACID')['properties']);
    }

    public function test_the_mass_used_is_the_one_really_weighed(): void
    {
        $batch = $this->neutralisationBatch();
        $this->value($this->acid, 'acid_value', 'mgKOH/g', '190', 'L-ACID');
        $this->value($this->base, 'purity', '%w/w', '80', 'L-BASE');
        $this->weigh($batch, $this->acid, '500', 'L-ACID');
        $this->weigh($batch, $this->base, '75', 'L-BASE');

        $alkali = app(BatchAlkaliService::class)->forBatch($batch->refresh(), null);

        // Half the acid: 500 x 180/1000 / 0.9 = 100 g against 500 x 190/1000 / 0.8 = 118.75 g.
        $this->assertSame(['100', '118.75'], [$alkali['result']['plannedG'], $alkali['result']['lotG']]);
    }

    public function test_a_lot_with_no_certificate_values_stops_the_calculation_and_is_named(): void
    {
        $batch = $this->neutralisationBatch();
        $this->weigh($batch, $this->acid, '1000', 'L-ACID');
        $this->weigh($batch, $this->base, '150', 'L-BASE');

        $alkali = app(BatchAlkaliService::class)->forBatch($batch->refresh(), null);

        $this->assertTrue($alkali['applicable']);
        $this->assertNull($alkali['result']);
        $this->assertCount(2, $alkali['missing']);
        $this->assertStringContainsString('LABSA', $alkali['missing'][0]);
        $this->assertStringContainsString(__('engine.production.lot_coa_missing'), $alkali['missing'][0]);
        $this->assertSame([], collect($alkali['lots'])->firstWhere('lot', 'L-ACID')['properties'], 'The lot has no certificate values on file.');
    }

    public function test_a_lot_value_in_another_unit_than_the_reference_is_not_used(): void
    {
        $batch = $this->neutralisationBatch();
        $this->value($this->acid, 'acid_value', 'mgKOH/g', '190', 'L-ACID');
        $this->weigh($batch, $this->acid, '1000', 'L-ACID');
        $this->weigh($batch, $this->base, '150', null);

        $alkali = app(BatchAlkaliService::class)->forBatch($batch->refresh(), null);

        $this->assertNull($alkali['result']);
        $this->assertCount(1, $alkali['missing'], 'Only the base has no certificate: the acid lot is complete.');
        $this->assertStringContainsString(__('production.alkali.properties.strength_lot'), $alkali['missing'][0]);
    }

    public function test_a_soap_needs_the_superfat_typed_and_is_recomputed_with_the_lot_sap_and_purity(): void
    {
        $this->oil = TradeMaterial::factory()->create(['trade_name' => 'Olive oil', 'substance_id' => null]);
        $this->base = TradeMaterial::factory()->create(['trade_name' => 'KOH', 'substance_id' => Substance::factory()->create(['cas' => '1310-58-3'])->id]);
        $this->value($this->oil, 'sap', 'mgKOH/g', '190');
        $this->value($this->base, 'purity', '%w/w', '100');
        $this->value($this->oil, 'sap', 'mgKOH/g', '200', 'L-OIL');
        $this->value($this->base, 'purity', '%w/w', '80', 'L-BASE');
        // 2.5 kg: oil 40 % = 1000 g, KOH 8 %.
        $batch = $this->batchOf([[$this->oil, '40'], [$this->base, '8']]);
        $this->weigh($batch, $this->oil, '1000', 'L-OIL');
        $this->weigh($batch, $this->base, '200', 'L-BASE');

        $needs = app(BatchAlkaliService::class)->forBatch($batch->refresh(), null);
        $this->assertSame(['soap', 'superfat', null], [$needs['kind'], $needs['needs'], $needs['result']]);

        // Superfat 0: 1000 g x 190/1000 = 190 g at the reference; 1000 x 200/1000 / 0.8 = 250 g at the lot values.
        $alkali = app(BatchAlkaliService::class)->forBatch($batch->refresh(), '0');
        $this->assertSame(['190', '250', '60', '31.579'], [$alkali['result']['plannedG'], $alkali['result']['lotG'], $alkali['result']['deviation']['difference']['value'], $alkali['result']['deviation']['differencePctDisplay']]);

        $withSuperfat = app(BatchAlkaliService::class)->forBatch($batch->refresh(), '10');
        $this->assertSame(['171', '225'], [$withSuperfat['result']['plannedG'], $withSuperfat['result']['lotG']]);
    }

    public function test_a_batch_with_no_known_alkali_is_not_applicable_and_the_page_shows_the_lots_anyway(): void
    {
        $other = TradeMaterial::factory()->create(['trade_name' => 'Glycerin', 'substance_id' => null]);
        $batch = $this->batchOf([[$other, '10']]);
        $this->weigh($batch, $other, '250', 'L-GLY');

        $alkali = app(BatchAlkaliService::class)->forBatch($batch->refresh(), null);

        $this->assertFalse($alkali['applicable']);
        $this->assertSame('L-GLY', $alkali['lots'][0]['lot']);
        $props = $this->get(route('production.batches.show', $batch))->original->getData()['page']['props'];
        $this->assertFalse($props['alkali']['applicable']);
    }

    public function test_the_page_takes_the_superfat_from_the_query_string(): void
    {
        $this->oil = TradeMaterial::factory()->create(['trade_name' => 'Olive oil', 'substance_id' => null]);
        $this->base = TradeMaterial::factory()->create(['trade_name' => 'KOH', 'substance_id' => Substance::factory()->create(['cas' => '1310-58-3'])->id]);
        $this->value($this->oil, 'sap', 'mgKOH/g', '190');
        $this->value($this->base, 'purity', '%w/w', '100');
        $this->value($this->oil, 'sap', 'mgKOH/g', '200', 'L-OIL');
        $this->value($this->base, 'purity', '%w/w', '100', 'L-BASE');
        $batch = $this->batchOf([[$this->oil, '40'], [$this->base, '8']]);
        $this->weigh($batch, $this->oil, '1000', 'L-OIL');
        $this->weigh($batch, $this->base, '200', 'L-BASE');

        $props = $this->get(route('production.batches.show', [$batch, 'superfat_pct' => '5']))->original->getData()['page']['props'];

        $this->assertSame(['180.5', '190'], [$props['alkali']['result']['plannedG'], $props['alkali']['result']['lotG']]);
    }
}
