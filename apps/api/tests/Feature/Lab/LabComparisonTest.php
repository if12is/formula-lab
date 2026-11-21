<?php

namespace Tests\Feature\Lab;

use App\Enums\Confidence;
use App\Enums\LabProperty;
use App\Models\LabMeasurement;
use App\Models\PropertyValue;
use App\Models\Source;
use App\Services\Lab\LabComparisonService;
use App\Services\Lab\LabTrialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsLabFixtures;
use Tests\TestCase;

/** O-05: what the comparison view of a trial is handed (the engine computes the expected value and the difference in the page). */
class LabComparisonTest extends TestCase
{
    use BuildsLabFixtures, RefreshDatabase;

    public function test_it_hands_over_the_lines_with_the_registered_active_matter_and_the_latest_measured_one(): void
    {
        $version = $this->versionWithLines(['code' => 'F-0400']);
        [$first, $qs] = $version->lines;
        $source = Source::factory()->create(['title' => 'Batch certificate']);
        PropertyValue::factory()->create(['entity_type' => 'trade_material', 'entity_id' => $first->trade_material_id, 'property' => 'active_matter', 'unit' => '%w/w', 'value' => '70', 'source_id' => $source->id, 'confidence' => Confidence::L1CoaBatch]);
        PropertyValue::factory()->create(['entity_type' => 'trade_material', 'entity_id' => $first->trade_material_id, 'property' => 'density', 'unit' => 'g/mL', 'value' => '1.1']);
        $trial = $this->trialWith($version, [], false);
        $trials = app(LabTrialService::class);
        $trials->record($trial, ['property' => 'active_matter', 'value' => '6.9', 'unit' => '%w/w', 'method' => 'MBAS', 'instrument' => 'Spectro', 'measured_at' => '2026-11-20T10:00']);
        $latest = $trials->record($trial, ['property' => 'active_matter', 'value' => '7.1', 'unit' => '%w/w', 'method' => 'Titration', 'instrument' => 'Burette', 'measured_at' => '2026-11-20T11:00']);

        $comparison = app(LabComparisonService::class)->forTrial($trial);

        $this->assertSame('active_matter', $comparison['property']);
        $this->assertCount(2, $comparison['lines']);
        $this->assertSame('10', $comparison['lines'][0]['percent']);
        $this->assertFalse($comparison['lines'][0]['is_qs']);
        $this->assertSame(['active_matter'], array_column($comparison['lines'][0]['properties'], 'property'), 'only the compared property is handed over');
        $this->assertSame('70', $comparison['lines'][0]['properties'][0]['value']);
        $this->assertSame('Batch certificate', $comparison['lines'][0]['properties'][0]['source_title']);
        $this->assertNull($comparison['lines'][1]['percent']);
        $this->assertTrue($comparison['lines'][1]['is_qs']);
        $this->assertSame([], $comparison['lines'][1]['properties']);
        $this->assertSame('7.1', $comparison['measured']['value']);
        $this->assertSame('lab_measurement:'.$latest->id, $comparison['measured']['sourceId']);
        $this->assertSame('L1_coa_batch', $comparison['measured']['confidence']);
        $this->assertSame('Titration', $comparison['measured']['method']);
        $this->assertSame('2026-11-20', $comparison['measured']['validFrom']);
    }

    public function test_without_an_active_matter_reading_the_measured_side_is_null_not_zero(): void
    {
        $trial = $this->trialWith($this->versionWithLines(), [LabProperty::Ph], false);

        $this->assertNull(app(LabComparisonService::class)->forTrial($trial)['measured']);
    }

    public function test_active_matter_is_a_measurable_property_with_a_percent_unit_and_no_limit(): void
    {
        $this->assertSame(['%w/w'], LabProperty::ActiveMatter->units());
        $this->assertSame(['min' => null, 'max' => null], LabProperty::ActiveMatter->limits());
        $this->assertNull(LabProperty::ActiveMatter->protocolCode());
    }

    public function test_the_trial_page_carries_the_comparison_block(): void
    {
        $trial = $this->trialWith($this->versionWithLines(), [], false);
        LabMeasurement::factory()->create(['lab_trial_id' => $trial->id, 'property' => LabProperty::ActiveMatter, 'unit' => '%w/w', 'value' => '9.5']);

        $this->get(route('lab.trials.show', $trial))->assertInertia(fn (Assert $page) => $page
            ->where('comparison.property', 'active_matter')
            ->has('comparison.lines', 2)
            ->where('comparison.measured.value', '9.5'));
    }

    public function test_a_percent_unit_other_than_w_w_is_refused_for_active_matter(): void
    {
        $trial = $this->trialWith($this->versionWithLines(), [], false);

        $this->post(route('lab.trials.measurements.store', $trial), ['property' => 'active_matter', 'value' => '9', 'unit' => '%', 'method' => 'MBAS', 'instrument' => 'i', 'measured_at' => '2026-11-20T10:00'])
            ->assertSessionHasErrors('unit');
    }
}
