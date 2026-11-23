<?php

namespace Tests\Feature\Production;

use App\Enums\ItemKind;
use App\Enums\Production\ProductionOrderStatus;
use App\Models\ProductionBatch;
use App\Models\ProductionBatchLine;
use App\Models\TradeMaterial;
use App\Services\Production\ProductionBatchService;
use App\Services\Production\ProductionOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsProductionFixtures;
use Tests\TestCase;

/** Q-04 (waste, reporting side) and Q-09 (actual cost against the computed one): every number is the engine's, a gap is named, never zero. */
class ProductionResultsTest extends TestCase
{
    use BuildsProductionFixtures, RefreshDatabase;

    private ProductionBatchService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ProductionBatchService::class);
    }

    private function batch(array $packaging = []): ProductionBatch
    {
        $version = $this->productionProduct();
        $order = app(ProductionOrderService::class)->create($version, $this->orderData(['packaging' => $packaging]));
        $order->forceFill(['status' => ProductionOrderStatus::Approved, 'approved_at' => now()])->save();

        return $this->service->start($order, 'Operator A', null);
    }

    private function weigh(ProductionBatch $batch, string $name, string $actual, ?string $unitCost = null): void
    {
        $line = $batch->lines()->get()->first(fn (ProductionBatchLine $line): bool => $line->tradeMaterial->trade_name === $name);
        $this->service->weigh($batch, $line, ['actual_quantity' => $actual, 'weighed_by' => 'Operator A', 'unit_cost' => $unitCost]);
    }

    private function props(ProductionBatch $batch): array
    {
        return $this->pageProps($this->get(route('production.batches.show', $batch))->assertOk());
    }

    public function test_the_waste_is_compared_with_the_planned_one_by_the_engine(): void
    {
        config(['production.deviation_threshold_pct' => '10']);
        $batch = $this->batch();
        $this->assertSame('not_recorded', $this->props($batch)['waste']['state']);

        // 2.5 kg charged = 2500 g; 2 % planned = 50 g; 60 g recorded is +10 g, +20 %.
        $this->put(route('production.batches.results.update', $batch), ['planned_waste_pct' => '2', 'actual_waste_g' => '60'])->assertSessionHasNoErrors();

        $waste = $this->props($batch)['waste'];
        $this->assertSame('compared', $waste['state']);
        $this->assertSame(['50', '10', '20.000', 'exceeds'], [$waste['result']['plannedWasteG']['value'], $waste['result']['deviation']['difference']['value'], $waste['result']['deviation']['differencePctDisplay'], $waste['result']['deviation']['verdict']]);

        $this->put(route('production.batches.results.update', $batch), ['planned_waste_pct' => '2', 'actual_waste_g' => '48'])->assertSessionHasNoErrors();
        $this->assertSame(['-4.000', 'within'], [$this->props($batch)['waste']['result']['deviation']['differencePctDisplay'], $this->props($batch)['waste']['result']['deviation']['verdict']]);
    }

    public function test_a_planned_waste_of_zero_cannot_be_compared_and_is_reported_not_hidden(): void
    {
        $batch = $this->batch();
        $this->put(route('production.batches.results.update', $batch), ['planned_waste_pct' => '0', 'actual_waste_g' => '5'])->assertSessionHasNoErrors();

        $waste = $this->props($batch)['waste'];
        $this->assertSame('incomplete', $waste['state']);
        $this->assertSame([__('engine.production.planned_not_positive')], $waste['missing']);
    }

    public function test_the_results_form_refuses_numbers_that_are_not_decimals_or_out_of_range(): void
    {
        $batch = $this->batch();
        $url = route('production.batches.results.update', $batch);

        $this->put($url, ['planned_waste_pct' => '100'])->assertSessionHasErrors('planned_waste_pct');
        $this->put($url, ['actual_waste_g' => '-3'])->assertSessionHasErrors('actual_waste_g');
        $this->put($url, ['actual_units' => '0'])->assertSessionHasErrors('actual_units');
        $this->put($url, ['actual_conversion_egp' => 'ten'])->assertSessionHasErrors('actual_conversion_egp');
        $this->assertNull($batch->fresh()->actual_waste_g);
    }

    public function test_the_actual_cost_adds_materials_packaging_and_conversion_and_is_compared_with_the_approved_unit_cost(): void
    {
        config(['production.deviation_threshold_pct' => '5']);
        $bottle = TradeMaterial::factory()->packaging(ItemKind::Container)->create(['trade_name' => 'Bottle']);
        $batch = $this->batch([['trade_material_id' => $bottle->id, 'quantity' => '10']]);
        // 300 g at 51.5 per kg = 15.45; 38 g at 10 = 0.38; 2162 g at 0.5 = 1.081: materials 16.911. Bottles: 10 at 2.5 = 25.
        $this->weigh($batch, 'Surfactant X', '300', '51.5');
        $this->weigh($batch, 'Salt', '38', '10');
        $this->weigh($batch, 'Water', '2162', '0.5');
        $this->weigh($batch, 'Bottle', '10', '2.5');
        // Conversion 10, two units: (16.911 + 25 + 10) / 2 = 25.9555 per unit; the approved unit cost is 18.4573.
        $this->put(route('production.batches.results.update', $batch), ['actual_units' => '2', 'actual_conversion_egp' => '10'])->assertSessionHasNoErrors();

        $cost = $this->props($batch)['cost'];

        $this->assertTrue($cost['ok']);
        $result = $cost['result'];
        $this->assertSame(['16.911', '25', '10', '51.911', '25.9555'], [$result['materials']['value'], $result['packaging']['value'], $result['conversion']['value'], $result['total']['value'], $result['perUnit']['value']]);
        $this->assertSame(['18.4573', '7.4982', '40.625', 'exceeds'], [$result['comparison']['planned'], $result['comparison']['difference']['value'], $result['comparison']['differencePctDisplay'], $result['comparison']['verdict']]);
        $this->assertSame('18.4573', $cost['computed']['unit_cost']);
    }

    public function test_a_lot_without_cost_a_missing_conversion_and_missing_units_are_named_and_nothing_is_totalled(): void
    {
        $batch = $this->batch();
        $this->weigh($batch, 'Surfactant X', '300', '51.5');
        $this->weigh($batch, 'Salt', '38');

        $cost = $this->props($batch)['cost'];

        $this->assertFalse($cost['ok']);
        $this->assertNull($cost['result']);
        $text = implode('|', $cost['missing']);
        $this->assertStringContainsString('3 · Salt', $text, 'The salt has no cost.');
        $this->assertStringNotContainsString('2 · Surfactant X', $text, 'The surfactant has its cost.');
        $this->assertStringContainsString('1 · Water', $text, 'The water was never weighed.');
        $this->assertStringContainsString(__('production.cost.properties.conversion'), $text);
        $this->assertStringContainsString(__('production.cost.properties.units'), $text);
    }

    public function test_with_no_approved_unit_cost_the_actual_cost_has_no_comparison_and_says_so(): void
    {
        $version = $this->productionProduct(approveCost: false);
        $order = app(ProductionOrderService::class)->create($version, $this->orderData());
        $order->forceFill(['status' => ProductionOrderStatus::Approved, 'approved_at' => now()])->save();
        $batch = $this->service->start($order, 'Operator A', null);
        $this->weigh($batch, 'Surfactant X', '300', '51.5');
        $this->weigh($batch, 'Salt', '38', '10');
        $this->weigh($batch, 'Water', '2162', '0.5');
        $this->put(route('production.batches.results.update', $batch), ['actual_units' => '1', 'actual_conversion_egp' => '0'])->assertSessionHasNoErrors();

        $cost = $this->props($batch)['cost'];

        $this->assertTrue($cost['ok']);
        $this->assertSame('16.911', $cost['result']['perUnit']['value']);
        $this->assertNull($cost['result']['comparison']);
        $this->assertNull($cost['computed']);
    }
}
