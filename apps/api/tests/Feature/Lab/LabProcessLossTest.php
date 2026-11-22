<?php

namespace Tests\Feature\Lab;

use App\Enums\LabProperty;
use App\Models\FormulaVersion;
use App\Models\LabMeasurement;
use App\Models\LabTrial;
use App\Models\User;
use App\Services\Lab\LabProcessLossService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLabFixtures;
use Tests\TestCase;

/** L-04: the loss measured on the trials of a version is handed to the perfume cost as a sourced value, missing stays missing. */
class LabProcessLossTest extends TestCase
{
    use BuildsLabFixtures, RefreshDatabase;

    private FormulaVersion $version;

    protected function setUp(): void
    {
        parent::setUp();

        $this->version = $this->versionWithLines(['code' => 'F-0400']);
    }

    private function loss(LabProperty $property, string $value, string $measuredAt): LabMeasurement
    {
        $trial = LabTrial::factory()->create(['formula_version_id' => $this->version->id]);

        return LabMeasurement::factory()->create([
            'lab_trial_id' => $trial->id, 'property' => $property, 'value' => $value, 'unit' => '%w/w', 'measured_at' => $measuredAt,
        ]);
    }

    public function test_the_loss_properties_are_percent_measurements_without_a_limit(): void
    {
        foreach ([LabProperty::AgeingLoss, LabProperty::ChillingLoss, LabProperty::FiltrationLoss] as $property) {
            $this->assertSame(['%w/w'], $property->units());
            $this->assertSame(['min' => null, 'max' => null], $property->limits());
        }
    }

    public function test_every_stage_is_null_without_a_reading_never_zero(): void
    {
        $payload = app(LabProcessLossService::class)->payload($this->version);

        $this->assertSame(['ageing' => null, 'chilling' => null, 'filtration' => null], $payload);
    }

    public function test_each_stage_takes_its_newest_reading_with_its_trial_as_the_source(): void
    {
        $this->loss(LabProperty::AgeingLoss, '3', '2026-11-10 09:00:00');
        $newest = $this->loss(LabProperty::AgeingLoss, '2.5', '2026-11-12 09:00:00');
        $this->loss(LabProperty::FiltrationLoss, '1', '2026-11-11 09:00:00');

        $payload = app(LabProcessLossService::class)->payload($this->version);

        $this->assertSame('2.5', $payload['ageing']['value']);
        $this->assertSame('%w/w', $payload['ageing']['unit']);
        $this->assertSame('lab_measurement:'.$newest->id, $payload['ageing']['sourceId']);
        $this->assertSame('2026-11-12', $payload['ageing']['validFrom']);
        $this->assertSame('F-0400', $payload['ageing']['formula']);
        $this->assertSame('1', $payload['filtration']['value']);
        $this->assertNull($payload['chilling']);
    }

    public function test_a_reading_of_another_version_is_not_used(): void
    {
        $other = $this->versionWithLines(['code' => 'F-0401']);
        $trial = LabTrial::factory()->create(['formula_version_id' => $other->id]);
        LabMeasurement::factory()->create(['lab_trial_id' => $trial->id, 'property' => LabProperty::ChillingLoss, 'value' => '4', 'unit' => '%w/w']);

        $this->assertNull(app(LabProcessLossService::class)->payload($this->version)['chilling']);
    }

    public function test_the_json_endpoint_returns_the_losses_of_the_version(): void
    {
        $this->loss(LabProperty::ChillingLoss, '1.5', '2026-11-12 09:00:00');

        $this->actingAs(User::factory()->create())
            ->getJson(route('lab.formula-versions.process-losses', $this->version))
            ->assertOk()
            ->assertJsonPath('losses.chilling.value', '1.5')
            ->assertJsonPath('losses.ageing', null);
    }
}
