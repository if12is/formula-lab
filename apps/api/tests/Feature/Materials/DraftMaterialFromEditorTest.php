<?php

namespace Tests\Feature\Materials;

use App\Enums\Confidence;
use App\Enums\TradeMaterialStatus;
use App\Models\Price;
use App\Models\Source;
use App\Models\Substance;
use App\Models\TradeMaterial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** D-09: a material typed into the formula editor is a draft, and a price typed with it is an unverified L7 estimate. */
class DraftMaterialFromEditorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_registers_the_material_as_a_draft_whatever_the_request_says(): void
    {
        $response = $this->postJson('/materials/draft', ['trade_name' => 'Fragrance X', 'status' => 'active']);

        $response->assertCreated()->assertJsonPath('label', 'Fragrance X')->assertJsonPath('status', 'draft');
        $material = TradeMaterial::query()->firstOrFail();
        $this->assertSame(TradeMaterialStatus::Draft, $material->status);
        $this->assertSame($material->id, $response->json('value'));
    }

    public function test_a_price_without_date_or_source_is_stored_as_an_l7_estimate_against_the_shared_unverified_source(): void
    {
        $response = $this->postJson('/materials/draft', ['trade_name' => 'Fragrance X', 'price_per_kg' => '85.5']);

        $response->assertCreated()->assertJsonCount(1, 'prices')->assertJsonPath('prices.0.confidence', 'L7_estimate');

        $price = Price::query()->firstOrFail();
        $this->assertSame(Confidence::L7Estimate, $price->confidence);
        $this->assertSame('EGP', $price->currency);
        $this->assertSame('kg', $price->per_unit);
        $this->assertSame(now()->toDateString(), $price->quoted_on->toDateString());
        $this->assertSame('85.5', rtrim($price->amount, '0'));

        $source = Source::query()->findOrFail($price->source_id);
        $this->assertSame('unverified_entry', $source->kind);
    }

    public function test_every_unverified_price_shares_one_source(): void
    {
        $this->postJson('/materials/draft', ['trade_name' => 'A', 'price_per_kg' => '1']);
        $this->postJson('/materials/draft', ['trade_name' => 'B', 'price_per_kg' => '2']);

        $this->assertDatabaseCount('sources', 1);
        $this->assertDatabaseCount('prices', 2);
    }

    public function test_without_a_price_no_price_is_stored(): void
    {
        $this->postJson('/materials/draft', ['trade_name' => 'Fragrance X'])->assertCreated()->assertJsonCount(0, 'prices');

        $this->assertDatabaseCount('prices', 0);
        $this->assertDatabaseCount('sources', 0);
    }

    public function test_a_failure_stores_neither_the_material_nor_the_price(): void
    {
        $this->postJson('/materials/draft', ['trade_name' => 'X', 'price_per_kg' => 'abc'])->assertUnprocessable();

        $this->assertDatabaseCount('trade_materials', 0);
        $this->assertDatabaseCount('prices', 0);
    }

    public function test_it_answers_validation_failures_with_translated_messages(): void
    {
        $this->postJson('/materials/draft', ['trade_name' => ''])
            ->assertUnprocessable()
            ->assertJsonPath('errors.trade_name.0', __('validation.required', ['attribute' => __('validation.attributes.trade_name')]));

        $this->postJson('/materials/draft', ['trade_name' => 'X', 'price_per_kg' => '-1'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.price_per_kg.0', __('validation.regex', ['attribute' => __('validation.attributes.price_per_kg')]));
    }

    public function test_a_mixture_cannot_carry_a_substance(): void
    {
        $this->postJson('/materials/draft', ['trade_name' => 'X', 'is_mixture' => true, 'substance_id' => Substance::factory()->create()->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.substance_id.0', __('materials.errors.mixture_has_no_substance'));
    }
}
