<?php

namespace Tests\Feature\Lab;

use App\Enums\LabProperty;
use App\Exceptions\Domain\LabTrialException;
use App\Models\Formula;
use App\Models\FormulaVersion;
use App\Models\LabMeasurement;
use App\Models\LabTrial;
use App\Services\Formula\FormulaVersionService;
use App\Services\Lab\FormulaSaltCurveService;
use App\Services\Lab\LabCoverageService;
use App\Services\Lab\LabTrialService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsLabFixtures;
use Tests\TestCase;

/** O-03: the formula's own salt curve is made of its viscosity readings that state an added salt %. */
class LabSaltCurveTest extends TestCase
{
    use BuildsLabFixtures, RefreshDatabase;

    private FormulaVersion $version;

    protected function setUp(): void
    {
        parent::setUp();

        $this->version = $this->versionWithLines(['code' => 'F-0100']);
    }

    /** @param  array<string, mixed>  $override */
    private function reading(LabTrial $trial, string $salt, string $viscosity, array $override = []): LabMeasurement
    {
        return app(LabTrialService::class)->record($trial, [
            'property' => 'viscosity',
            'value' => $viscosity,
            'unit' => 'mPa·s',
            'salt_pct' => $salt,
            'method' => 'Bench method',
            'instrument' => 'Brookfield 1',
            'measured_at' => '2026-11-20T10:00',
            ...$override,
        ]);
    }

    private function openTrial(?FormulaVersion $version = null): LabTrial
    {
        return $this->trialWith($version ?? $this->version, [], false);
    }

    public function test_a_viscosity_reading_can_state_the_salt_added_and_keeps_it_exactly(): void
    {
        $reading = $this->reading($this->openTrial(), '1.50', '3200');

        $this->assertSame('1.50', $reading->fresh()->salt_pct);
        $this->assertSame('3200', $reading->fresh()->value);
    }

    public function test_salt_is_refused_for_any_other_property(): void
    {
        $this->assertThrowsException(LabTrialException::class, fn () => app(LabTrialService::class)->record($this->openTrial(), [
            'property' => 'ph', 'value' => '7', 'unit' => 'pH', 'salt_pct' => '1', 'method' => 'm', 'instrument' => 'i', 'measured_at' => '2026-11-20T10:00',
        ]));
    }

    public function test_a_salt_that_is_not_a_plain_non_negative_decimal_is_refused(): void
    {
        foreach (['abc', '-1', '1e3'] as $salt) {
            $this->assertThrowsException(LabTrialException::class, fn () => $this->reading($this->openTrial(), $salt, '100'));
        }
    }

    public function test_the_database_refuses_salt_on_a_non_viscosity_row(): void
    {
        $this->expectException(QueryException::class);

        LabMeasurement::factory()->create([
            'lab_trial_id' => $this->openTrial()->id, 'property' => LabProperty::Ph, 'unit' => 'pH', 'salt_pct' => '1',
        ]);
    }

    public function test_the_measurement_form_request_validates_salt(): void
    {
        $trial = $this->openTrial();
        $payload = ['property' => 'viscosity', 'value' => '100', 'unit' => 'mPa·s', 'method' => 'm', 'instrument' => 'i', 'measured_at' => '2026-11-20T10:00'];

        $this->post(route('lab.trials.measurements.store', $trial), [...$payload, 'salt_pct' => '1.5'])->assertSessionDoesntHaveErrors();
        $this->assertSame('1.5', $trial->measurements()->first()->salt_pct);

        $this->post(route('lab.trials.measurements.store', $trial), [...$payload, 'salt_pct' => 'x'])->assertSessionHasErrors('salt_pct');
        $this->post(route('lab.trials.measurements.store', $trial), [...$payload, 'property' => 'ph', 'unit' => 'pH', 'salt_pct' => '1'])->assertSessionHasErrors('salt_pct');
        $this->post(route('lab.trials.measurements.store', $trial), [...$payload, 'salt_pct' => ''])->assertSessionDoesntHaveErrors();
        $this->assertNull($trial->measurements()->get()->last()->salt_pct);
    }

