<?php

namespace Tests\Feature\Lab;

use App\Enums\LabProperty;
use App\Models\LabProtocol;
use App\Services\Lab\LabTrialService;
use Database\Seeders\LabProtocolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Tests\Concerns\BuildsLabFixtures;
use Tests\TestCase;

/** M-07: the emulsion stability protocols are lab protocol templates and their results are lab measurements, with no invented limit. */
class StabilityProtocolTest extends TestCase
{
    use BuildsLabFixtures, RefreshDatabase;

    private const CODES = ['stability_centrifuge', 'stability_thermal_cycles', 'stability_40c_4w'];

    public function test_the_three_stability_protocols_are_seeded_and_each_measures_its_own_property(): void
    {
        $this->seed(LabProtocolSeeder::class);

        $this->assertSame(
            ['stability_centrifuge' => LabProperty::CentrifugeSeparation, 'stability_thermal_cycles' => LabProperty::ThermalCycleSeparation, 'stability_40c_4w' => LabProperty::Stability4w],
            LabProtocol::query()->whereIn('code', self::CODES)->orderBy('position')->get()->mapWithKeys(fn (LabProtocol $protocol): array => [$protocol->code => $protocol->property])->all(),
        );

        foreach (LabProtocol::query()->whereIn('code', self::CODES)->get() as $protocol) {
            $this->assertSame($protocol->code, $protocol->property->protocolCode());
            $this->assertContains($protocol->unit, $protocol->property->units());
        }
    }

    public function test_the_only_figures_the_long_test_names_are_four_weeks_at_forty_degrees_and_nothing_else_is_fixed(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $texts = Arr::dot(require lang_path("{$locale}/lab.php"));

            foreach (['stability_centrifuge', 'stability_thermal_cycles'] as $code) {
                foreach (config("lab.protocols.{$code}.steps") as $step) {
                    $this->assertDoesNotMatchRegularExpression('/[0-9٠-٩]/u', $texts["protocols.{$code}.steps.{$step}"], "{$locale} {$code} {$step} fixes a setting");
                }
            }

            $this->assertStringContainsString('40', $texts['protocols.stability_40c_4w.steps.store']);
        }
    }

    public function test_no_stability_property_has_an_acceptance_limit_and_none_is_required_for_approval(): void
    {
        foreach (['centrifuge_separation', 'thermal_cycle_separation', 'stability_4w'] as $property) {
            $this->assertSame(['min' => null, 'max' => null], config("lab.acceptance_limits.{$property}"));
            $this->assertNotContains($property, config('lab.required_measurements.default'));
        }
    }

    public function test_a_trial_records_each_stability_result_through_its_protocol_as_a_local_value(): void
    {
        $this->seed(LabProtocolSeeder::class);
        $service = app(LabTrialService::class);
        $trial = $service->start($this->versionWithLines(), ['batch_size' => '500', 'batch_unit' => 'g', 'trial_date' => '2026-11-20', 'operator' => 'Mona']);

        foreach (self::CODES as $code) {
            $protocol = LabProtocol::query()->where('code', $code)->sole();
            $measurement = $service->record($trial, [
                'property' => $protocol->property->value,
                'value' => '0',
                'unit' => $protocol->unit,
                'lab_protocol_id' => $protocol->id,
                'instrument' => 'Lab instrument',
                'measured_at' => '2026-11-20T09:30',
            ]);

            $this->assertSame('0', $measurement->fresh()->value, "{$code}: a real zero separation is a value, not a missing one");
            $this->assertSame($protocol->id, $measurement->fresh()->lab_protocol_id);
        }

        $this->assertSame(3, $trial->measurements()->count());
    }
}
