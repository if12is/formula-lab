<?php

namespace Tests\Feature\Lab;

use App\Enums\Confidence;
use App\Enums\LabProperty;
use App\Enums\TestedValueKind;
use App\Exceptions\Domain\ImmutableRecordException;
use App\Models\FormulaVersion;
use App\Models\LabMeasurement;
use App\Models\LabTrial;
use App\Models\PropertyValue;
use App\Models\Source;
use App\Models\TestedValueMark;
use App\Services\Formula\FormulaWorkflowService;
use App\Services\Lab\LabTrialService;
use App\Services\Lab\TestedValueService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsLabFixtures;
use Tests\TestCase;

/** O-07: a stability result that stands on an approved version marks the doses and required HLBs it used as "tested on formula X". */
class TestedValueMarkTest extends TestCase
{
    use BuildsLabFixtures, RefreshDatabase;

    private FormulaVersion $version;

    private string $oilId;

    private string $waterId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->version = $this->versionWithLines(['code' => 'F-0500']);
        [$oil, $water] = $this->version->lines;
        $this->oilId = $oil->trade_material_id;
        $this->waterId = $water->trade_material_id;
        $source = Source::factory()->create();
        // Two registered required HLBs for the oil: the engine picks the better-sourced one (L1 beats L2).
        PropertyValue::factory()->create(['entity_type' => 'trade_material', 'entity_id' => $this->oilId, 'property' => 'required_hlb', 'unit' => 'HLB', 'value' => '10', 'confidence' => Confidence::L2SupplierTds, 'source_id' => Source::factory()]);
        PropertyValue::factory()->create(['entity_type' => 'trade_material', 'entity_id' => $this->oilId, 'property' => 'required_hlb', 'unit' => 'HLB', 'value' => '11', 'confidence' => Confidence::L1CoaBatch, 'source_id' => $source->id]);
    }

    /** @return list<LabProperty> */
    private function everything(): array
    {
        return [...$this->requiredProperties(), LabProperty::Stability4w];
    }

    private function approve(): void
    {
        $workflow = app(FormulaWorkflowService::class);
        $workflow->sendToLab($this->version);
        $workflow->approve($this->version->fresh());
    }

    public function test_approving_a_version_with_a_completed_stability_trial_marks_its_doses_and_required_hlbs(): void
    {
        $trial = $this->trialWith($this->version, $this->everything());

        $this->approve();

        $marks = TestedValueMark::query()->orderBy('kind')->get();
        $this->assertCount(3, $marks);
        $dose = $marks->first(fn (TestedValueMark $m): bool => $m->kind === TestedValueKind::Dose && $m->trade_material_id === $this->oilId);
        $qs = $marks->first(fn (TestedValueMark $m): bool => $m->kind === TestedValueKind::Dose && $m->trade_material_id === $this->waterId);
        $hlb = $marks->first(fn (TestedValueMark $m): bool => $m->kind === TestedValueKind::RequiredHlb);
        $this->assertSame('10', $dose->value);
        $this->assertSame('90', $qs->value, 'the q.s. line is balanced by the engine: 100 - 10');
        $this->assertSame('11', $hlb->value);
        $this->assertSame($this->oilId, $hlb->trade_material_id);
        $this->assertNotNull($hlb->source_id);
        $this->assertNull($dose->source_id);
        $this->assertSame(Confidence::L1CoaBatch, $hlb->confidence);
        $this->assertSame($trial->id, $hlb->lab_trial_id);
        $this->assertSame($this->version->id, $hlb->formula_version_id);
        $this->assertSame($this->version->formula->tenant_id, $hlb->tenant_id);
        $this->assertSame('2026-11-20', $hlb->tested_on->toDateString());
        $this->assertSame(
            LabMeasurement::query()->where('lab_trial_id', $trial->id)->where('property', LabProperty::Stability4w)->value('id'),
            $hlb->lab_measurement_id,
        );
    }

    public function test_completing_a_stability_trial_on_an_already_approved_version_marks_too(): void
    {
        $this->trialWith($this->version, $this->requiredProperties());
        $this->approve();
        $this->assertSame(0, TestedValueMark::query()->count(), 'no stability result yet');

        $service = app(LabTrialService::class);
        $trial = $service->start($this->version->fresh(), ['batch_size' => '500', 'batch_unit' => 'g', 'trial_date' => '2026-11-22', 'operator' => 'Mona']);
        $service->record($trial, ['property' => 'stability_4w', 'value' => '98', 'unit' => '%', 'method' => '4 weeks at 40 C', 'instrument' => 'Oven', 'measured_at' => '2026-11-22T10:00']);
        $service->complete($trial);

        $this->assertSame(3, TestedValueMark::query()->where('lab_trial_id', $trial->id)->count());
    }

    public function test_nothing_is_marked_without_every_condition(): void
    {
        $service = app(TestedValueService::class);

        // Not approved: a completed stability trial of a draft version.
        $draftTrial = $this->trialWith($this->version, $this->everything());
        $this->assertSame(0, $service->markTrial($draftTrial));

        // Approved, but the trial is still open.
        $this->approve();
        $open = $this->trialWith($this->version->fresh(), [LabProperty::Stability4w], false);
        $this->assertSame(0, $service->markTrial($open));

        // Approved and completed, but no stability result.
        $noStability = $this->trialWith($this->version->fresh(), [LabProperty::Ph]);
        $this->assertSame(0, $service->markTrial($noStability));
        $this->assertSame(0, TestedValueMark::query()->where('lab_trial_id', '!=', $draftTrial->id)->count());
    }

    public function test_marking_again_adds_nothing_twice(): void
    {
        $this->trialWith($this->version, $this->everything());
        $this->approve();
        $service = app(TestedValueService::class);

        $this->assertSame(0, $service->markVersion($this->version->fresh()));
        $this->assertSame(3, TestedValueMark::query()->count());
    }

    public function test_a_material_without_a_required_hlb_only_gets_its_dose(): void
    {
        PropertyValue::query()->where('property', 'required_hlb')->delete();
        $this->trialWith($this->version, $this->everything());

        $this->approve();

        $this->assertSame(2, TestedValueMark::query()->count());
        $this->assertSame(0, TestedValueMark::query()->where('kind', TestedValueKind::RequiredHlb)->count());
    }

    public function test_a_record_is_append_only(): void
    {
        $mark = TestedValueMark::factory()->create();

        $this->assertThrowsException(ImmutableRecordException::class, fn () => $mark->update(['value' => '13']));
        $this->expectException(QueryException::class);
        TestedValueMark::query()->toBase()->where('id', $mark->id)->update(['value' => '14']);
    }

    public function test_the_database_allows_one_record_per_trial_material_and_kind(): void
    {
        $mark = TestedValueMark::factory()->create();
        $this->expectException(QueryException::class);

        TestedValueMark::factory()->create([
            'lab_trial_id' => $mark->lab_trial_id,
            'lab_measurement_id' => $mark->lab_measurement_id,
            'trade_material_id' => $mark->trade_material_id,
            'kind' => $mark->kind,
        ]);
    }

    public function test_the_engine_shaped_records_of_a_material_newest_first(): void
    {
        $older = TestedValueMark::factory()->create(['trade_material_id' => $this->oilId, 'kind' => TestedValueKind::RequiredHlb, 'value' => '11.0', 'tested_on' => '2026-11-01']);
        $newer = TestedValueMark::factory()->create(['trade_material_id' => $this->oilId, 'kind' => TestedValueKind::RequiredHlb, 'value' => '11', 'tested_on' => '2026-11-15']);

        $records = app(TestedValueService::class)->forMaterials([$this->oilId, $this->waterId]);

        $this->assertSame([$this->oilId], array_keys($records));
        $this->assertSame([$newer->lab_trial_id, $older->lab_trial_id], array_column($records[$this->oilId], 'trialId'));
        $this->assertSame('11', $records[$this->oilId][1]['value'], 'normalised: 11.0 is 11');
        $this->assertSame('required_hlb', $records[$this->oilId][0]['kind']);
        $this->assertSame('L1_coa_batch', $records[$this->oilId][0]['confidence']);
        $this->assertSame([], app(TestedValueService::class)->forMaterials([]));
    }

    public function test_the_calculator_material_search_carries_the_records(): void
    {
        $this->trialWith($this->version, $this->everything());
        $this->approve();
        $name = PropertyValue::query()->where('entity_id', $this->oilId)->exists() ? $this->version->lines->first()->tradeMaterial->trade_name : '';

        $this->getJson('/calculators/materials?q='.urlencode($name))
            ->assertOk()
            ->assertJsonPath('0.testedMarks.0.kind', 'dose')
            ->assertJsonPath('0.testedMarks.0.formulaCode', 'F-0500')
            ->assertJsonPath('0.testedMarks.0.version', 1)
            ->assertJsonPath('0.testedMarks.0.confidence', 'L1_coa_batch');
    }

    public function test_the_trial_page_lists_the_tested_values_of_its_version(): void
    {
        $trial = $this->trialWith($this->version, $this->everything());
        $this->approve();

        $this->get(route('lab.trials.show', $trial))->assertInertia(fn (Assert $page) => $page
            ->has('testedValues', 3)
            ->where('testedValues.0.kind', 'dose')
            ->where('testedValues.0.trial', $trial->code));
    }

    public function test_a_stability_trial_of_another_version_marks_nothing_for_this_one(): void
    {
        $other = $this->versionWithLines(['code' => 'F-0600']);
        $this->trialWith($other, $this->everything());
        $this->trialWith($this->version, $this->requiredProperties());

        $this->approve();

        $this->assertSame(0, TestedValueMark::query()->count(), 'this version has no stability result of its own, and the other is not approved');
        $this->assertSame(1, LabTrial::query()->where('formula_version_id', $this->version->id)->count());
    }
}
