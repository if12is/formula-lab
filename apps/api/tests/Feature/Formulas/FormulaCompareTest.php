<?php

namespace Tests\Feature\Formulas;

use App\Enums\FormulaStatus;
use App\Models\Formula;
use App\Models\FormulaVersion;
use App\Models\TradeMaterial;
use App\Services\Formula\FormulaVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FormulaCompareTest extends TestCase
{
    use RefreshDatabase;

    private Formula $formula;

    protected function setUp(): void
    {
        parent::setUp();

        $water = TradeMaterial::factory()->create(['trade_name' => 'Water']);
        $sles = TradeMaterial::factory()->create(['trade_name' => 'SLES']);
        $salt = TradeMaterial::factory()->create(['trade_name' => 'Salt']);
        $this->formula = Formula::factory()->create(['code' => 'F-0001']);
        $service = app(FormulaVersionService::class);

        $service->createVersion($this->formula, [
            ['trade_material_id' => $sles->id, 'percent_ww' => '10', 'phase' => 'A'],
            ['trade_material_id' => $water->id, 'percent_ww' => '90'],
        ], null);
        $service->createVersion($this->formula, [
            ['trade_material_id' => $sles->id, 'percent_ww' => '12', 'phase' => 'A'],
            ['trade_material_id' => $water->id, 'percent_ww' => '87'],
            ['trade_material_id' => $salt->id, 'percent_ww' => '1'],
        ], 'More foam and salt');
        $service->createVersion($this->formula, [
            ['trade_material_id' => $sles->id, 'percent_ww' => '12', 'phase' => 'A'],
            ['trade_material_id' => $water->id, 'percent_ww' => '88'],
        ], 'Salt out again');
    }

    public function test_it_compares_the_last_two_versions_by_default(): void
    {
        $this->get('/formulas/'.$this->formula->id.'/compare')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Formulas/Compare')
            ->where('from', 2)
            ->where('to', 3)
            ->has('history', 3)
            ->has('diff.removed', 1)
            ->where('diff.removed.0.material', 'Salt')
            ->has('diff.added', 0)
            ->has('diff.changed', 1)
            ->where('diff.changed.0.material', 'Water')
            ->where('diff.changed.0.changes.percent_ww.from', '87')
            ->where('diff.changed.0.changes.percent_ww.to', '88')
            ->where('diff.meta.reason.to', 'Salt out again')
            ->where('diff.identical', false));
    }

    public function test_it_compares_any_two_versions(): void
    {
        $this->get('/formulas/'.$this->formula->id.'/compare?from=1&to=2')->assertInertia(fn (Assert $page) => $page
            ->where('from', 1)
            ->where('to', 2)
            ->has('diff.added', 1)
            ->where('diff.added.0.material', 'Salt')
            ->where('diff.added.0.percent_ww', '1')
            ->has('diff.changed', 2));
    }

    public function test_comparing_the_other_way_swaps_added_and_removed(): void
    {
        $this->get('/formulas/'.$this->formula->id.'/compare?from=2&to=1')->assertInertia(fn (Assert $page) => $page
            ->has('diff.removed', 1)
            ->has('diff.added', 0));
    }

    public function test_a_version_against_itself_is_identical(): void
    {
        $this->get('/formulas/'.$this->formula->id.'/compare?from=2&to=2')->assertInertia(fn (Assert $page) => $page->where('diff.identical', true));
    }

    public function test_a_status_change_shows_in_the_details(): void
    {
        $version = FormulaVersion::factory()->create(['formula_id' => $this->formula->id, 'version' => 4, 'reason' => 'Lab', 'status' => FormulaStatus::LabTesting]);
        $this->formula->versions()->where('version', 3)->firstOrFail()->lines->each(fn ($line) => $version->lines()->create($line->only(['line_no', 'trade_material_id', 'percent_ww', 'is_qs', 'phase'])));

        $this->get('/formulas/'.$this->formula->id.'/compare?from=3&to=4')->assertInertia(fn (Assert $page) => $page
            ->where('diff.meta.status.from', 'draft')
            ->where('diff.meta.status.to', 'lab_testing'));
    }

    public function test_a_formula_with_a_single_version_compares_it_with_itself(): void
    {
        $single = Formula::factory()->create();
        FormulaVersion::factory()->create(['formula_id' => $single->id]);

        $this->get('/formulas/'.$single->id.'/compare')->assertInertia(fn (Assert $page) => $page->where('from', 1)->where('to', 1)->where('diff.identical', true));
    }

    public function test_an_unknown_version_unknown_formula_or_bad_number_is_refused(): void
    {
        $this->get('/formulas/'.$this->formula->id.'/compare?from=1&to=9')->assertNotFound();
        $this->get('/formulas/'.fake()->uuid().'/compare')->assertNotFound();
        $this->get('/formulas/'.$this->formula->id.'/compare?from=abc')->assertSessionHasErrors('from');
        $this->get('/formulas/'.$this->formula->id.'/compare?to=0')->assertSessionHasErrors('to');
    }

    public function test_a_formula_without_versions_has_nothing_to_compare(): void
    {
        $this->get('/formulas/'.Formula::factory()->create()->id.'/compare')->assertNotFound();
    }
}
