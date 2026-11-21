<?php

namespace Tests\Feature\Lab;

use App\Enums\FormulaStatus;
use App\Enums\LabProperty;
use App\Models\Formula;
use App\Models\FormulaVersion;
use App\Models\LabTrial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsLabFixtures;
use Tests\TestCase;

class LabTrialListTest extends TestCase
{
    use BuildsLabFixtures, RefreshDatabase;

    private Formula $shampoo;

    private Formula $lotion;

    protected function setUp(): void
    {
        parent::setUp();

        $a = $this->versionWithLines(['code' => 'F-0001', 'name' => 'Daily shampoo']);
        $b = $this->versionWithLines(['code' => 'F-0002', 'name' => 'Body lotion']);
        $this->shampoo = $a->formula;
        $this->lotion = $b->formula;

        $this->trialWith($a, [LabProperty::Ph, LabProperty::Density], true, ['code' => 'F-0001/T1', 'trial_date' => '2026-02-01', 'operator' => 'Mona']);
        $this->trialWith($a, [], false, ['code' => 'F-0001/T2', 'trial_date' => '2026-03-01', 'operator' => 'Hana']);
        $this->trialWith($b, [LabProperty::Ph], true, ['code' => 'F-0002/T1', 'trial_date' => '2026-02-15', 'operator' => 'Mona']);
        $b->update(['status' => FormulaStatus::LabTesting]);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<string>
     */
    private function codes(array $query = []): array
    {
        $codes = [];
        $this->get(route('lab.trials.index', $query))->assertOk()->assertInertia(function (Assert $page) use (&$codes): void {
            $codes = array_column($page->toArray()['props']['list']['rows'], 'code');
        });

        return $codes;
    }

    public function test_it_lists_trials_newest_date_first_with_their_formula_and_version(): void
    {
        $this->get('/lab/trials')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Lab/Trials/Index')
            ->where('list.meta.total', 3)
            ->where('list.rows.0.code', 'F-0001/T2')
            ->where('list.rows.0.formula_code', 'F-0001')
            ->where('list.rows.0.formula_name', 'Daily shampoo')
            ->where('list.rows.0.version', 1)
            ->where('list.rows.0.status', 'in_progress')
            ->where('list.rows.0.measurements', 0)
            ->where('list.rows.2.measurements', 2)
            ->where('list.schema.defaults.sort', 'date')
            ->where('list.schema.defaults.dir', 'desc'));
    }

    public function test_the_lab_home_redirects_to_the_trials(): void
    {
        $this->get('/lab')->assertRedirect('/lab/trials');
    }

    public function test_it_searches_trial_code_formula_and_operator(): void
    {
        $this->assertSame(['F-0002/T1'], $this->codes(['search' => 'lotion']));
        $this->assertSame(['F-0001/T2'], $this->codes(['search' => 'Hana']));
        $this->assertEqualsCanonicalizing(['F-0001/T1', 'F-0002/T1'], $this->codes(['search' => 'Mona']));
        $this->assertSame([], $this->codes(['search' => 'nothing']));
    }

    public function test_it_filters_by_formula_status_version_status_and_date(): void
    {
        $this->assertEqualsCanonicalizing(['F-0001/T1', 'F-0001/T2'], $this->codes(['formula_id' => $this->shampoo->id]));
        $this->assertSame(['F-0001/T2'], $this->codes(['status' => 'in_progress']));
        $this->assertSame(['F-0002/T1'], $this->codes(['version_status' => 'lab_testing']));
        $this->assertSame(['F-0002/T1'], $this->codes(['trial_date_from' => '2026-02-10', 'trial_date_to' => '2026-02-20']));
        $this->assertCount(3, $this->codes(['status' => 'bogus']));
    }

    public function test_it_sorts_and_ignores_unknown_sorts(): void
    {
        $this->assertSame(['F-0001/T1', 'F-0001/T2', 'F-0002/T1'], $this->codes(['sort' => 'code', 'dir' => 'asc']));
        $this->assertSame(['F-0002/T1', 'F-0001/T2', 'F-0001/T1'], $this->codes(['sort' => 'code', 'dir' => 'desc']));
        $this->assertSame(['F-0001/T2', 'F-0002/T1', 'F-0001/T1'], $this->codes(['sort' => 'nope']));
    }

    public function test_the_formula_filter_options_list_every_formula(): void
    {
        $this->getJson(route('lab.formulas.options'))->assertOk()->assertExactJson([
            ['value' => $this->shampoo->id, 'label' => 'F-0001 — Daily shampoo'],
            ['value' => $this->lotion->id, 'label' => 'F-0002 — Body lotion'],
        ]);
    }

    public function test_the_detail_page_carries_the_required_flags_coverage_and_limits(): void
    {
        $trial = LabTrial::query()->where('code', 'F-0001/T1')->firstOrFail();

        $this->get(route('lab.trials.show', $trial))->assertOk()->assertInertia(function (Assert $page): void {
            $props = $page->toArray()['props'];
            $rows = collect($props['properties'])->keyBy('property');

            $this->assertTrue($rows['ph']['required']);
            $this->assertFalse($rows['foam']['required']);
            $this->assertSame(['min' => null, 'max' => null], $rows['ph']['limits']);
            $this->assertSame(['viscosity'], $props['coverage']['missing']);
            $this->assertFalse($props['trial']['open']);
            $this->assertSame(FormulaVersion::query()->count(), 2);
        });
    }
}
