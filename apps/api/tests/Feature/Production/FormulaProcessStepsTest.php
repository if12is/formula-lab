<?php

namespace Tests\Feature\Production;

use App\Enums\Confidence;
use App\Exceptions\Domain\FormulaProcessException;
use App\Models\Formula;
use App\Models\FormulaProcessCheck;
use App\Models\FormulaProcessStep;
use App\Models\FormulaVersion;
use App\Models\Source;
use App\Models\TradeMaterial;
use App\Services\Formula\FormulaProcessService;
use App\Services\Formula\FormulaVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsProductionFixtures;
use Tests\TestCase;

/** Q-10: the manufacturing steps are part of the version: in its hash, editable on a draft, frozen once approved. */
class FormulaProcessStepsTest extends TestCase
{
    use BuildsProductionFixtures, RefreshDatabase;

    private FormulaProcessService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(FormulaProcessService::class);
    }

    public function test_steps_are_saved_with_their_checks_and_are_part_of_the_version_hash(): void
    {
        $version = $this->productionProduct();

        $this->assertSame(3, FormulaProcessStep::query()->where('formula_version_id', $version->id)->count());
        $this->assertSame(1, FormulaProcessCheck::query()->count());
        $this->assertSame(3, $version->processSteps()->firstOrFail()->formulaLine->line_no, 'The first step adds line 3 (the water).');
        $this->assertTrue($version->verifyHash());
        $this->assertNotSame(FormulaVersion::hashLines($version->canonicalLines()), $version->hash, 'The hash covers the steps, not the lines alone.');
    }

    public function test_a_version_without_steps_keeps_the_hash_of_its_lines_alone(): void
    {
        $version = $this->productionProduct(withSteps: false);

        $this->assertSame(FormulaVersion::hashLines($version->canonicalLines()), $version->hash);
        $this->assertTrue($version->verifyHash());
    }

    public function test_changing_a_step_changes_the_hash_and_the_same_steps_give_the_same_hash(): void
    {
        $formula = Formula::factory()->create();
        $material = TradeMaterial::factory()->create(['substance_id' => null]);
        $lines = [['trade_material_id' => $material->id, 'percent_ww' => '100']];
        $first = app(FormulaVersionService::class)->createVersion($formula, $lines, null);
        $second = app(FormulaVersionService::class)->createVersion($formula, $lines, 'Same lines');
        $linesOnlyHash = $first->hash;
        $steps = [['kind' => 'add', 'line_no' => '1', 'temperature_c' => '40', 'checks' => []]];

        $this->assertSame($first->hash, $second->hash, 'Two versions with the same lines hash the same.');
        $this->assertSame($this->service->replace($first, $steps)->hash, $this->service->replace($second, $steps)->hash, 'The same lines and steps hash the same.');

        $this->assertNotSame($first->fresh()->hash, $this->service->replace($first, [['kind' => 'add', 'line_no' => '1', 'temperature_c' => '41', 'checks' => []]])->hash);
        $this->assertSame($linesOnlyHash, $this->service->replace($first, [])->hash, 'Without steps the hash is the lines alone again.');
    }

    public function test_step_order_is_part_of_the_hash(): void
    {
        $material = TradeMaterial::factory()->create(['substance_id' => null]);
        $formula = Formula::factory()->create();
        $version = app(FormulaVersionService::class)->createVersion($formula, [['trade_material_id' => $material->id, 'percent_ww' => '100']], null);
        $heat = ['kind' => 'heat', 'temperature_c' => '70', 'checks' => []];
        $mix = ['kind' => 'mix', 'mixing_speed_rpm' => '60', 'checks' => []];

        $one = $this->service->replace($version, [$heat, $mix])->hash;
        $two = $this->service->replace($version, [$mix, $heat])->hash;

        $this->assertNotSame($one, $two);
    }

    public function test_an_approved_version_refuses_new_steps_in_the_service_and_in_the_database(): void
    {
        $version = $this->productionProduct();

        $this->assertThrowsException(FormulaProcessException::class, fn () => $this->service->replace($version, []));

        $this->assertDatabaseRefuses(fn () => FormulaProcessStep::query()->create(['formula_version_id' => $version->id, 'step_no' => 9, 'kind' => 'mix']));
        $this->assertDatabaseRefuses(fn () => DB::table('formula_process_steps')->where('formula_version_id', $version->id)->update(['note' => 'x']));
        $this->assertDatabaseRefuses(fn () => DB::table('formula_process_checks')->delete());
    }

    public function test_a_limit_needs_its_source_and_confidence_and_an_unlimited_check_needs_none(): void
    {
        $material = TradeMaterial::factory()->create(['substance_id' => null]);
        $version = app(FormulaVersionService::class)->createVersion(Formula::factory()->create(), [['trade_material_id' => $material->id, 'percent_ww' => '100']], null);

        $this->assertThrowsException(FormulaProcessException::class, fn () => $this->service->replace($version, [
            ['kind' => 'check', 'checks' => [['property' => 'ph', 'unit' => 'pH', 'limit_min' => '5', 'limit_max' => '6']]],
        ]));

        $source = Source::factory()->create();
        $saved = $this->service->replace($version, [
            ['kind' => 'check', 'checks' => [
                ['property' => 'ph', 'unit' => 'pH', 'limit_min' => '5', 'limit_max' => '6', 'source_id' => $source->id, 'confidence' => Confidence::L3Standard->value],
                ['property' => 'viscosity', 'unit' => 'mPa·s'],
            ]],
        ]);

        $checks = $saved->processSteps->firstOrFail()->checks;
        $this->assertSame(['5', '6'], [$checks[0]->limit_min, $checks[0]->limit_max]);
        $this->assertSame([null, null, null], [$checks[1]->limit_min, $checks[1]->limit_max, $checks[1]->source_id]);

        // The database refuses a limit without a source as well.
        $this->assertDatabaseRefuses(fn () => FormulaProcessCheck::query()->create(['formula_process_step_id' => $saved->processSteps->firstOrFail()->id, 'check_no' => 9, 'property' => 'ph', 'unit' => 'pH', 'limit_max' => '7']));
    }

    public function test_an_add_step_needs_a_line_that_exists_and_a_unit_must_fit_its_property(): void
    {
        $material = TradeMaterial::factory()->create(['substance_id' => null]);
        $version = app(FormulaVersionService::class)->createVersion(Formula::factory()->create(), [['trade_material_id' => $material->id, 'percent_ww' => '100']], null);

        $this->assertThrowsException(FormulaProcessException::class, fn () => $this->service->replace($version, [['kind' => 'add', 'checks' => []]]));
        $this->assertThrowsException(FormulaProcessException::class, fn () => $this->service->replace($version, [['kind' => 'add', 'line_no' => '7', 'checks' => []]]));
        $this->assertThrowsException(FormulaProcessException::class, fn () => $this->service->replace($version, [['kind' => 'check', 'checks' => [['property' => 'ph', 'unit' => 'mPa·s']]]]));
    }

    public function test_the_process_page_and_save_work_over_http_and_a_frozen_version_is_read_only(): void
    {
        $version = $this->productionProduct(withSteps: false);
        // The fixture approved it: make a second, draft version to edit.
        $draft = app(FormulaVersionService::class)->createVersion($this->formula, [['trade_material_id' => $this->water->id, 'percent_ww' => '100']], 'Draft for steps');

        $this->get(route('formulas.versions.process', [$this->formula, $draft->version]))->assertOk()->assertInertia(fn ($page) => $page
            ->component('Formulas/Process')->where('version.editable', true)->has('choices.kinds'));

        $this->put(route('formulas.versions.process.update', [$this->formula, $draft->version]), ['steps' => [['kind' => 'mix', 'mixing_speed_rpm' => '60', 'duration_minutes' => '12']]])->assertSessionHasNoErrors();
        $this->assertSame(1, $draft->processSteps()->count());

        $this->put(route('formulas.versions.process.update', [$this->formula, $draft->version]), ['steps' => [['kind' => 'mix', 'mixing_speed_rpm' => '0']]])->assertSessionHasErrors('steps.0.mixing_speed_rpm');
        $this->put(route('formulas.versions.process.update', [$this->formula, $version->version]), ['steps' => []])->assertSessionHas('error');
        $this->get(route('formulas.versions.process', [$this->formula, $version->version]))->assertInertia(fn ($page) => $page->where('version.editable', false));
    }
}
