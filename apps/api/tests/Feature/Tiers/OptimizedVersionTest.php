<?php

namespace Tests\Feature\Tiers;

use App\Actions\Tenancy\EnsureDefaultTenantAction;
use App\Enums\FormulaStatus;
use App\Enums\ProductTier;
use App\Exceptions\Domain\FormulaWorkflowException;
use App\Exceptions\Domain\ImmutableRecordException;
use App\Models\Formula;
use App\Models\FormulaVersion;
use App\Models\FormulaVersionProvenance;
use App\Models\TierProfile;
use App\Services\Formula\FormulaWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsTierMaterials;
use Tests\TestCase;

/**
 * J-09: an optimizer answer is saved as a draft version with its provenance, and can only be approved through the lab workflow.
 * Same hand case as TierSolveTest: 80 % water + 20 % surfactant = 20.8 EGP/kg for active matter >= 10 %.
 */
class OptimizedVersionTest extends TestCase
{
    use BuildsTierMaterials, RefreshDatabase;

    private Formula $formula;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tierMaterials();
        $this->formula = Formula::factory()->create(['tenant_id' => app(EnsureDefaultTenantAction::class)()->getKey(), 'product_type' => 'shampoo', 'tier' => ProductTier::Economy]);
    }

    private function profile(string $activeMin = '10'): TierProfile
    {
        return TierProfile::factory()->create([
            'tenant_id' => $this->formula->tenant_id,
            'product_type' => 'shampoo',
            'tier' => ProductTier::Economy,
            'source_id' => $this->source->id,
            'profile' => [
                'targetRetailPrice' => ['per' => 'kg', 'value' => null],
                'constraints' => [
                    'totalActiveMinPct' => $activeMin, 'totalActiveMaxPct' => null, 'maxCostEGPperKg' => null,
                    'functionBounds' => [], 'requiredMaterials' => [], 'forbiddenMaterials' => [], 'materialBounds' => [], 'measuredRequirements' => [],
                ],
            ],
        ]);
    }

    private function save(): TestResponse
    {
        return $this->postJson('/tiers/versions', ['formula_id' => $this->formula->id, 'tier' => 'economy', 'qs_material_id' => $this->water->id]);
    }

    public function test_an_optimal_answer_is_saved_as_a_draft_version_with_the_optimizer_lines(): void
    {
        $profile = $this->profile();

        $this->save()->assertCreated()->assertJsonPath('status', 'draft')->assertJsonPath('number', 1);

        $version = FormulaVersion::query()->where('formula_id', $this->formula->id)->firstOrFail();
        $this->assertSame(FormulaStatus::Draft, $version->status);
        $this->assertTrue($version->verifyHash());
        $this->assertEqualsCanonicalizing(
            [[$this->water->id, '80'], [$this->surfactant->id, '20']],
            $version->lines->map(fn ($line): array => [$line->trade_material_id, rtrim(rtrim((string) $line->percent_ww, '0'), '.')])->all(),
        );

        $provenance = $version->provenance;
        $this->assertSame('optimizer', $provenance->origin->value);
        $this->assertSame($profile->id, $provenance->tier_profile_id);
        $this->assertSame('economy', $provenance->tier);
        $this->assertSame('20.8', $provenance->cost_per_kg);
        $this->assertSame($this->water->id, $provenance->optimizer_inputs['qsMaterialId']);
        $this->assertSame('10', $provenance->optimizer_inputs['profile']['constraints']['totalActiveMinPct']);
        $this->assertNotSame('', $provenance->input_hash);
        $this->assertNotSame('', $provenance->solver);
        $this->assertNotSame('', $provenance->optimizer_version);
    }

    public function test_identical_inputs_give_identical_version_hashes_and_input_hashes(): void
    {
        $this->profile();

        $first = $this->save()->assertCreated()->json();
        $second = $this->save()->assertCreated()->json();

        $this->assertSame(1, $first['number']);
        $this->assertSame(2, $second['number']);
        $this->assertSame($first['hash'], $second['hash']);
        $hashes = FormulaVersionProvenance::query()->pluck('input_hash')->unique();
        $this->assertCount(1, $hashes);
    }

    public function test_different_inputs_give_a_different_version_hash(): void
    {
        $this->profile('10');
        $first = $this->save()->assertCreated()->json();
        TierProfile::query()->update(['profile' => [
            'targetRetailPrice' => ['per' => 'kg', 'value' => null],
            'constraints' => [
                'totalActiveMinPct' => '20', 'totalActiveMaxPct' => null, 'maxCostEGPperKg' => null,
                'functionBounds' => [], 'requiredMaterials' => [], 'forbiddenMaterials' => [], 'materialBounds' => [], 'measuredRequirements' => [],
            ],
        ]]);
        $second = $this->save()->assertCreated()->json();

        $this->assertNotSame($first['hash'], $second['hash']);
        $this->assertCount(2, FormulaVersionProvenance::query()->pluck('input_hash')->unique());
    }

    public function test_without_a_saved_profile_nothing_is_saved(): void
    {
        $this->save()->assertStatus(422)->assertJsonValidationErrors('formula_id');

        $this->assertSame(0, FormulaVersion::query()->count());
        $this->assertSame(0, FormulaVersionProvenance::query()->count());
    }

    public function test_numbers_sent_by_the_browser_are_ignored(): void
    {
        $this->profile();

        $this->postJson('/tiers/versions', ['formula_id' => $this->formula->id, 'tier' => 'economy', 'qs_material_id' => $this->water->id, 'formula' => [['materialId' => $this->surfactant->id, 'percentWW' => '100']]])->assertCreated();

        $version = FormulaVersion::query()->firstOrFail();
        $this->assertCount(2, $version->lines);
    }

    public function test_an_optimized_version_cannot_be_approved_straight_from_draft(): void
    {
        $this->profile();
        $this->save()->assertCreated();
        $version = FormulaVersion::query()->firstOrFail();

        $this->expectException(FormulaWorkflowException::class);
        app(FormulaWorkflowService::class)->approve($version);
    }

    public function test_an_optimized_version_in_the_lab_cannot_be_approved_without_a_completed_trial(): void
    {
        $this->profile();
        $this->save()->assertCreated();
        $version = FormulaVersion::query()->firstOrFail();
        $workflow = app(FormulaWorkflowService::class);
        $workflow->sendToLab($version);

        try {
            $workflow->approve($version->fresh());
            $this->fail('Approval without a trial must be refused.');
        } catch (FormulaWorkflowException $exception) {
            $this->assertSame(__('exceptions.workflow_no_completed_trial'), $exception->getMessage());
        }

        $this->post("/formulas/{$this->formula->id}/versions/{$version->version}/approve")->assertSessionHas('error');
        $this->assertSame(FormulaStatus::LabTesting, $version->fresh()->status);
    }

    public function test_the_provenance_row_never_changes(): void
    {
        $this->profile();
        $this->save()->assertCreated();
        $row = FormulaVersionProvenance::query()->firstOrFail();

        $this->expectException(ImmutableRecordException::class);
        $row->update(['cost_per_kg' => '1']);
    }

    public function test_the_workflow_panel_shows_where_the_version_came_from(): void
    {
        $this->profile();
        $this->save()->assertCreated();

        $panel = app(FormulaWorkflowService::class)->panel(FormulaVersion::query()->firstOrFail());

        $this->assertSame('optimizer', $panel['provenance']['origin']);
        $this->assertSame('economy', $panel['provenance']['tier']);
        $this->assertTrue($panel['provenance']['has_profile']);
    }

    public function test_a_typed_version_has_no_provenance(): void
    {
        $version = FormulaVersion::factory()->create(['formula_id' => $this->formula->id]);

        $this->assertNull(app(FormulaWorkflowService::class)->panel($version)['provenance']);
    }
}
