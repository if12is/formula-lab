<?php

namespace Tests\Feature\Formulas;

use App\Enums\FormulaPhase;
use App\Models\Formula;
use App\Models\FormulaLine;
use App\Models\FormulaVersion;
use App\Models\TradeMaterial;
use App\Services\Formula\FormulaDiffService;
use App\Services\Formula\FormulaVersionService;
use App\Support\DecimalNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** M-01: phases (water, oil, cool-down) and the addition temperature on formula lines. */
class FormulaPhaseTest extends TestCase
{
    use RefreshDatabase;

    private Formula $formula;

    private TradeMaterial $water;

    private TradeMaterial $oil;

    private TradeMaterial $preservative;

    protected function setUp(): void
    {
        parent::setUp();

        $this->water = TradeMaterial::factory()->create(['trade_name' => 'Water']);
        $this->oil = TradeMaterial::factory()->create(['trade_name' => 'Oil']);
        $this->preservative = TradeMaterial::factory()->create(['trade_name' => 'Preservative']);
        $this->formula = Formula::factory()->create(['code' => 'F-0002', 'name' => 'Cream']);
        app(FormulaVersionService::class)->createVersion($this->formula, [
            ['trade_material_id' => $this->oil->id, 'percent_ww' => '25'],
            ['trade_material_id' => $this->water->id, 'percent_ww' => '75'],
        ], null);
    }

    /** @return array<string, mixed> */
    private function save(array $lines): array
    {
        return ['reason' => 'Phases', 'lines' => $lines, 'balance_events' => []];
    }

    private function store(array $lines): TestResponse
    {
        return $this->post(route('formulas.versions.store', [$this->formula, 1]), $this->save($lines));
    }

    public function test_a_version_saves_phases_and_addition_temperatures(): void
    {
        $this->store([
            ['trade_material_id' => $this->oil->id, 'percent_ww' => '25', 'is_qs' => false, 'phase' => 'oil', 'addition_temp_c' => '75'],
            ['trade_material_id' => $this->water->id, 'percent_ww' => '70', 'is_qs' => false, 'phase' => 'water', 'addition_temp_c' => '75.5'],
            ['trade_material_id' => $this->preservative->id, 'percent_ww' => '5', 'is_qs' => false, 'phase' => 'cool_down', 'addition_temp_c' => '40'],
        ])->assertSessionHasNoErrors();

        $lines = $this->formula->versions()->where('version', 2)->firstOrFail()->lines;
        $this->assertSame(['oil', 'water', 'cool_down'], $lines->pluck('phase')->all());
        $this->assertSame(['75', '75.5', '40'], $lines->map(fn (FormulaLine $line): string => DecimalNormalizer::normalize($line->addition_temp_c))->all());
    }

    public function test_the_editor_sends_the_phase_options_and_each_lines_temperature(): void
    {
        $this->store([
            ['trade_material_id' => $this->oil->id, 'percent_ww' => '100', 'is_qs' => false, 'phase' => 'oil', 'addition_temp_c' => '72.50'],
        ])->assertSessionHasNoErrors();

        $this->get(route('formulas.versions.edit', [$this->formula, 2]))->assertInertia(fn (Assert $page) => $page
            ->where('phaseOptions', FormulaPhase::options())
            ->where('lines.0.phase', 'oil')
            ->where('lines.0.addition_temp_c', '72.5'));
    }

    public function test_lines_without_phase_or_temperature_still_save_as_before(): void
    {
        $this->store([
            ['trade_material_id' => $this->oil->id, 'percent_ww' => '40', 'is_qs' => false, 'phase' => null],
            ['trade_material_id' => $this->water->id, 'percent_ww' => '60', 'is_qs' => false],
        ])->assertSessionHasNoErrors();

        $line = $this->formula->versions()->where('version', 2)->firstOrFail()->lines->first();
        $this->assertNull($line->phase);
        $this->assertNull($line->addition_temp_c);
    }

    public function test_a_phase_typed_before_phases_were_a_list_is_still_accepted(): void
    {
        $this->store([['trade_material_id' => $this->oil->id, 'percent_ww' => '100', 'phase' => 'A']])->assertSessionHasNoErrors();

        $this->assertSame('A', $this->formula->versions()->where('version', 2)->firstOrFail()->lines->first()->phase);
    }

    public function test_it_refuses_a_temperature_that_is_not_a_plain_non_negative_decimal(): void
    {
        foreach (['hot', '-5', '1e2', '75.123', '1000'] as $bad) {
            $this->store([['trade_material_id' => $this->oil->id, 'percent_ww' => '100', 'phase' => 'oil', 'addition_temp_c' => $bad]])
                ->assertSessionHasErrors('lines.0.addition_temp_c');
        }

        $this->assertSame(1, $this->formula->versions()->count());
    }

    public function test_the_temperature_changes_the_hash_but_its_absence_leaves_old_hashes_alone(): void
    {
        $plain = new FormulaLine(['line_no' => 1, 'trade_material_id' => 'm-1', 'percent_ww' => '10', 'is_qs' => false, 'phase' => 'oil']);
        $hot = new FormulaLine(['line_no' => 1, 'trade_material_id' => 'm-1', 'percent_ww' => '10', 'is_qs' => false, 'phase' => 'oil', 'addition_temp_c' => '75.0']);

        $this->assertArrayNotHasKey('additionTempC', $plain->canonical());
        $this->assertSame('75', $hot->canonical()['additionTempC']);
        $this->assertNotSame(
            FormulaVersion::hashLines([$plain->canonical()]),
            FormulaVersion::hashLines([$hot->canonical()]),
        );
    }

    public function test_the_diff_reports_a_changed_temperature_and_phase(): void
    {
        $this->store([['trade_material_id' => $this->oil->id, 'percent_ww' => '25', 'phase' => 'oil', 'addition_temp_c' => '70']]);
        $this->post(route('formulas.versions.store', [$this->formula, 2]), $this->save([
            ['trade_material_id' => $this->oil->id, 'percent_ww' => '25', 'phase' => 'cool_down', 'addition_temp_c' => '40'],
        ]))->assertSessionHasNoErrors();

        $versions = $this->formula->versions()->orderBy('version')->get();
        $diff = app(FormulaDiffService::class)->diff($versions[1], $versions[2]);

        $this->assertSame(['from' => '70', 'to' => '40'], $diff['changed'][0]['changes']['addition_temp_c']);
        $this->assertSame(['from' => 'oil', 'to' => 'cool_down'], $diff['changed'][0]['changes']['phase']);
    }

    public function test_the_phase_enum_has_translated_labels_in_both_languages(): void
    {
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            foreach (FormulaPhase::cases() as $case) {
                $this->assertNotSame('enums.formula_phase.'.$case->value, $case->label());
            }
        }
    }
}
