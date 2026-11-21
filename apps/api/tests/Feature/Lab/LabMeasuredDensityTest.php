<?php

namespace Tests\Feature\Lab;

use App\Enums\LabProperty;
use App\Models\FormulaVersion;
use App\Models\LabMeasurement;
use App\Models\LabTrial;
use App\Services\Formula\FormulaVersionService;
use App\Services\Lab\LabMeasuredDensityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsLabFixtures;
use Tests\TestCase;

/** O-04: the density measured on a trial of a version is linked to that version and handed to the litre/kilo conversion. */
class LabMeasuredDensityTest extends TestCase
{
    use BuildsLabFixtures, RefreshDatabase;

    private FormulaVersion $version;

    protected function setUp(): void
    {
        parent::setUp();

        $this->version = $this->versionWithLines(['code' => 'F-0300']);
    }

    private function density(FormulaVersion $version, string $value, string $measuredAt, string $unit = 'g/mL'): LabMeasurement
    {
        $trial = LabTrial::factory()->create(['formula_version_id' => $version->id]);

        return LabMeasurement::factory()->create([
            'lab_trial_id' => $trial->id, 'property' => LabProperty::Density, 'value' => $value, 'unit' => $unit, 'measured_at' => $measuredAt,
        ]);
    }

    public function test_a_version_without_a_density_reading_has_none(): void
    {
        $service = app(LabMeasuredDensityService::class);

        $this->assertNull($service->forVersion($this->version));
        $this->assertNull($service->payload($this->version));
    }

    public function test_the_newest_density_reading_of_the_version_is_the_one_used(): void
    {
        $this->density($this->version, '1.02', '2026-11-10 09:00:00');
        $newest = $this->density($this->version, '1.04', '2026-11-12 09:00:00', 'kg/L');
        $this->density($this->version, '1.03', '2026-11-11 09:00:00');

        $payload = app(LabMeasuredDensityService::class)->payload($this->version);

        $this->assertSame('1.04', $payload['value']);
        $this->assertSame('kg/L', $payload['unit']);
        $this->assertSame('lab_measurement:'.$newest->id, $payload['sourceId']);
        $this->assertSame('L1_coa_batch', $payload['confidence']);
        $this->assertSame('2026-11-12', $payload['validFrom']);
        $this->assertSame('F-0300', $payload['formula']);
        $this->assertSame(1, $payload['version']);
    }

    public function test_another_version_or_formula_never_lends_its_density(): void
    {
        $second = app(FormulaVersionService::class)->createVersion($this->version->formula, [
            ['trade_material_id' => $this->version->lines->first()->trade_material_id, 'percent_ww' => '100'],
        ], 'Second version');
        $this->density($second, '0.98', '2026-11-12 09:00:00');
        $this->density($this->versionWithLines(), '1.5', '2026-11-13 09:00:00');

        $this->assertNull(app(LabMeasuredDensityService::class)->payload($this->version));
        $this->assertSame('0.98', app(LabMeasuredDensityService::class)->payload($second)['value']);
    }

    public function test_a_non_density_reading_is_ignored(): void
    {
        $trial = LabTrial::factory()->create(['formula_version_id' => $this->version->id]);
        LabMeasurement::factory()->create(['lab_trial_id' => $trial->id, 'property' => LabProperty::Viscosity, 'unit' => 'mPa·s', 'value' => '1000']);

        $this->assertNull(app(LabMeasuredDensityService::class)->payload($this->version));
    }

    public function test_the_density_route_answers_json_for_the_calculator(): void
    {
        $this->getJson(route('lab.formula-versions.density', $this->version))->assertOk()->assertExactJson(['density' => null]);

        $reading = $this->density($this->version, '1.04', '2026-11-12 09:00:00');

        $this->getJson(route('lab.formula-versions.density', $this->version))
            ->assertOk()
            ->assertJsonPath('density.value', '1.04')
            ->assertJsonPath('density.sourceId', 'lab_measurement:'.$reading->id)
            ->assertJsonPath('density.trial', $reading->trial->code);
        $this->getJson('/lab/formula-versions/not-a-uuid/density')->assertNotFound();
    }

    public function test_the_trial_page_shows_the_density_of_its_version(): void
    {
        $reading = $this->density($this->version, '1.04', '2026-11-12 09:00:00');

        $this->get(route('lab.trials.show', $reading->trial))->assertInertia(fn (Assert $page) => $page
            ->where('density.value', '1.04')
            ->where('density.trial', $reading->trial->code));
    }

    public function test_the_calculator_page_offers_the_version_lookup(): void
    {
        $this->get(route('calculators.show', 'litre-kilo'))->assertInertia(fn (Assert $page) => $page
            ->where('urls.formulaVersions', route('lab.formula-versions.search', [], false)));
    }
}
