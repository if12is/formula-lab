<?php

namespace Tests\Feature\Lab;

use App\Enums\LabProperty;
use App\Models\LabProtocol;
use Database\Seeders\LabProtocolSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LabProtocolTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_seeder_loads_the_protocol_templates_from_the_configuration_and_is_idempotent(): void
    {
        $this->seed(LabProtocolSeeder::class);
        $this->seed(LabProtocolSeeder::class);

        $this->assertSame(['ph', 'density_pycnometer', 'viscosity_brookfield'], LabProtocol::query()->orderBy('position')->pluck('code')->all());

        $density = LabProtocol::query()->where('code', 'density_pycnometer')->sole();
        $this->assertSame(LabProperty::Density, $density->property);
        $this->assertSame('g/mL', $density->unit);
        $this->assertSame('pycnometer', $density->instrument);
        $this->assertSame(config('lab.protocols.density_pycnometer.steps'), $density->steps);
    }

    public function test_every_template_property_unit_instrument_and_step_is_known_and_worded_in_both_languages(): void
    {
        foreach (['ar', 'en'] as $locale) {
            $texts = Arr::dot(require lang_path("{$locale}/lab.php"));

            foreach (config('lab.protocols') as $code => $protocol) {
                $property = LabProperty::from($protocol['property']);
                $this->assertContains($protocol['unit'], $property->units(), "{$code} unit");
                $this->assertNotSame('', trim($texts["instruments.{$protocol['instrument']}"] ?? ''), "{$locale} instrument of {$code}");
                $this->assertNotSame('', trim($texts["protocols.{$code}.name"] ?? ''));
                $this->assertNotSame('', trim($texts["protocols.{$code}.description"] ?? ''));
                $this->assertSame($code, $property->protocolCode() === $code ? $code : $property->protocolCode(), "{$property->value} points at {$code}");

                foreach ($protocol['steps'] as $step) {
                    $this->assertNotSame('', trim($texts["protocols.{$code}.steps.{$step}"] ?? ''), "{$locale} step {$step} of {$code}");
                }
            }
        }
    }

    public function test_no_acceptance_limit_is_invented_and_every_limit_is_unset_until_the_owner_supplies_it(): void
    {
        $this->assertEqualsCanonicalizing(LabProperty::values(), array_keys(config('lab.acceptance_limits')));

        foreach (config('lab.acceptance_limits') as $property => $limits) {
            $this->assertSame(['min' => null, 'max' => null], $limits, "{$property} has a limit nobody approved");
        }
    }

    public function test_the_required_measurements_are_known_properties(): void
    {
        $required = [...config('lab.required_measurements.default'), ...Arr::flatten(config('lab.required_measurements.by_product_type'))];

        foreach ($required as $key) {
            $this->assertNotNull(LabProperty::tryFrom($key), $key);
        }
    }

    public function test_the_list_shows_the_protocols_and_filters_by_property(): void
    {
        $this->seed(LabProtocolSeeder::class);

        $this->get('/lab/protocols')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Lab/Protocols/Index')
            ->where('list.meta.total', 3)
            ->where('list.rows.0.code', 'ph')
            ->where('list.rows.0.name', __('lab.protocols.ph.name'))
            ->where('list.rows.0.steps', 4)
            ->where('list.schema.defaults.sort', 'position'));

        $this->get('/lab/protocols?property=viscosity')->assertInertia(fn (Assert $page) => $page->where('list.meta.total', 1)->where('list.rows.0.code', 'viscosity_brookfield'));
        $this->get('/lab/protocols?search=pycno')->assertInertia(fn (Assert $page) => $page->where('list.meta.total', 1));
    }

    public function test_a_protocol_page_lists_its_steps_instrument_and_no_limit(): void
    {
        $this->seed(LabProtocolSeeder::class);
        $protocol = LabProtocol::query()->where('code', 'viscosity_brookfield')->sole();

        $this->get(route('lab.protocols.show', $protocol))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Lab/Protocols/Show')
            ->where('protocol.instrument', __('lab.instruments.brookfield_viscometer'))
            ->where('protocol.unit', 'mPa·s')
            ->where('protocol.steps.0', __('lab.protocols.viscosity_brookfield.steps.prepare_sample'))
            ->has('protocol.steps', 4)
            ->where('protocol.limits', ['min' => null, 'max' => null]));
    }
}
