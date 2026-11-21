<?php

namespace Tests\Feature\Formulas;

use App\Enums\FormulaStatus;
use App\Models\Formula;
use App\Models\FormulaVersion;
use App\Models\Tenant;
use App\Models\TradeMaterial;
use App\Support\DecimalNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FormulaCreateTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<TradeMaterial> */
    private array $materials;

    protected function setUp(): void
    {
        parent::setUp();

        $this->materials = TradeMaterial::factory()->count(3)->create()->all();
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return [
            'code' => 'F-0100',
            'name' => 'Hand wash',
            'product_type' => 'liquid_soap',
            'tier' => 'mid',
            'lines' => [
                ['trade_material_id' => $this->materials[0]->id, 'percent_ww' => '10', 'is_qs' => false, 'phase' => 'A'],
                ['trade_material_id' => $this->materials[1]->id, 'percent_ww' => '2.5', 'is_qs' => false, 'phase' => null],
                ['trade_material_id' => $this->materials[2]->id, 'percent_ww' => null, 'is_qs' => true, 'phase' => null],
            ],
            ...$overrides,
        ];
    }

    public function test_the_create_page_opens_an_empty_editor(): void
    {
        $this->get('/formulas/create')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Formulas/Editor')
            ->where('formula', null)
            ->where('version', null)
            ->has('lines', 0)
            ->has('tierOptions', 3)
            ->where('datasetVersion', config('formula.unpublished_dataset_version'))
            ->where('urls.materials', route('materials.search', [], false)));
    }

    public function test_it_creates_the_formula_and_its_first_draft_version_with_the_lines_in_order(): void
    {
        $response = $this->post('/formulas', $this->payload());

        $formula = Formula::query()->where('code', 'F-0100')->firstOrFail();
        $response->assertRedirect(route('formulas.versions.edit', [$formula, 1]))->assertSessionHas('success', __('formulas.created'));

        $version = $formula->versions()->firstOrFail();
        $this->assertSame(FormulaStatus::Draft, $version->status);
        $this->assertNull($version->reason);
        $this->assertSame(['10', '2.5', null], $version->lines->map(fn ($line) => $line->percent_ww === null ? null : DecimalNormalizer::normalize($line->percent_ww))->all());
        $this->assertSame([false, false, true], $version->lines->pluck('is_qs')->all());
        $this->assertSame('A', $version->lines[0]->phase);
        $this->assertTrue($version->verifyHash());
        $this->assertSame(config('formula.default_tenant_name'), Tenant::query()->findOrFail($formula->tenant_id)->name);
    }

    public function test_every_formula_shares_the_default_tenant(): void
    {
        $this->post('/formulas', $this->payload());
        $this->post('/formulas', $this->payload(['code' => 'F-0101']));

        $this->assertDatabaseCount('tenants', 1);
        $this->assertDatabaseCount('formulas', 2);
    }

    public function test_a_draft_may_be_saved_when_the_lines_do_not_add_up_to_100(): void
    {
        $this->post('/formulas', $this->payload(['lines' => [['trade_material_id' => $this->materials[0]->id, 'percent_ww' => '40']], 'tier' => null]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('formula_versions', 1);
    }

    public function test_it_refuses_missing_fields_with_translated_messages(): void
    {
        $this->post('/formulas', $this->payload(['code' => '', 'name' => '', 'product_type' => '']))
            ->assertSessionHasErrors([
                'code' => __('validation.required', ['attribute' => __('validation.attributes.code')]),
                'name' => __('validation.required', ['attribute' => __('validation.attributes.name')]),
                'product_type' => __('validation.required', ['attribute' => __('validation.attributes.product_type')]),
            ]);

        $this->assertDatabaseCount('formulas', 0);
    }

    public function test_it_refuses_a_code_already_used_and_an_unknown_tier(): void
    {
        $this->post('/formulas', $this->payload())->assertSessionHasNoErrors();

        $this->post('/formulas', $this->payload(['tier' => 'gold']))->assertSessionHasErrors('tier');
        $this->post('/formulas', $this->payload())
            ->assertSessionHasErrors(['code' => __('validation.unique', ['attribute' => __('validation.attributes.code')])]);

        $this->assertDatabaseCount('formulas', 1);
    }

    public function test_it_refuses_no_lines_and_bad_lines(): void
    {
        $this->post('/formulas', $this->payload(['lines' => []]))
            ->assertSessionHasErrors(['lines' => __('validation.required', ['attribute' => __('validation.attributes.lines')])]);

        $this->post('/formulas', $this->payload(['lines' => [['trade_material_id' => fake()->uuid(), 'percent_ww' => '10']]]))
            ->assertSessionHasErrors('lines.0.trade_material_id');

        $this->post('/formulas', $this->payload(['lines' => [['trade_material_id' => $this->materials[0]->id, 'percent_ww' => '12,5']]]))
            ->assertSessionHasErrors('lines.0.percent_ww');

        $this->assertDatabaseCount('formulas', 0);
    }

    public function test_it_refuses_a_percentage_above_100_a_missing_percentage_and_two_q_s_lines(): void
    {
        $this->post('/formulas', $this->payload(['lines' => [['trade_material_id' => $this->materials[0]->id, 'percent_ww' => '100.5']]]))
            ->assertSessionHasErrors(['lines.0.percent_ww' => __('formulas.errors.percent_over_100')]);

        $this->post('/formulas', $this->payload(['lines' => [['trade_material_id' => $this->materials[0]->id, 'percent_ww' => null, 'is_qs' => false]]]))
            ->assertSessionHasErrors(['lines.0.percent_ww' => __('formulas.errors.percent_required')]);

        $this->post('/formulas', $this->payload(['lines' => [
            ['trade_material_id' => $this->materials[0]->id, 'is_qs' => true],
            ['trade_material_id' => $this->materials[1]->id, 'is_qs' => true],
        ]]))->assertSessionHasErrors(['lines' => __('formulas.errors.single_qs')]);

        $this->assertDatabaseCount('formulas', 0);
        $this->assertSame(0, FormulaVersion::query()->count());
    }
}
