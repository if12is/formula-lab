<?php

namespace Tests\Feature\Materials;

use App\Enums\Confidence;
use App\Models\Price;
use App\Models\PropertyValue;
use App\Models\Source;
use App\Models\Substance;
use App\Models\TradeMaterial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MaterialShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_shows_the_facts_the_properties_the_prices_and_the_audit_trail(): void
    {
        $substance = Substance::factory()->create(['inci' => 'GLYCERIN', 'cas' => '56-81-5']);
        $material = TradeMaterial::factory()->for($substance)->create(['trade_name' => 'Glycerin 99.5']);
        $source = Source::factory()->create(['title' => 'Supplier sheet', 'kind' => 'supplier_tds']);
        PropertyValue::factory()->create(['entity_type' => 'trade_material', 'entity_id' => $material->id, 'property' => 'density', 'value' => '1.26', 'unit' => 'g/mL', 'valid_from' => '2026-03-01', 'source_id' => $source->id]);
        PropertyValue::factory()->create(['entity_type' => 'substance', 'entity_id' => $substance->id, 'property' => 'density', 'value' => '1.25', 'unit' => 'g/mL', 'valid_from' => '2026-02-01', 'source_id' => $source->id]);
        PropertyValue::factory()->create(['entity_type' => 'trade_material', 'entity_id' => TradeMaterial::factory()->create()->id, 'property' => 'other']);
        Price::factory()->create(['trade_material_id' => $material->id, 'amount' => '42.5', 'source_id' => $source->id, 'confidence' => Confidence::L2SupplierTds]);

        $this->get('/materials/'.$material->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Materials/Show')
                ->where('material.trade_name', 'Glycerin 99.5')
                ->where('material.substance.label', 'GLYCERIN')
                ->where('material.worst_price_confidence', 'L2_supplier_tds')
                ->where('tab', 'properties')
                ->has('properties', 2)
                ->where('properties.0.origin', 'material')
                ->where('properties.0.valid_from', '2026-03-01')
                ->where('properties.0.source_title', 'Supplier sheet')
                ->where('properties.1.origin', 'substance')
                ->has('prices', 1)
                ->where('prices.0.amount', '42.5')
                ->has('audit')
                ->has('confidenceOptions', 7)
                ->has('sourceKindOptions')
                ->where('urls.sourceStore', route('sources.store', [], false)));
    }

    public function test_it_opens_the_tab_named_in_the_address_and_falls_back_for_an_unknown_one(): void
    {
        $material = TradeMaterial::factory()->create();

        $this->get('/materials/'.$material->id.'?tab=prices')->assertInertia(fn (Assert $page) => $page->where('tab', 'prices'));
        $this->get('/materials/'.$material->id.'?tab=audit')->assertInertia(fn (Assert $page) => $page->where('tab', 'audit'));
        $this->get('/materials/'.$material->id.'?tab=bogus')->assertInertia(fn (Assert $page) => $page->where('tab', 'properties'));
    }

    public function test_the_audit_tab_lists_changes_to_the_material_its_values_and_its_prices_including_deleted_ones(): void
    {
        $material = TradeMaterial::factory()->create(['trade_name' => 'Before']);
        $material->update(['trade_name' => 'After']);
        $value = PropertyValue::factory()->create(['entity_type' => 'trade_material', 'entity_id' => $material->id, 'property' => 'density']);
        $price = Price::factory()->create(['trade_material_id' => $material->id]);
        $value->delete();
        $price->delete();
        // Another material's history never shows up here.
        TradeMaterial::factory()->create(['trade_name' => 'Elsewhere']);

        $this->get('/materials/'.$material->id.'?tab=audit')->assertInertia(function (Assert $page): void {
            $entries = $page->toArray()['props']['audit'];
            $summary = array_map(fn (array $entry): string => $entry['table'].':'.$entry['action'], $entries);

            $this->assertContains('trade_materials:create', $summary);
            $this->assertContains('trade_materials:update', $summary);
            $this->assertContains('property_values:create', $summary);
            $this->assertContains('property_values:delete', $summary);
            $this->assertContains('prices:create', $summary);
            $this->assertContains('prices:delete', $summary);
            $this->assertCount(6, $entries);

            $update = collect($entries)->firstWhere(fn (array $entry): bool => $entry['table'] === 'trade_materials' && $entry['action'] === 'update');
            $this->assertSame([['field' => 'trade_name', 'before' => 'Before', 'after' => 'After']], $update['changes']);
        });
    }

    public function test_a_material_without_prices_or_properties_shows_empty_lists(): void
    {
        $material = TradeMaterial::factory()->mixture()->create();

        $this->get('/materials/'.$material->id)->assertInertia(fn (Assert $page) => $page
            ->where('material.substance', null)
            ->where('material.worst_price_confidence', null)
            ->has('properties', 0)
            ->has('prices', 0));
    }
}
