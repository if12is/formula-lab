<?php

namespace Tests\Feature\Materials;

use App\Enums\Confidence;
use App\Models\Price;
use App\Models\PropertyValue;
use App\Models\Source;
use App\Models\Substance;
use App\Models\TradeMaterial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Property values and prices of a material, and the substance and source records created inline beside them. */
class MaterialDataEntryTest extends TestCase
{
    use RefreshDatabase;

    private TradeMaterial $material;

    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->material = TradeMaterial::factory()->create();
        $this->source = Source::factory()->create(['title' => 'Supplier data sheet']);
    }

    /** @return array<string, mixed> */
    private function property(array $overrides = []): array
    {
        return [
            'property' => 'density', 'value' => '1.04', 'min' => null, 'max' => null, 'unit' => 'g/mL',
            'source_id' => $this->source->id, 'confidence' => 'L2_supplier_tds', 'valid_from' => '2026-03-01', 'lot_no' => 'LOT-9',
            ...$overrides,
        ];
    }

    /** @return array<string, mixed> */
    private function price(array $overrides = []): array
    {
        return ['amount' => '42.5', 'quoted_on' => '2026-03-01', 'source_id' => $this->source->id, 'confidence' => 'L2_supplier_tds', ...$overrides];
    }

    private function showUrl(string $tab): string
    {
        return route('materials.show', ['material' => $this->material, 'tab' => $tab]);
    }

    public function test_it_adds_a_single_property_value(): void
    {
        $this->post("/materials/{$this->material->id}/properties", $this->property())
            ->assertRedirect($this->showUrl('properties'))
            ->assertSessionHas('success', __('materials.property_added'));

        $value = PropertyValue::query()->firstOrFail();
        $this->assertSame('trade_material', $value->entity_type);
        $this->assertSame($this->material->id, $value->entity_id);
        $this->assertSame('1.04', $value->value);
        $this->assertSame(Confidence::L2SupplierTds, $value->confidence);
        $this->assertSame('LOT-9', $value->lot_no);
        $this->assertNull($value->min);
    }

    public function test_it_adds_a_range_and_a_half_open_range(): void
    {
        $this->post("/materials/{$this->material->id}/properties", $this->property(['value' => null, 'min' => '10', 'max' => '12.5']))->assertSessionHasNoErrors();
        $this->post("/materials/{$this->material->id}/properties", $this->property(['value' => null, 'min' => '5']))->assertSessionHasNoErrors();

        $this->assertDatabaseCount('property_values', 2);
    }

    public function test_it_refuses_an_empty_value_both_a_value_and_a_range_and_an_inverted_range(): void
    {
        $this->post("/materials/{$this->material->id}/properties", $this->property(['value' => null]))
            ->assertSessionHasErrors(['value' => __('materials.errors.value_or_range_required')]);
        $this->post("/materials/{$this->material->id}/properties", $this->property(['min' => '1']))
            ->assertSessionHasErrors(['value' => __('materials.errors.value_or_range_exclusive')]);
        $this->post("/materials/{$this->material->id}/properties", $this->property(['value' => null, 'min' => '9.5', 'max' => '10']))
            ->assertSessionHasNoErrors();
        $this->post("/materials/{$this->material->id}/properties", $this->property(['value' => null, 'min' => '10', 'max' => '9.5']))
            ->assertSessionHasErrors(['min' => __('materials.errors.range_order')]);

        $this->assertDatabaseCount('property_values', 1);
    }

    public function test_it_refuses_a_bad_number_an_unknown_source_confidence_and_a_missing_unit_with_translated_messages(): void
    {
        $this->post("/materials/{$this->material->id}/properties", $this->property(['value' => '1,5', 'unit' => '', 'confidence' => 'L9', 'source_id' => fake()->uuid(), 'valid_from' => '01/03/2026']))
            ->assertSessionHasErrors([
                'value' => __('validation.regex', ['attribute' => __('validation.attributes.value')]),
                'unit' => __('validation.required', ['attribute' => __('validation.attributes.unit')]),
                'confidence',
                'source_id' => __('validation.exists', ['attribute' => __('validation.attributes.source_id')]),
                'valid_from',
            ]);
    }

    public function test_it_removes_a_property_value_of_this_material_only(): void
    {
        $value = PropertyValue::factory()->create(['entity_type' => 'trade_material', 'entity_id' => $this->material->id, 'source_id' => $this->source->id]);
        $foreign = PropertyValue::factory()->create(['entity_type' => 'trade_material', 'entity_id' => TradeMaterial::factory()->create()->id, 'source_id' => $this->source->id]);

        $this->delete("/materials/{$this->material->id}/properties/{$foreign->id}")->assertNotFound();
        $this->delete("/materials/{$this->material->id}/properties/{$value->id}")
            ->assertRedirect($this->showUrl('properties'))
            ->assertSessionHas('success', __('materials.property_removed'));

        $this->assertModelMissing($value);
        $this->assertModelExists($foreign);
        $this->assertDatabaseHas('audit_log', ['table_name' => 'property_values', 'record_id' => $value->id, 'action' => 'delete']);
    }

    public function test_it_adds_a_price_in_egp_per_kg(): void
    {
        $this->post("/materials/{$this->material->id}/prices", $this->price())
            ->assertRedirect($this->showUrl('prices'))
            ->assertSessionHas('success', __('materials.price_added'));

        $price = Price::query()->firstOrFail();
        $this->assertSame($this->material->id, $price->trade_material_id);
        $this->assertSame('EGP', $price->currency);
        $this->assertSame('kg', $price->per_unit);
        $this->assertSame('2026-03-01', $price->quoted_on->toDateString());
        $this->assertSame(Confidence::L2SupplierTds, $price->confidence);
    }

    public function test_it_adds_a_price_in_a_foreign_currency_as_it_was_quoted(): void
    {
        $this->post("/materials/{$this->material->id}/prices", $this->price(['currency' => 'USD', 'amount' => '2.5']))
            ->assertRedirect($this->showUrl('prices'))
            ->assertSessionHas('success', __('materials.price_added'));

        $price = Price::query()->firstOrFail();
        $this->assertSame('USD', $price->currency);
        $this->assertSame('2.5', $price->amount);
        $this->assertDatabaseHas('audit_log', ['table_name' => 'prices', 'record_id' => $price->id, 'action' => 'create']);
    }

    public function test_it_defaults_the_currency_to_egp_when_none_is_sent_or_it_is_empty(): void
    {
        $this->post("/materials/{$this->material->id}/prices", $this->price(['currency' => null]))->assertSessionHasNoErrors();
        $this->post("/materials/{$this->material->id}/prices", $this->price(['currency' => 'EGP', 'quoted_on' => '2026-03-02']))->assertSessionHasNoErrors();

        $this->assertSame(['EGP', 'EGP'], Price::query()->pluck('currency')->all());
    }

    public function test_it_refuses_an_unsupported_currency_with_a_translated_message(): void
    {
        $this->post("/materials/{$this->material->id}/prices", $this->price(['currency' => 'JPY']))
            ->assertSessionHasErrors(['currency' => __('validation.in', ['attribute' => __('validation.attributes.currency')])]);
        $this->post("/materials/{$this->material->id}/prices", $this->price(['currency' => 'usd']))->assertSessionHasErrors('currency');

        $this->assertDatabaseCount('prices', 0);
    }

    public function test_it_refuses_a_price_without_date_source_or_amount_and_a_negative_amount(): void
    {
        $this->post("/materials/{$this->material->id}/prices", ['amount' => '', 'quoted_on' => '', 'source_id' => '', 'confidence' => ''])
            ->assertSessionHasErrors(['amount' => __('validation.required', ['attribute' => __('validation.attributes.amount')]), 'quoted_on', 'source_id', 'confidence']);
        $this->post("/materials/{$this->material->id}/prices", $this->price(['amount' => '-3']))->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('prices', 0);
    }

    public function test_it_removes_a_price_of_this_material_only(): void
    {
        $price = Price::factory()->create(['trade_material_id' => $this->material->id, 'source_id' => $this->source->id]);
        $foreign = Price::factory()->create(['source_id' => $this->source->id]);

        $this->delete("/materials/{$this->material->id}/prices/{$foreign->id}")->assertNotFound();
        $this->delete("/materials/{$this->material->id}/prices/{$price->id}")
            ->assertRedirect($this->showUrl('prices'))
            ->assertSessionHas('success', __('materials.price_removed'));

        $this->assertModelMissing($price);
        $this->assertModelExists($foreign);
    }

    public function test_the_entry_routes_answer_not_found_for_an_unknown_material(): void
    {
        $unknown = fake()->uuid();

        $this->post("/materials/{$unknown}/properties", $this->property())->assertNotFound();
        $this->post("/materials/{$unknown}/prices", $this->price())->assertNotFound();
    }

    public function test_it_creates_a_substance_inline_and_answers_with_the_option(): void
    {
        $this->postJson('/substances', ['inci' => 'GLYCERIN', 'cas' => '56-81-5', 'name_en' => 'Glycerol', 'mw' => '92.09'])
            ->assertCreated()
            ->assertJsonPath('label', 'GLYCERIN')
            ->assertJsonPath('description', '56-81-5 · Glycerol');

        $this->assertDatabaseHas('substances', ['cas' => '56-81-5']);
    }

    public function test_it_refuses_a_substance_without_identity_a_bad_or_taken_cas_and_a_bad_molar_mass(): void
    {
        $this->postJson('/substances', [])->assertUnprocessable()->assertJsonPath('errors.inci.0', __('materials.errors.substance_identity_required'));
        $this->postJson('/substances', ['inci' => 'X', 'cas' => '123'])->assertUnprocessable()->assertJsonValidationErrors('cas');
        $this->postJson('/substances', ['inci' => 'X', 'mw' => 'heavy'])->assertUnprocessable()->assertJsonValidationErrors('mw');

        Substance::factory()->create(['cas' => '56-81-5']);
        $this->postJson('/substances', ['inci' => 'X', 'cas' => '56-81-5'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.cas.0', __('validation.unique', ['attribute' => __('validation.attributes.cas')]));
    }

    public function test_it_finds_substances_by_inci_cas_or_name(): void
    {
        Substance::factory()->create(['inci' => 'GLYCERIN', 'cas' => '56-81-5']);
        Substance::factory()->create(['inci' => 'AQUA', 'name_en' => 'Water', 'name_ar' => 'ماء']);

        $this->getJson('/substances/search?q=glyc')->assertJsonCount(1)->assertJsonPath('0.label', 'GLYCERIN');
        $this->getJson('/substances/search?q=56-81')->assertJsonCount(1)->assertJsonPath('0.label', 'GLYCERIN');
        $this->getJson('/substances/search?q=water')->assertJsonCount(1)->assertJsonPath('0.label', 'AQUA');
        $this->getJson('/substances/search?q=zzz')->assertExactJson([]);
    }

    public function test_it_creates_a_source_inline_and_answers_with_the_option(): void
    {
        $this->postJson('/sources', ['kind' => 'coa', 'title' => 'COA 2026-14', 'retrieved_at' => '2026-03-02', 'url' => 'https://example.com/coa.pdf'])
            ->assertCreated()
            ->assertJsonPath('label', 'COA 2026-14');

        $this->assertDatabaseHas('sources', ['title' => 'COA 2026-14', 'kind' => 'coa']);
    }

    public function test_it_refuses_a_source_with_an_unknown_kind_the_system_kind_a_bad_link_or_a_bad_date(): void
    {
        $this->postJson('/sources', ['kind' => 'rumour', 'title' => 'x', 'retrieved_at' => '2026-03-02'])->assertUnprocessable()->assertJsonValidationErrors('kind');
        $this->postJson('/sources', ['kind' => 'unverified_entry', 'title' => 'x', 'retrieved_at' => '2026-03-02'])->assertUnprocessable()->assertJsonValidationErrors('kind');
        $this->postJson('/sources', ['kind' => 'coa', 'title' => 'x', 'retrieved_at' => '2026-03-02', 'url' => 'javascript:alert(1)'])->assertUnprocessable()->assertJsonValidationErrors('url');
        $this->postJson('/sources', ['kind' => 'coa', 'title' => '', 'retrieved_at' => '02/03/2026'])->assertUnprocessable()->assertJsonValidationErrors(['title', 'retrieved_at']);
    }

    public function test_it_finds_sources_by_title(): void
    {
        Source::factory()->create(['title' => 'Supplier data sheet']);

        $this->getJson('/sources/search?q=sheet')->assertOk()->assertJsonPath('0.label', 'Supplier data sheet');
        $this->getJson('/sources/search?q=zzz')->assertExactJson([]);
    }
}
