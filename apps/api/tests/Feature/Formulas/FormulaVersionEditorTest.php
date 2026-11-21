<?php

namespace Tests\Feature\Formulas;

use App\Enums\FormulaStatus;
use App\Models\AuditLog;
use App\Models\Formula;
use App\Models\FormulaVersion;
use App\Models\Price;
use App\Models\TradeMaterial;
use App\Services\Formula\FormulaVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FormulaVersionEditorTest extends TestCase
{
    use RefreshDatabase;

    private Formula $formula;

    private TradeMaterial $water;

    private TradeMaterial $sles;

    private FormulaVersion $v1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->water = TradeMaterial::factory()->create(['trade_name' => 'Water']);
        $this->sles = TradeMaterial::factory()->create(['trade_name' => 'SLES']);
        $this->formula = Formula::factory()->create(['code' => 'F-0001', 'name' => 'Shampoo']);

        $this->v1 = app(FormulaVersionService::class)->createVersion($this->formula, [
            ['trade_material_id' => $this->sles->id, 'percent_ww' => '10'],
            ['trade_material_id' => $this->water->id, 'percent_ww' => null, 'is_qs' => true],
        ], null);
    }

    /** @return array<string, mixed> */
    private function save(array $overrides = []): array
    {
        return [
            'reason' => 'More foam',
            'lines' => [
                ['trade_material_id' => $this->sles->id, 'percent_ww' => '12', 'is_qs' => false, 'phase' => null],
                ['trade_material_id' => $this->water->id, 'percent_ww' => '88', 'is_qs' => true, 'phase' => null],
            ],
            'balance_events' => [],
            ...$overrides,
        ];
    }

    private function editUrl(int $version): string
    {
        return route('formulas.versions.edit', [$this->formula, $version]);
    }

    private function storeUrl(int $version): string
    {
        return route('formulas.versions.store', [$this->formula, $version]);
    }

    public function test_a_latest_draft_opens_editable_with_its_lines_materials_and_history(): void
    {
        Price::factory()->create(['trade_material_id' => $this->sles->id, 'amount' => '30']);

        $this->get($this->editUrl(1))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Formulas/Editor')
            ->where('formula.code', 'F-0001')
            ->where('version.number', 1)
            ->where('version.status', 'draft')
            ->where('version.editable', true)
            ->where('version.is_latest', true)
            ->has('lines', 2)
            ->where('lines.0.percent_ww', '10')
            ->where('lines.1.percent_ww', '')
            ->where('lines.1.is_qs', true)
            ->where('materials.'.$this->sles->id.'.label', 'SLES')
            ->has('materials.'.$this->sles->id.'.prices', 1)
            ->has('history', 1)
            ->where('history.0.is_current', true));
    }

    public function test_an_approved_version_opens_read_only(): void
    {
        $approved = FormulaVersion::factory()->approved()->create(['formula_id' => Formula::factory()->create()->id]);

        $this->get(route('formulas.versions.edit', [$approved->formula_id, 1]))->assertInertia(fn (Assert $page) => $page
            ->where('version.status', 'approved')
            ->where('version.editable', false));
    }

    public function test_an_older_version_opens_read_only_while_a_newer_one_exists(): void
    {
        app(FormulaVersionService::class)->createVersion($this->formula, [['trade_material_id' => $this->sles->id, 'percent_ww' => '100']], 'Simplified');

        $this->get($this->editUrl(1))->assertInertia(fn (Assert $page) => $page->where('version.editable', false)->where('version.is_latest', false));
        $this->get($this->editUrl(2))->assertInertia(fn (Assert $page) => $page->where('version.editable', true)->has('history', 2)->where('history.0.number', 2));
    }

    public function test_an_unknown_formula_or_version_is_not_found(): void
    {
        $this->get($this->editUrl(9))->assertNotFound();
        $this->get('/formulas/'.fake()->uuid().'/versions/1/edit')->assertNotFound();
        $this->get('/formulas/'.$this->formula->id.'/versions/abc/edit')->assertNotFound();
        $this->post($this->storeUrl(9), $this->save())->assertNotFound();
    }

    public function test_a_version_of_another_formula_is_not_reachable_through_this_one(): void
    {
        $other = Formula::factory()->create();
        FormulaVersion::factory()->create(['formula_id' => $other->id, 'version' => 5, 'reason' => 'x']);

        $this->get($this->editUrl(5))->assertNotFound();
    }

    public function test_saving_creates_the_next_version_derived_from_the_one_edited(): void
    {
        $this->post($this->storeUrl(1), $this->save())
            ->assertRedirect($this->editUrl(2))
            ->assertSessionHas('success', __('formulas.version_saved', ['version' => 2]));

        $v2 = $this->formula->versions()->where('version', 2)->firstOrFail();
        $this->assertSame(FormulaStatus::Draft, $v2->status);
        $this->assertSame('More foam', $v2->reason);
        $this->assertSame($this->v1->id, $v2->parent_version_id);
        $this->assertCount(2, $v2->lines);
        // The edited version is untouched.
        $this->assertTrue($this->v1->fresh()->verifyHash());
        $this->assertCount(2, $this->v1->fresh()->lines);
    }

    public function test_a_new_version_can_start_from_an_approved_one_and_keeps_it_as_its_parent(): void
    {
        $approved = FormulaVersion::factory()->approved()->create(['formula_id' => $this->formula->id, 'version' => 2, 'reason' => 'Approved']);

        $this->post($this->storeUrl(2), $this->save(['reason' => 'Start again']))->assertRedirect($this->editUrl(3));

        $this->assertSame($approved->id, $this->formula->versions()->where('version', 3)->firstOrFail()->parent_version_id);
        $this->assertSame(FormulaStatus::Approved, $approved->fresh()->status);
    }

    public function test_a_version_started_from_an_older_one_is_its_child_not_the_latest_one(): void
    {
        app(FormulaVersionService::class)->createVersion($this->formula, [['trade_material_id' => $this->sles->id, 'percent_ww' => '100']], 'Simplified');

        $this->post($this->storeUrl(1), $this->save(['reason' => 'Branch']))->assertRedirect($this->editUrl(3));

        $this->assertSame($this->v1->id, $this->formula->versions()->where('version', 3)->firstOrFail()->parent_version_id);
    }

    public function test_the_reason_is_required_with_a_translated_message(): void
    {
        $this->from($this->editUrl(1))->post($this->storeUrl(1), $this->save(['reason' => '']))
            ->assertRedirect($this->editUrl(1))
            ->assertSessionHasErrors(['reason' => __('validation.required', ['attribute' => __('validation.attributes.reason')])]);

        $this->assertSame(1, $this->formula->versions()->count());
    }

    public function test_a_whitespace_reason_is_refused_by_the_domain_rule(): void
    {
        $this->from($this->editUrl(1))->post($this->storeUrl(1), $this->save(['reason' => '   ']))
            ->assertSessionHasErrors('reason');

        $this->assertSame(1, $this->formula->versions()->count());
    }

    public function test_invalid_lines_are_refused_and_nothing_is_saved(): void
    {
        $this->post($this->storeUrl(1), $this->save(['lines' => []]))->assertSessionHasErrors('lines');
        $this->post($this->storeUrl(1), $this->save(['lines' => [['trade_material_id' => $this->sles->id, 'percent_ww' => '101']]]))
            ->assertSessionHasErrors(['lines.0.percent_ww' => __('formulas.errors.percent_over_100')]);
        $this->post($this->storeUrl(1), $this->save(['lines' => [['trade_material_id' => fake()->uuid(), 'percent_ww' => '5']]]))
            ->assertSessionHasErrors('lines.0.trade_material_id');

        $this->assertSame(1, $this->formula->versions()->count());
    }

    public function test_the_balance_event_is_kept_in_the_reason_and_in_the_audit_trail(): void
    {
        $this->post($this->storeUrl(1), $this->save(['balance_events' => [['material_id' => $this->water->id, 'before' => null, 'after' => '88']]]))
            ->assertSessionHasNoErrors();

        $v2 = $this->formula->versions()->where('version', 2)->firstOrFail();
        $note = __('formulas.balance_note', ['material' => 'Water', 'before' => '—', 'after' => '88']);
        $this->assertSame("More foam [{$note}]", $v2->reason);

        $entry = AuditLog::query()->where('table_name', 'formula_versions')->where('record_id', $v2->id)->firstOrFail();
        $this->assertEquals([['material' => 'Water', 'before' => null, 'after' => '88']], $entry->after['balance_events']);
        $this->assertSame($note, $entry->reason);
    }

    public function test_a_balance_event_that_does_not_match_the_q_s_line_is_refused(): void
    {
        $this->post($this->storeUrl(1), $this->save(['balance_events' => [['material_id' => $this->water->id, 'before' => null, 'after' => '70']]]))
            ->assertSessionHasErrors('balance_events.0.after');
        $this->post($this->storeUrl(1), $this->save(['balance_events' => [['material_id' => $this->sles->id, 'before' => null, 'after' => '12']]]))
            ->assertSessionHasErrors('balance_events.0.after');

        $this->assertSame(1, $this->formula->versions()->count());
    }

    public function test_two_q_s_lines_are_refused(): void
    {
        $this->post($this->storeUrl(1), $this->save(['lines' => [
            ['trade_material_id' => $this->sles->id, 'percent_ww' => '12', 'is_qs' => true],
            ['trade_material_id' => $this->water->id, 'percent_ww' => '88', 'is_qs' => true],
        ]]))->assertSessionHasErrors(['lines' => __('formulas.errors.single_qs')]);
    }

    public function test_a_q_s_line_may_be_saved_unbalanced_with_no_percentage(): void
    {
        $this->post($this->storeUrl(1), $this->save(['lines' => [
            ['trade_material_id' => $this->sles->id, 'percent_ww' => '12', 'is_qs' => false],
            ['trade_material_id' => $this->water->id, 'percent_ww' => null, 'is_qs' => true],
        ]]))->assertSessionHasNoErrors();

        $line = $this->formula->versions()->where('version', 2)->firstOrFail()->lines[1];
        $this->assertNull($line->percent_ww);
        $this->assertTrue($line->is_qs);
    }
}
