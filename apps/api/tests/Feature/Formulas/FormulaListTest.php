<?php

namespace Tests\Feature\Formulas;

use App\Enums\FormulaStatus;
use App\Enums\ProductTier;
use App\Models\Formula;
use App\Models\FormulaVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FormulaListTest extends TestCase
{
    use RefreshDatabase;

    private Formula $shampoo;

    private Formula $lotion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shampoo = Formula::factory()->create(['code' => 'F-0001', 'name' => 'Daily shampoo', 'product_type' => 'shampoo', 'tier' => ProductTier::Economy]);
        $this->lotion = Formula::factory()->create(['code' => 'F-0002', 'name' => 'Body lotion', 'product_type' => 'lotion', 'tier' => ProductTier::Premium]);

        FormulaVersion::factory()->create(['formula_id' => $this->shampoo->id, 'version' => 1, 'status' => FormulaStatus::Approved, 'created_at' => '2026-02-01 10:00:00']);
        FormulaVersion::factory()->create(['formula_id' => $this->shampoo->id, 'version' => 2, 'reason' => 'Retuned', 'status' => FormulaStatus::Draft, 'created_at' => '2026-03-01 10:00:00']);
        FormulaVersion::factory()->create(['formula_id' => $this->lotion->id, 'version' => 1, 'status' => FormulaStatus::Approved, 'created_at' => '2026-02-15 10:00:00']);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<string>
     */
    private function codes(array $query = []): array
    {
        $codes = [];
        $this->get(route('formulas.index', $query))->assertOk()->assertInertia(function (Assert $page) use (&$codes): void {
            $codes = array_column($page->toArray()['props']['list']['rows'], 'code');
        });

        return $codes;
    }

    public function test_it_lists_formulas_with_their_latest_version_newest_activity_first(): void
    {
        $this->get('/formulas')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Formulas/Index')
            ->where('list.meta.total', 2)
            ->where('list.rows.0.code', 'F-0001')
            ->where('list.rows.0.latest_version', 2)
            ->where('list.rows.0.latest_status', 'draft')
            ->where('list.rows.0.last_activity_at', '2026-03-01')
            ->where('list.rows.0.href', route('formulas.show', $this->shampoo, false))
            ->where('list.rows.1.latest_status', 'approved')
            ->where('list.schema.defaults.sort', 'updated')
            ->where('list.schema.defaults.dir', 'desc'));
    }

    public function test_it_searches_code_and_name(): void
    {
        $this->assertSame(['F-0002'], $this->codes(['search' => 'lotion']));
        $this->assertSame(['F-0001'], $this->codes(['search' => 'F-0001']));
        $this->assertSame([], $this->codes(['search' => 'nothing']));
    }

    public function test_it_filters_by_product_type_tier_and_the_status_of_the_latest_version(): void
    {
        $this->assertSame(['F-0002'], $this->codes(['product_type' => 'lotion']));
        $this->assertSame(['F-0001'], $this->codes(['tier' => 'economy']));
        $this->assertSame(['F-0001'], $this->codes(['latest_status' => 'draft']));
        $this->assertSame(['F-0002'], $this->codes(['latest_status' => 'approved']));
    }

    public function test_the_status_filter_looks_at_the_latest_version_not_an_older_one(): void
    {
        // The shampoo's version 1 is approved, but its latest (2) is a draft.
        $this->assertNotContains('F-0001', $this->codes(['latest_status' => 'approved']));
    }

    public function test_it_filters_by_the_date_of_the_last_change(): void
    {
        $this->assertSame(['F-0001'], $this->codes(['updated_from' => '2026-03-01']));
        $this->assertSame(['F-0002'], $this->codes(['updated_from' => '2026-02-10', 'updated_to' => '2026-02-20']));
        $this->assertSame([], $this->codes(['updated_from' => '2027-01-01']));
    }

    public function test_it_sorts_by_code_and_name(): void
    {
        $this->assertSame(['F-0001', 'F-0002'], $this->codes(['sort' => 'code', 'dir' => 'asc']));
        $this->assertSame(['F-0001', 'F-0002'], $this->codes(['sort' => 'name', 'dir' => 'desc']));
    }

    public function test_a_formula_without_versions_still_lists_and_ignores_bad_filters(): void
    {
        Formula::factory()->create(['code' => 'F-0003']);

        $this->assertContains('F-0003', $this->codes(['tier' => 'bogus']));
        $this->assertSame([], $this->codes(['latest_status' => 'rejected']));
    }

    public function test_the_product_type_options_are_the_distinct_types(): void
    {
        Formula::factory()->create(['product_type' => 'lotion']);

        $this->getJson('/formulas/product-types')->assertExactJson([
            ['value' => 'lotion', 'label' => 'lotion'],
            ['value' => 'shampoo', 'label' => 'shampoo'],
        ]);
    }

    public function test_a_formula_opens_at_its_latest_version(): void
    {
        $this->get('/formulas/'.$this->shampoo->id)->assertRedirect(route('formulas.versions.edit', [$this->shampoo, 2]));
        $this->get('/formulas/'.fake()->uuid())->assertNotFound();
        $this->get('/formulas/not-a-uuid')->assertNotFound();
    }
}
