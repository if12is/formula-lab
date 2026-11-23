<?php

namespace Tests\Feature\Materials;

use App\Enums\Confidence;
use App\Enums\Ionicity;
use App\Models\PropertyValue;
use App\Models\Substance;
use App\Models\TradeMaterial;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** M-08: the ionic character of a material picks the emulsifier path; the library filters by it and by the confidence of the required HLB. */
class MaterialIonicityTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return ['trade_name' => 'Cetrimonium chloride', 'status' => 'draft', 'is_mixture' => true, ...$overrides];
    }

    /** @return list<string> */
    private function names(array $query): array
    {
        $names = [];
        $this->get(route('materials.index', $query))->assertOk()->assertInertia(function (Assert $page) use (&$names): void {
            $names = array_column($page->toArray()['props']['list']['rows'], 'trade_name');
        });
        sort($names);

        return $names;
    }

    private function requiredHlb(TradeMaterial|Substance $entity, Confidence $confidence): void
    {
        PropertyValue::factory()->create([
            'entity_type' => $entity instanceof TradeMaterial ? 'trade_material' : 'substance',
            'entity_id' => $entity->getKey(),
            'property' => 'required_hlb',
            'unit' => 'HLB',
            'confidence' => $confidence,
        ]);
    }

    public function test_a_material_is_saved_with_its_ionic_character_and_it_can_be_cleared(): void
    {
        $this->post('/materials', $this->payload(['ionicity' => 'cationic']))->assertSessionHasNoErrors();

        $material = TradeMaterial::query()->firstOrFail();
        $this->assertSame(Ionicity::Cationic, $material->ionicity);

        $this->put(route('materials.update', $material), $this->payload(['ionicity' => null]))->assertSessionHasNoErrors();
        $this->assertNull($material->fresh()->ionicity);
    }

    public function test_an_unstated_ionicity_stays_null_and_is_never_assumed_non_ionic(): void
    {
        $this->post('/materials', $this->payload())->assertSessionHasNoErrors();

        $this->assertNull(TradeMaterial::query()->firstOrFail()->ionicity);
    }

    public function test_an_unknown_ionic_character_is_refused_by_the_form_and_by_the_database(): void
    {
        $this->post('/materials', $this->payload(['ionicity' => 'zwitterion']))->assertSessionHasErrors('ionicity');
        $this->assertDatabaseCount('trade_materials', 0);

        $material = TradeMaterial::factory()->create();
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('trade_materials')->where('id', $material->id)->update(['ionicity' => 'zwitterion']));
    }

    public function test_the_library_filters_by_ionic_character_and_lists_it(): void
    {
        TradeMaterial::factory()->mixture()->create(['trade_name' => 'Polysorbate', 'ionicity' => 'nonionic']);
        TradeMaterial::factory()->mixture()->create(['trade_name' => 'Behentrimonium', 'ionicity' => 'cationic']);
        TradeMaterial::factory()->mixture()->create(['trade_name' => 'Sulfate', 'ionicity' => 'anionic']);
        TradeMaterial::factory()->mixture()->create(['trade_name' => 'Unstated']);

        $this->assertSame(['Behentrimonium'], $this->names(['ionicity' => 'cationic']));
        $this->assertSame(['Sulfate'], $this->names(['ionicity' => 'anionic']));
        $this->assertSame(['Polysorbate'], $this->names(['ionicity' => 'nonionic']));
        $this->assertSame([], $this->names(['ionicity' => 'amphoteric']));
        $this->assertSame(['Behentrimonium', 'Polysorbate', 'Sulfate', 'Unstated'], $this->names([]));

        $this->get(route('materials.index', ['ionicity' => 'cationic']))->assertInertia(fn (Assert $page) => $page
            ->where('ionicityOptions', Ionicity::options())
            ->where('list.rows.0.ionicity', 'cationic'));
    }

    public function test_the_library_filters_by_the_confidence_of_the_required_hlb_and_takes_the_worst(): void
    {
        $estimate = TradeMaterial::factory()->mixture()->create(['trade_name' => 'Estimated oil']);
        $this->requiredHlb($estimate, Confidence::L7Estimate);

        $reference = TradeMaterial::factory()->mixture()->create(['trade_name' => 'Referenced oil']);
        $this->requiredHlb($reference, Confidence::L5Reference);

        $mixed = TradeMaterial::factory()->mixture()->create(['trade_name' => 'Mixed oil']);
        $this->requiredHlb($mixed, Confidence::L2SupplierTds);
        $this->requiredHlb($mixed, Confidence::L7Estimate);

        $substance = Substance::factory()->create();
        $inherited = TradeMaterial::factory()->create(['trade_name' => 'Inherited oil', 'substance_id' => $substance->id, 'is_mixture' => false]);
        $this->requiredHlb($substance, Confidence::L4RegulatoryDb);

        TradeMaterial::factory()->mixture()->create(['trade_name' => 'No rHLB']);
        $other = TradeMaterial::factory()->mixture()->create(['trade_name' => 'Other property']);
        PropertyValue::factory()->create(['entity_type' => 'trade_material', 'entity_id' => $other->id, 'property' => 'hlb', 'unit' => 'HLB', 'confidence' => Confidence::L7Estimate]);

        $this->assertSame(['Estimated oil', 'Mixed oil'], $this->names(['required_hlb_confidence' => 'L7_estimate']));
        $this->assertSame(['Referenced oil'], $this->names(['required_hlb_confidence' => 'L5_reference']));
        $this->assertSame([$inherited->trade_name], $this->names(['required_hlb_confidence' => 'L4_regulatory_db']));
        $this->assertSame([], $this->names(['required_hlb_confidence' => 'L1_coa_batch']));

        $this->get(route('materials.index', ['search' => 'No rHLB']))->assertInertia(fn (Assert $page) => $page->where('list.rows.0.required_hlb_confidence', null));
        $this->get(route('materials.index', ['search' => 'Mixed oil']))->assertInertia(fn (Assert $page) => $page->where('list.rows.0.required_hlb_confidence', 'L7_estimate'));
    }

    public function test_the_material_page_and_the_calculator_selector_carry_the_ionic_character(): void
    {
        $material = TradeMaterial::factory()->mixture()->create(['trade_name' => 'Ionic Calc Material', 'ionicity' => 'cationic']);

        $this->get(route('materials.show', $material))->assertInertia(fn (Assert $page) => $page
            ->where('material.ionicity', 'cationic')
            ->has('ionicityOptions', count(Ionicity::cases())));

        $this->getJson('/calculators/materials?q='.urlencode('Ionic Calc Material'))->assertOk()->assertJsonPath('0.ionicity', 'cationic');
    }

    public function test_every_ionic_character_has_a_label_in_both_languages_and_matches_the_engine_words(): void
    {
        $this->assertSame(['nonionic', 'anionic', 'cationic', 'amphoteric', 'other'], Ionicity::values());

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            foreach (Ionicity::cases() as $case) {
                $this->assertNotSame('enums.ionicity.'.$case->value, $case->label());
            }
        }
    }
}
