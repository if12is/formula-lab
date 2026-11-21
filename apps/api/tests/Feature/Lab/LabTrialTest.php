<?php

namespace Tests\Feature\Lab;

use App\Enums\Confidence;
use App\Enums\FormulaStatus;
use App\Enums\LabProperty;
use App\Enums\LabTrialStatus;
use App\Exceptions\Domain\ImmutableRecordException;
use App\Exceptions\Domain\LabTrialException;
use App\Models\AuditLog;
use App\Models\FormulaVersion;
use App\Models\LabMeasurement;
use App\Models\LabProtocol;
use App\Models\LabTrial;
use App\Services\Lab\LabTrialService;
use Database\Seeders\LabProtocolSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsLabFixtures;
use Tests\TestCase;

class LabTrialTest extends TestCase
{
    use BuildsLabFixtures, RefreshDatabase;

    private LabTrialService $service;

    private FormulaVersion $version;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(LabTrialService::class);
        $this->version = $this->versionWithLines(['code' => 'F-0042']);
    }

    /** @return array<string, mixed> */
    private function measurement(array $override = []): array
    {
        return [
            'property' => 'ph',
            'value' => '7.20',
            'unit' => 'pH',
            'method' => 'Bench method',
            'instrument' => 'Meter 1',
            'measured_at' => '2026-11-20T09:30',
            ...$override,
        ];
    }

    private function trial(): LabTrial
    {
        return $this->service->start($this->version, ['batch_size' => '500', 'batch_unit' => 'g', 'trial_date' => '2026-11-20', 'operator' => 'Mona']);
    }

    public function test_a_trial_links_a_version_with_its_batch_and_gets_a_running_code(): void
    {
        $first = $this->trial();
        $second = $this->trial();

        $this->assertSame('F-0042/T1', $first->code);
        $this->assertSame('F-0042/T2', $second->code);
        $this->assertSame($this->version->id, $first->formula_version_id);
        $this->assertSame($this->version->formula->tenant_id, $first->tenant_id);
        $this->assertSame(LabTrialStatus::InProgress, $first->status);
        $this->assertSame('500', $first->batch_size);
        $this->assertSame('g', $first->batch_unit);
        $this->assertSame('Mona', $first->operator);
        $this->assertSame('2026-11-20', $first->trial_date->toDateString());
    }

    public function test_a_measurement_is_a_sourced_local_lab_value_kept_exactly_as_typed(): void
    {
        $measurement = $this->service->record($this->trial(), $this->measurement());

        $fresh = $measurement->fresh();
        $this->assertSame('7.20', $fresh->value);
        $this->assertSame('pH', $fresh->unit);
        $this->assertSame('Bench method', $fresh->method);
        $this->assertSame('Meter 1', $fresh->instrument);
        $this->assertSame(LabProperty::Ph, $fresh->property);
        $this->assertSame(Confidence::L1CoaBatch, $fresh->confidence);
        $this->assertSame('2026-11-20 09:30', $fresh->measured_at->format('Y-m-d H:i'));
    }

    public function test_a_protocol_can_stand_in_for_the_method(): void
    {
        $this->seed(LabProtocolSeeder::class);
        $protocol = LabProtocol::query()->where('code', 'ph')->firstOrFail();

        $measurement = $this->service->record($this->trial(), $this->measurement(['method' => null, 'lab_protocol_id' => $protocol->id]));

        $this->assertSame($protocol->id, $measurement->fresh()->lab_protocol_id);
    }

    public function test_a_blank_value_is_rejected_and_never_stored_as_zero(): void
    {
        $trial = $this->trial();

        foreach (['', '   ', 'abc', '7,2'] as $value) {
            $this->post(route('lab.trials.measurements.store', $trial), $this->measurement(['value' => $value]))->assertSessionHasErrors('value');
        }
        $this->post(route('lab.trials.measurements.store', $trial), array_diff_key($this->measurement(), ['value' => 1]))->assertSessionHasErrors('value');

        $this->assertSame(0, LabMeasurement::query()->count());
    }

    public function test_a_real_zero_reading_is_a_value_not_a_missing_one(): void
    {
        $trial = $this->trial();

        $this->post(route('lab.trials.measurements.store', $trial), $this->measurement(['property' => 'cloud_point', 'value' => '0', 'unit' => '°C']))
            ->assertSessionHasNoErrors();

        $this->assertSame('0', LabMeasurement::query()->sole()->value);
    }

    public function test_an_unmeasured_property_shows_as_missing_never_zero(): void
    {
        $trial = $this->trial();
        $this->service->record($trial, $this->measurement());

        $this->get(route('lab.trials.show', $trial))->assertOk()->assertInertia(function (Assert $page): void {
            $rows = collect($page->toArray()['props']['properties'])->keyBy('property');
            $this->assertSame('7.20', $rows['ph']['latest']['value']);
            $this->assertNull($rows['density']['latest']);
            $this->assertSame([], $rows['density']['earlier']);
        });
    }

    public function test_a_measurement_needs_an_instrument_and_a_protocol_or_a_method(): void
    {
        $trial = $this->trial();
        $url = route('lab.trials.measurements.store', $trial);

        $this->post($url, $this->measurement(['instrument' => '']))->assertSessionHasErrors('instrument');
        $this->post($url, $this->measurement(['method' => '']))->assertSessionHasErrors('method');
        $this->assertSame(0, LabMeasurement::query()->count());
    }

    public function test_the_unit_must_belong_to_the_property(): void
    {
        $trial = $this->trial();

        $this->post(route('lab.trials.measurements.store', $trial), $this->measurement(['unit' => 'mPa·s']))->assertSessionHasErrors('unit');
        $this->assertThrowsException(LabTrialException::class, fn () => $this->service->record($trial, $this->measurement(['unit' => 'mPa·s'])));
        $this->assertSame(0, LabMeasurement::query()->count());
    }

    public function test_a_newer_reading_of_the_same_property_replaces_it_and_the_earlier_one_stays(): void
    {
        $trial = $this->trial();
        $this->service->record($trial, $this->measurement(['value' => '7.0']));
        $this->service->record($trial, $this->measurement(['value' => '7.4']));

        $this->assertSame(2, LabMeasurement::query()->count());
        $this->get(route('lab.trials.show', $trial))->assertInertia(function (Assert $page): void {
            $ph = collect($page->toArray()['props']['properties'])->firstWhere('property', 'ph');
            $this->assertSame('7.4', $ph['latest']['value']);
            $this->assertSame(['7.0'], array_column($ph['earlier'], 'value'));
        });
    }

    public function test_completing_needs_a_measurement_and_closes_the_trial(): void
    {
        $trial = $this->trial();
        $this->assertThrowsException(LabTrialException::class, fn () => $this->service->complete($trial));

        $this->service->record($trial, $this->measurement());
        $done = $this->service->complete($trial);

        $this->assertSame(LabTrialStatus::Completed, $done->status);
        $this->assertNotNull($done->completed_at);
        $this->assertThrowsException(LabTrialException::class, fn () => $this->service->record($trial->fresh(), $this->measurement()));
        $this->assertThrowsException(LabTrialException::class, fn () => $this->service->complete($trial->fresh()));
    }

    public function test_a_completed_trial_is_closed_at_the_database_too(): void
    {
        $trial = $this->trialWith($this->version, [LabProperty::Ph]);

        $this->assertThrowsQuery(fn () => DB::table('lab_trials')->where('id', $trial->id)->update(['notes' => 'changed']));
        $this->assertThrowsQuery(fn () => DB::table('lab_trials')->where('id', $trial->id)->delete());
        $this->assertThrowsQuery(fn () => LabMeasurement::query()->create([
            'lab_trial_id' => $trial->id, 'property' => 'density', 'value' => '1', 'unit' => 'g/mL', 'method' => 'x', 'instrument' => 'y', 'measured_at' => now(),
        ]));
    }

    public function test_measurements_are_append_only_in_the_model_and_the_database(): void
    {
        $measurement = $this->service->record($this->trial(), $this->measurement());

        $this->assertThrowsException(ImmutableRecordException::class, fn () => $measurement->update(['value' => '9']));
        $this->assertThrowsException(ImmutableRecordException::class, fn () => $measurement->delete());
        $this->assertThrowsQuery(fn () => DB::table('lab_measurements')->where('id', $measurement->id)->update(['value' => '9']));
        $this->assertThrowsQuery(fn () => DB::table('lab_measurements')->where('id', $measurement->id)->delete());
        $this->assertThrowsQuery(fn () => DB::statement('truncate lab_measurements'));
    }

    public function test_the_database_refuses_a_measurement_without_a_value_or_without_provenance(): void
    {
        $trial = $this->trial();
        $row = ['lab_trial_id' => $trial->id, 'property' => 'ph', 'value' => '7', 'unit' => 'pH', 'method' => 'm', 'instrument' => 'i', 'measured_at' => now()];

        $this->assertThrowsQuery(fn () => DB::table('lab_measurements')->insert([...$row, 'value' => null]));
        $this->assertThrowsQuery(fn () => DB::table('lab_measurements')->insert([...$row, 'method' => null]));
        $this->assertThrowsQuery(fn () => DB::table('lab_measurements')->insert([...$row, 'instrument' => '  ']));
    }

    public function test_trials_and_measurements_are_audited(): void
    {
        $trial = $this->trial();
        $measurement = $this->service->record($trial, $this->measurement());
        $this->service->complete($trial);

        $this->assertSame(1, AuditLog::query()->where('table_name', 'lab_measurements')->where('record_id', (string) $measurement->id)->where('action', 'create')->count());
        $this->assertSame(1, AuditLog::query()->where('table_name', 'lab_trials')->where('record_id', $trial->id)->where('action', 'create')->count());
        $update = AuditLog::query()->where('table_name', 'lab_trials')->where('record_id', $trial->id)->where('action', 'update')->sole();
        $this->assertSame('in_progress', $update->before['status']);
        $this->assertSame('completed', $update->after['status']);
    }

    public function test_a_trial_of_an_approved_version_is_allowed_and_leaves_the_version_untouched(): void
    {
        $this->version->update(['status' => FormulaStatus::Approved]);
        $before = $this->version->fresh()->toArray();

        $trial = $this->trial();
        $this->service->record($trial, $this->measurement());
        $this->service->complete($trial);

        $this->assertSame($before, $this->version->fresh()->toArray());
        $this->assertTrue($this->version->fresh()->verifyHash());
    }

    public function test_the_entry_form_opens_a_trial_and_lands_on_it(): void
    {
        $response = $this->post(route('lab.trials.store'), [
            'formula_version_id' => $this->version->id,
            'batch_size' => '250.5',
            'batch_unit' => 'kg',
            'trial_date' => '2026-11-21',
            'operator' => '  Hana  ',
            'notes' => 'First pilot',
        ]);

        $trial = LabTrial::query()->sole();
        $response->assertRedirect(route('lab.trials.show', $trial));
        $this->assertSame('Hana', $trial->operator);
        $this->assertSame('250.5', $trial->batch_size);
    }

    public function test_the_entry_form_validates_the_batch_and_the_version(): void
    {
        $valid = ['formula_version_id' => $this->version->id, 'batch_size' => '100', 'batch_unit' => 'g', 'trial_date' => '2026-11-21', 'operator' => 'Hana'];

        $this->post(route('lab.trials.store'), [...$valid, 'batch_size' => '0'])->assertSessionHasErrors('batch_size');
        $this->post(route('lab.trials.store'), [...$valid, 'batch_size' => '-5'])->assertSessionHasErrors('batch_size');
        $this->post(route('lab.trials.store'), [...$valid, 'batch_size' => 'ten'])->assertSessionHasErrors('batch_size');
        $this->post(route('lab.trials.store'), [...$valid, 'batch_unit' => 'barrel'])->assertSessionHasErrors('batch_unit');
        $this->post(route('lab.trials.store'), [...$valid, 'operator' => ' '])->assertSessionHasErrors('operator');
        $this->post(route('lab.trials.store'), [...$valid, 'trial_date' => '21/11/2026'])->assertSessionHasErrors('trial_date');
        $this->post(route('lab.trials.store'), [...$valid, 'formula_version_id' => '3f0c6b0e-0000-4000-8000-000000000000'])->assertSessionHasErrors('formula_version_id');
        $this->assertSame(0, LabTrial::query()->count());
    }

    public function test_the_create_page_preselects_the_version_from_the_editor_link(): void
    {
        $this->get(route('lab.trials.create', ['formula_version' => $this->version->id]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Lab/Trials/Create')
            ->where('version.value', $this->version->id)
            ->where('version.label', 'F-0042 — '.$this->version->formula->name.' · v1')
            ->has('batchUnits', 4));

        $this->get(route('lab.trials.create'))->assertInertia(fn (Assert $page) => $page->where('version', null));
        $this->get(route('lab.trials.create', ['formula_version' => 'not-a-uuid']))->assertOk()->assertInertia(fn (Assert $page) => $page->where('version', null));
    }

    public function test_the_version_search_finds_versions_by_formula_code_or_name(): void
    {
        $this->versionWithLines(['code' => 'F-0099', 'name' => 'Other thing']);

        $this->getJson(route('lab.formula-versions.search', ['q' => 'F-0042']))->assertOk()->assertJsonCount(1)->assertJsonPath('0.value', $this->version->id);
        $this->getJson(route('lab.formula-versions.search', ['q' => 'other']))->assertOk()->assertJsonCount(1);
        $this->getJson(route('lab.formula-versions.search', ['q' => 'zzz']))->assertOk()->assertJsonCount(0);
        $this->getJson(route('lab.formula-versions.search'))->assertOk()->assertJsonCount(2);
    }

    public function test_completing_through_the_page_flashes_and_a_second_try_is_an_error(): void
    {
        $trial = $this->trial();
        $this->service->record($trial, $this->measurement());

        $this->post(route('lab.trials.complete', $trial))->assertSessionHas('success', __('lab.trials.completed'));
        $this->post(route('lab.trials.complete', $trial))->assertSessionHas('error');
    }

    private function assertThrowsQuery(callable $callback): void
    {
        $this->assertThrowsException(QueryException::class, function () use ($callback): void {
            DB::transaction($callback);
        });
    }
}