    public function test_points_come_from_every_trial_of_the_formula_sorted_by_salt_and_only_from_it(): void
    {
        $second = app(FormulaVersionService::class)->createVersion($this->version->formula, [
            ['trade_material_id' => $this->version->lines->first()->trade_material_id, 'percent_ww' => '100'],
        ], 'Second version');
        $other = $this->versionWithLines(['code' => 'F-0200']);
        $this->reading($this->openTrial(), '2', '5000');
        $this->reading($this->openTrial($second), '0', '100');
        $this->reading($this->openTrial($this->version), '1', '1100');
        $this->reading($this->openTrial($other), '1', '9999');

        $points = app(FormulaSaltCurveService::class)->points($this->version->formula);

        $this->assertSame(['0', '1', '2'], array_column($points, 'salt_pct'));
        $this->assertSame(['100', '1100', '5000'], array_column($points, 'viscosity'));
        $this->assertSame([2, 1, 1], array_column($points, 'version'));
        $this->assertSame('L1_coa_batch', $points[0]['confidence']);
    }

    public function test_two_readings_at_the_same_salt_are_one_point_the_newer(): void
    {
        $this->reading($this->openTrial(), '1.0', '1000', ['measured_at' => '2026-11-20T10:00']);
        $newer = $this->reading($this->openTrial(), '1', '1250', ['measured_at' => '2026-11-21T10:00']);

        $points = app(FormulaSaltCurveService::class)->points($this->version->formula);

        $this->assertCount(1, $points);
        $this->assertSame('1250', $points[0]['viscosity']);
        $this->assertSame('lab_measurement:'.$newer->id, $points[0]['source_id']);
        $this->assertSame(2, LabMeasurement::query()->whereNotNull('salt_pct')->count(), 'the older reading stays recorded');
    }

    public function test_the_engine_payload_and_sources_carry_the_measurement_as_source(): void
    {
        $service = app(FormulaSaltCurveService::class);
        $reading = $this->reading($this->openTrial(), '0.5', '600');
        $points = $service->points($this->version->formula);

        $this->assertSame([
            'formulaId' => $this->version->formula_id,
            'points' => [['saltPct' => '0.5', 'viscosity_mPas' => '600', 'sourceId' => 'lab_measurement:'.$reading->id, 'confidence' => 'L1_coa_batch']],
        ], $service->enginePayload($this->version->formula, $points));
        $this->assertArrayHasKey('lab_measurement:'.$reading->id, $service->sources($points));
    }

    public function test_a_formula_without_readings_has_an_empty_curve(): void
    {
        $this->assertSame([], app(FormulaSaltCurveService::class)->points($this->version->formula));
    }

    public function test_salt_readings_do_not_count_as_the_formulas_own_viscosity(): void
    {
        $trial = $this->trialWith($this->version, [LabProperty::Ph, LabProperty::Density], false);
        $this->reading($trial, '1', '1100');
        $trial->update(['status' => 'completed', 'completed_at' => now()]);

        $coverage = app(LabCoverageService::class)->forVersion($this->version);

        $this->assertContains('viscosity', $coverage->missing);

        $this->get(route('lab.trials.show', $trial))->assertInertia(fn (Assert $page) => $page
            ->where('properties.2.property', 'viscosity')
            ->where('properties.2.latest', null)
            ->has('saltReadings', 1)
            ->where('saltReadings.0.salt_pct', '1'));
    }

    public function test_the_salt_curve_page_hands_the_points_and_the_curve_to_the_page(): void
    {
        $this->reading($this->openTrial(), '0', '100');
        $this->reading($this->openTrial(), '1', '1100');

        $this->get(route('formulas.salt-curve', $this->version->formula))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Formulas/SaltCurve')
                ->where('formula.code', 'F-0100')
                ->has('points', 2)
                ->where('curve.formulaId', $this->version->formula_id)
                ->has('curve.points', 2)
                ->where('curve.points.1.viscosity_mPas', '1100'));
    }

    public function test_the_salt_curve_page_of_another_formula_never_shows_these_points(): void
    {
        $this->reading($this->openTrial(), '1', '1100');
        $other = Formula::factory()->create();

        $this->get(route('formulas.salt-curve', $other))->assertInertia(fn (Assert $page) => $page->has('points', 0));
    }
}
