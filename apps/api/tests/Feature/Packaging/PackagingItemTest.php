<?php

namespace Tests\Feature\Packaging;

use App\Enums\Confidence;
use App\Enums\ItemKind;
use App\Enums\PackagingMaterial;
use App\Models\Source;
use App\Models\TradeMaterial;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** H-01: packaging components are additive columns on trade_materials; a spec value is a sourced fact. */
class PackagingItemTest extends TestCase
{
    use RefreshDatabase;

    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->source = Source::factory()->create();
    }

    /** @return array<string, mixed> */
    private function payload(array $override = []): array
    {
        return [
            'trade_name' => 'PET bottle 400 mL',
            'item_kind' => 'container',
            'packaging_material' => 'pet',
            'nominal_capacity_ml' => '400',
            'brimful_capacity_ml' => '415.5',
            'unit_weight_g' => '24.2',
            'neck_finish' => '28/410',
            'packaging_source_id' => $this->source->id,
            'packaging_confidence' => 'L2_supplier_tds',
            ...$override,
        ];
    }

    public function test_a_packaging_component_is_saved_with_its_spec_and_source(): void
    {
        $this->post(route('packaging.store'), $this->payload())->assertRedirect(route('packaging.index'));

        $item = TradeMaterial::query()->where('trade_name', 'PET bottle 400 mL')->firstOrFail();
        $this->assertSame(ItemKind::Container, $item->item_kind);
        $this->assertSame(PackagingMaterial::Pet, $item->packaging_material);
        $this->assertSame('415.5000', $item->brimful_capacity_ml);
        $this->assertSame('28/410', $item->neck_finish);
        $this->assertSame(Confidence::L2SupplierTds, $item->packaging_confidence);
        $this->assertSame($this->source->id, $item->packaging_source_id);
    }

    public function test_a_spec_value_without_a_source_is_refused(): void
    {
        $this->post(route('packaging.store'), $this->payload(['packaging_source_id' => null, 'packaging_confidence' => null]))
            ->assertSessionHasErrors(['packaging_source_id', 'packaging_confidence']);

        $this->assertSame(0, TradeMaterial::query()->packaging()->count());
    }

    public function test_a_component_with_no_spec_needs_no_source_and_keeps_the_spec_null_not_zero(): void
    {
        $this->post(route('packaging.store'), ['trade_name' => 'Plain carton', 'item_kind' => 'shipping_carton'])->assertSessionHasNoErrors();

        $item = TradeMaterial::query()->where('trade_name', 'Plain carton')->firstOrFail();
        $this->assertNull($item->nominal_capacity_ml);
        $this->assertNull($item->unit_weight_g);
        $this->assertNull($item->packaging_source_id);
    }

    public function test_brimful_below_nominal_and_non_positive_numbers_are_refused(): void
    {
        $this->post(route('packaging.store'), $this->payload(['brimful_capacity_ml' => '399.9']))->assertSessionHasErrors('brimful_capacity_ml');
        $this->post(route('packaging.store'), $this->payload(['unit_weight_g' => '0']))->assertSessionHasErrors('unit_weight_g');
        $this->post(route('packaging.store'), $this->payload(['nominal_capacity_ml' => '1e3']))->assertSessionHasErrors('nominal_capacity_ml');
    }

    public function test_a_raw_material_kind_and_an_unknown_material_are_refused(): void
    {
        $this->post(route('packaging.store'), $this->payload(['item_kind' => 'raw_material']))->assertSessionHasErrors('item_kind');
        $this->post(route('packaging.store'), $this->payload(['packaging_material' => 'wood']))->assertSessionHasErrors('packaging_material');
    }

    public function test_an_item_can_be_updated_and_a_raw_material_cannot_be_reached_through_the_packaging_route(): void
    {
        $item = TradeMaterial::factory()->packaging()->create(['trade_name' => 'Old']);
        $this->put(route('packaging.update', $item), $this->payload(['trade_name' => 'New']))->assertRedirect();
        $this->assertSame('New', $item->fresh()->trade_name);

        $raw = TradeMaterial::factory()->create();
        $this->put(route('packaging.update', $raw), $this->payload())->assertNotFound();
    }

    public function test_the_database_refuses_a_spec_on_a_raw_material_and_a_spec_without_its_source(): void
    {
        $this->expectException(QueryException::class);
        TradeMaterial::factory()->create(['nominal_capacity_ml' => '100', 'packaging_source_id' => $this->source->id, 'packaging_confidence' => Confidence::L2SupplierTds]);
    }

    public function test_packaging_stays_out_of_the_materials_library_and_the_formula_material_search(): void
    {
        TradeMaterial::factory()->create(['trade_name' => 'Glycerin']);
        TradeMaterial::factory()->packaging()->create(['trade_name' => 'Glycerin bottle']);

        $this->get(route('materials.index'))->assertInertia(fn (Assert $page) => $page->has('list.rows', 1)->where('list.rows.0.trade_name', 'Glycerin'));
        $this->getJson(route('materials.search', ['q' => 'Glycerin']))->assertJsonCount(1)->assertJsonPath('0.label', 'Glycerin');
    }

    public function test_the_list_filters_by_kind_material_and_capacity_and_sorts(): void
    {
        TradeMaterial::factory()->packaging()->create(['trade_name' => 'Bottle A', 'packaging_material' => 'pet', 'nominal_capacity_ml' => '250', 'packaging_source_id' => $this->source->id, 'packaging_confidence' => Confidence::L2SupplierTds]);
        TradeMaterial::factory()->packaging()->create(['trade_name' => 'Bottle B', 'packaging_material' => 'glass', 'nominal_capacity_ml' => '500', 'packaging_source_id' => $this->source->id, 'packaging_confidence' => Confidence::L2SupplierTds]);
        TradeMaterial::factory()->packaging(ItemKind::Closure)->create(['trade_name' => 'Cap']);

        $names = fn (array $query): array => collect($this->get(route('packaging.index', $query))->viewData('page')['props']['list']['rows'])->pluck('trade_name')->all();

        $this->assertSame(['Bottle A', 'Bottle B', 'Cap'], $names([]));
        $this->assertSame(['Cap'], $names(['item_kind' => 'closure']));
        $this->assertSame(['Bottle B'], $names(['packaging_material' => 'glass']));
        $this->assertSame(['Bottle B'], $names(['nominal_capacity_ml_min' => '300']));
        $this->assertSame(['Bottle A', 'Bottle B'], $names(['has_spec' => '1']));
        $this->assertSame(['Bottle A', 'Bottle B', 'Cap'], $names(['sort' => 'nominal_capacity_ml']));
        $this->assertSame(['Bottle A'], $names(['search' => 'bottle a']));
    }

    public function test_every_enum_case_has_both_labels(): void
    {
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            foreach ([...ItemKind::cases(), ...PackagingMaterial::cases()] as $case) {
                $this->assertNotSame('enums.'.Str::snake(class_basename($case)).'.'.$case->value, $case->label());
            }
        }
    }
}
