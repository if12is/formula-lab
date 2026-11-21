<?php

namespace Tests\Feature\Materials;

use App\Enums\Confidence;
use App\Enums\TradeMaterialStatus;
use App\Models\Price;
use App\Models\Substance;
use App\Models\TradeMaterial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MaterialListTest extends TestCase
{
    use RefreshDatabase;

    private TradeMaterial $sles;

    private TradeMaterial $glycerin;

    private TradeMaterial $blend;

    protected function setUp(): void
    {
        parent::setUp();

        $laureth = Substance::factory()->create(['inci' => 'SODIUM LAURETH SULFATE', 'cas' => '68585-34-2']);
        $this->sles = TradeMaterial::factory()->for($laureth)->active()->create([
            'trade_name' => 'SLES', 'grade' => '70%', 'function' => 'Surfactant', 'sanad_product_uuid' => fake()->uuid(),
        ]);
        $this->glycerin = TradeMaterial::factory()->create(['trade_name' => 'Glycerin', 'grade' => '99.5', 'function' => 'Humectant']);
        $this->blend = TradeMaterial::factory()->mixture()->create(['trade_name' => 'Base blend', 'function' => 'Surfactant']);

        Price::factory()->create(['trade_material_id' => $this->sles->id, 'confidence' => Confidence::L2SupplierTds]);
        Price::factory()->create(['trade_material_id' => $this->sles->id, 'confidence' => Confidence::L6Community]);
        Price::factory()->create(['trade_material_id' => $this->glycerin->id, 'confidence' => Confidence::L7Estimate]);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<string>
     */
    private function names(array $query = []): array
    {
        $names = [];
        $this->get(route('materials.index', $query))->assertOk()->assertInertia(function (Assert $page) use (&$names): void {
            $names = array_column($page->toArray()['props']['list']['rows'], 'trade_name');
        });

        return $names;
    }

    public function test_it_renders_the_library_with_every_material_sorted_by_name(): void
    {
        $this->get('/materials')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Materials/Index')
                ->where('list.meta.total', 3)
                ->where('list.rows.0.trade_name', 'Base blend')
                ->where('list.schema.defaults.sort', 'trade_name')
                ->has('statusOptions', 2)
                ->where('urls.substances', route('substances.search', [], false)));
    }

    public function test_each_row_carries_the_derived_columns(): void
    {
        $this->get('/materials?search=SLES')->assertInertia(fn (Assert $page) => $page
            ->where('list.rows.0.has_sanad_link', true)
            ->where('list.rows.0.worst_price_confidence', 'L6_community')
            ->where('list.rows.0.substance.cas', '68585-34-2'));
    }

    public function test_it_searches_name_grade_inci_and_cas(): void
    {
        $this->assertSame(['SLES'], $this->names(['search' => 'sles']));
        $this->assertSame(['Glycerin'], $this->names(['search' => '99.5']));
        $this->assertSame(['SLES'], $this->names(['search' => 'laureth']));
        $this->assertSame(['SLES'], $this->names(['search' => '68585']));
        $this->assertSame([], $this->names(['search' => 'nothing-here']));
    }

    public function test_it_filters_by_status_function_sanad_link_mixture_and_worst_price_confidence(): void
    {
        $this->assertSame(['SLES'], $this->names(['status' => TradeMaterialStatus::Active->value]));
        $this->assertSame(['Base blend', 'SLES'], $this->names(['function' => 'Surfactant']));
        $this->assertSame(['SLES'], $this->names(['has_sanad_link' => '1']));
        $this->assertSame(['Base blend'], $this->names(['is_mixture' => '1']));
        $this->assertSame(['Glycerin'], $this->names(['worst_price_confidence' => Confidence::L7Estimate->value]));
        $this->assertSame(['SLES'], $this->names(['worst_price_confidence' => Confidence::L6Community->value]));
    }

    public function test_the_worst_confidence_ignores_better_quotes_and_materials_without_a_price_never_match(): void
    {
        $this->assertSame([], $this->names(['worst_price_confidence' => Confidence::L2SupplierTds->value]));
    }

    public function test_filters_combine_and_a_value_outside_the_whitelist_is_ignored(): void
    {
        $this->assertSame(['SLES'], $this->names(['function' => 'Surfactant', 'status' => 'active']));
        $this->assertCount(3, $this->names(['status' => 'bogus', 'unknown_filter' => 'x']));
    }

    public function test_it_sorts_by_name_descending(): void
    {
        $this->assertSame(['SLES', 'Glycerin', 'Base blend'], $this->names(['sort' => 'trade_name', 'dir' => 'desc']));
    }

    public function test_a_filter_with_no_match_leaves_an_empty_list_that_still_echoes_the_filters(): void
    {
        $this->get('/materials?search=zzz&status=active')->assertInertia(fn (Assert $page) => $page
            ->where('list.meta.total', 0)
            ->where('list.applied.search', 'zzz')
            ->where('list.applied.filters.status', 'active'));
    }

    public function test_it_paginates_and_keeps_the_filters_in_the_page_size(): void
    {
        TradeMaterial::factory()->count(12)->create(['function' => 'Solvent']);

        $this->get('/materials?per=10&page=2&function=Solvent')->assertInertia(fn (Assert $page) => $page
            ->where('list.meta.total', 12)
            ->where('list.meta.current_page', 2)
            ->has('list.rows', 2));
    }

    public function test_the_function_options_are_the_distinct_functions(): void
    {
        $this->getJson('/materials/functions')->assertOk()->assertExactJson([
            ['value' => 'Humectant', 'label' => 'Humectant'],
            ['value' => 'Surfactant', 'label' => 'Surfactant'],
        ]);
    }

    public function test_the_typeahead_finds_materials_with_their_price_quotes(): void
    {
        $this->getJson('/materials/search?q=sles')->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.value', $this->sles->id)
            ->assertJsonPath('0.label', 'SLES')
            ->assertJsonPath('0.description', '70% · Surfactant')
            ->assertJsonCount(2, '0.prices')
            ->assertJsonStructure(['0' => ['prices' => [['id', 'amount', 'currency', 'per_unit', 'quoted_on', 'source_id', 'confidence']]]]);
    }

    public function test_the_typeahead_matches_the_inci_name_and_treats_wildcards_literally(): void
    {
        $this->getJson('/materials/search?q=LAURETH')->assertOk()->assertJsonPath('0.label', 'SLES');
        $this->getJson('/materials/search?q=_')->assertOk()->assertJsonCount(0);
    }

    public function test_the_typeahead_returns_nothing_for_a_term_with_no_match(): void
    {
        $this->getJson('/materials/search?q=nothing-here')->assertOk()->assertExactJson([]);
    }
}
