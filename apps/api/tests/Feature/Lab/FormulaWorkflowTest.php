<?php

namespace Tests\Feature\Lab;

use App\Enums\FormulaStatus;
use App\Enums\LabProperty;
use App\Exceptions\Domain\FormulaWorkflowException;
use App\Exceptions\Domain\ImmutableRecordException;
use App\Models\FormulaLine;
use App\Models\FormulaVersion;
use App\Models\FormulaVersionTransition;
use App\Models\User;
use App\Services\Formula\FormulaWorkflowService;
use App\Services\Lab\LabCoverageService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsLabFixtures;
use Tests\TestCase;

class FormulaWorkflowTest extends TestCase
{
    use BuildsLabFixtures, RefreshDatabase;

    private FormulaWorkflowService $workflow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workflow = app(FormulaWorkflowService::class);
    }

    private function inLab(?FormulaVersion $version = null): FormulaVersion
    {
        $version ??= $this->versionWithLines();
        $this->workflow->sendToLab($version);

        return $version->fresh();
    }

    /** @return array<string, array{0: FormulaStatus, 1: FormulaStatus, 2: bool}> */
    public static function moves(): array
    {
        $cases = [];
        $allowed = [['draft', 'lab_testing'], ['lab_testing', 'approved'], ['lab_testing', 'rejected']];

        foreach (FormulaStatus::cases() as $from) {
            foreach (FormulaStatus::cases() as $to) {
                $cases["{$from->value} to {$to->value}"] = [$from, $to, in_array([$from->value, $to->value], $allowed, true)];
            }
        }

        return $cases;
    }

    #[DataProvider('moves')]
    public function test_only_the_workflow_moves_are_allowed(FormulaStatus $from, FormulaStatus $to, bool $allowed): void
    {
        $this->assertSame($allowed, $from->canMoveTo($to));
    }

    public function test_approved_and_rejected_are_final(): void
    {
        $this->assertTrue(FormulaStatus::Approved->isFinal());
        $this->assertTrue(FormulaStatus::Rejected->isFinal());
        $this->assertFalse(FormulaStatus::Draft->isFinal());
        $this->assertFalse(FormulaStatus::LabTesting->isFinal());
    }

    public function test_sending_to_the_lab_signs_the_move_with_the_version_hash(): void
    {
        $user = User::factory()->create();
        $version = $this->versionWithLines();

        $signature = $this->workflow->sendToLab($version, $user, 'Ready for the bench');

        $this->assertSame(FormulaStatus::LabTesting, $version->fresh()->status);
        $this->assertSame(FormulaStatus::Draft, $signature->from_status);
        $this->assertSame(FormulaStatus::LabTesting, $signature->to_status);
        $this->assertSame($user->id, $signature->signed_by);
        $this->assertSame('Ready for the bench', $signature->comment);
        $this->assertSame($version->hash, $signature->version_hash);
        $this->assertNotNull($signature->signed_at);
    }

    public function test_a_draft_cannot_jump_to_approved_or_rejected(): void
    {
        $version = $this->versionWithLines();
        $this->trialWith($version, $this->requiredProperties());

        $this->assertThrowsException(FormulaWorkflowException::class, fn () => $this->workflow->approve($version));
        $this->assertThrowsException(FormulaWorkflowException::class, fn () => $this->workflow->reject($version, 'No'));
        $this->assertSame(FormulaStatus::Draft, $version->fresh()->status);
        $this->assertSame(0, FormulaVersionTransition::query()->count());
    }

    public function test_approval_is_blocked_without_a_trial(): void
    {
        $version = $this->inLab();

        $this->assertThrowsException(FormulaWorkflowException::class, fn () => $this->workflow->approve($version), __('exceptions.workflow_no_completed_trial'));
        $this->assertSame(FormulaStatus::LabTesting, $version->fresh()->status);
    }

    public function test_approval_is_blocked_while_the_only_trial_is_not_completed(): void
    {
        $version = $this->inLab();
        $this->trialWith($version, $this->requiredProperties(), completed: false);

        $this->assertThrowsException(FormulaWorkflowException::class, fn () => $this->workflow->approve($version));
    }

    public function test_approval_is_blocked_when_a_required_measurement_is_missing_and_names_it(): void
    {
        $version = $this->inLab();
        $this->trialWith($version, [LabProperty::Ph, LabProperty::Density]);

        try {
            $this->workflow->approve($version);
            $this->fail('Expected FormulaWorkflowException.');
        } catch (FormulaWorkflowException $exception) {
            $this->assertSame(['viscosity'], $exception->missing);
            $this->assertStringContainsString(LabProperty::Viscosity->label(), $exception->getMessage());
        }

        $this->assertSame(FormulaStatus::LabTesting, $version->fresh()->status);
    }

    public function test_the_required_measurements_must_come_from_one_trial_not_a_mix(): void
    {
        $version = $this->inLab();
        $this->trialWith($version, [LabProperty::Ph, LabProperty::Density]);
        $this->trialWith($version, [LabProperty::Viscosity]);

        $this->assertThrowsException(FormulaWorkflowException::class, fn () => $this->workflow->approve($version));
    }

    public function test_an_unmeasured_property_is_missing_even_when_other_values_exist(): void
    {
        $version = $this->inLab();
        $this->trialWith($version, [LabProperty::CloudPoint, LabProperty::Foam]);

        $coverage = app(LabCoverageService::class)->forVersion($version);

        $this->assertFalse($coverage->isCovered());
        $this->assertSame(['ph', 'density', 'viscosity'], $coverage->missing);
    }

    public function test_approval_with_a_complete_trial_signs_name_time_comment_hash_and_basis(): void
    {
        $user = User::factory()->create();
        $version = $this->inLab();
        $trial = $this->trialWith($version, [...$this->requiredProperties(), LabProperty::Foam]);

        $signature = $this->workflow->approve($version, $user, 'Passed the bench');

        $this->assertSame(FormulaStatus::Approved, $version->fresh()->status);
        $this->assertSame(FormulaStatus::Approved, $signature->to_status);
        $this->assertSame($user->id, $signature->signed_by);
        $this->assertSame('Passed the bench', $signature->comment);
        $this->assertSame($version->hash, $signature->version_hash);
        $this->assertTrue($version->fresh()->verifyHash());
        $this->assertSame('approve-formula-version:AFV-06', $signature->basis['decision_rule']);
        $this->assertSame($trial->id, $signature->basis['lab']['reference_trial_id']);
        $this->assertSame([], $signature->basis['lab']['missing']);
        $this->assertSame(['ph', 'density', 'viscosity'], $signature->basis['lab']['required']);
    }

    public function test_the_required_set_can_differ_by_product_type_and_an_empty_set_still_needs_a_trial(): void
    {
        config(['lab.required_measurements.by_product_type' => ['perfume' => [], 'shampoo' => ['ph']]]);

        $perfume = $this->inLab($this->versionWithLines(['product_type' => 'perfume']));
        $this->assertThrowsException(FormulaWorkflowException::class, fn () => $this->workflow->approve($perfume));
        $this->trialWith($perfume, [LabProperty::CloudPoint]);
        $this->workflow->approve($perfume);
        $this->assertSame(FormulaStatus::Approved, $perfume->fresh()->status);

        $shampoo = $this->inLab($this->versionWithLines(['product_type' => 'shampoo']));
        $this->trialWith($shampoo, [LabProperty::Ph]);
        $this->workflow->approve($shampoo);
        $this->assertSame(FormulaStatus::Approved, $shampoo->fresh()->status);
    }

    public function test_rejection_needs_a_reason_and_keeps_it_with_the_signature(): void
    {
        $version = $this->inLab();

        foreach (['', '   ', "\n"] as $reason) {
            $this->assertThrowsException(FormulaWorkflowException::class, fn () => $this->workflow->reject($version, $reason), __('exceptions.workflow_rejection_reason_required'));
        }
        $this->assertSame(FormulaStatus::LabTesting, $version->fresh()->status);

        $signature = $this->workflow->reject($version, '  Separated after a week ');

        $this->assertSame(FormulaStatus::Rejected, $version->fresh()->status);
        $this->assertSame('Separated after a week', $signature->comment);
    }

    public function test_a_rejected_version_cannot_be_approved_or_moved_again(): void
    {
        $version = $this->inLab();
        $this->trialWith($version, $this->requiredProperties());
        $this->workflow->reject($version, 'No');

        $this->assertThrowsException(FormulaWorkflowException::class, fn () => $this->workflow->approve($version->fresh()));
        $this->assertThrowsException(FormulaWorkflowException::class, fn () => $this->workflow->sendToLab($version->fresh()));
    }

    public function test_an_approved_version_and_its_lines_become_immutable(): void
    {
        $version = $this->inLab();
        $this->trialWith($version, $this->requiredProperties());
        $this->workflow->approve($version);
        $approved = $version->fresh();
        $line = FormulaLine::query()->where('formula_version_id', $approved->id)->firstOrFail();

        $this->assertThrowsException(FormulaWorkflowException::class, fn () => $this->workflow->reject($approved, 'Too late'));
        $this->assertThrowsException(ImmutableRecordException::class, fn () => $approved->update(['reason' => 'edit']));
        $this->assertThrowsException(ImmutableRecordException::class, fn () => $approved->update(['status' => FormulaStatus::Rejected]));
        $this->assertThrowsException(QueryException::class, fn () => DB::transaction(fn () => DB::table('formula_versions')->where('id', $approved->id)->update(['status' => 'draft'])));
        $this->assertThrowsException(QueryException::class, fn () => DB::transaction(fn () => DB::table('formula_lines')->where('id', $line->id)->update(['percent_ww' => '99'])));
        $this->assertTrue($approved->fresh()->verifyHash());
    }

    public function test_a_tampered_version_cannot_be_signed(): void
    {
        $version = $this->versionWithLines();
        DB::table('formula_lines')->where('formula_version_id', $version->id)->where('line_no', 1)->update(['percent_ww' => '11']);

        $this->assertThrowsException(FormulaWorkflowException::class, fn () => $this->workflow->sendToLab($version->fresh()), __('exceptions.workflow_hash_mismatch'));
        $this->assertSame(FormulaStatus::Draft, $version->fresh()->status);
    }

    public function test_signatures_are_append_only_and_a_rejection_needs_its_reason_in_the_database_too(): void
    {
        $version = $this->inLab();
        $signature = FormulaVersionTransition::query()->sole();

        $this->assertThrowsException(ImmutableRecordException::class, fn () => $signature->update(['comment' => 'x']));
        $this->assertThrowsException(ImmutableRecordException::class, fn () => $signature->delete());
        $this->assertThrowsException(QueryException::class, fn () => DB::transaction(fn () => DB::table('formula_version_transitions')->where('id', $signature->id)->update(['comment' => 'x'])));
        $this->assertThrowsException(QueryException::class, fn () => DB::transaction(fn () => DB::table('formula_version_transitions')->where('id', $signature->id)->delete()));
        $this->assertThrowsException(QueryException::class, fn () => DB::transaction(fn () => DB::table('formula_version_transitions')->insert([
            'id' => (string) Str::uuid(), 'formula_version_id' => $version->id, 'from_status' => 'lab_testing', 'to_status' => 'rejected',
            'comment' => '  ', 'version_hash' => $version->hash,
        ])));
    }

    public function test_the_http_moves_flash_the_outcome(): void
    {
        $version = $this->versionWithLines();
        $url = fn (string $name): string => route("formulas.versions.{$name}", [$version->formula_id, $version->version]);

        $this->post($url('send-to-lab'), ['comment' => 'Go'])->assertSessionHas('success', __('formulas.workflow.sent_to_lab'));
        $this->post($url('approve'))->assertSessionHas('error', __('exceptions.workflow_no_completed_trial'));
        $this->post($url('reject'), ['reason' => ''])->assertSessionHasErrors('reason');

        $this->trialWith($version, $this->requiredProperties());
        $this->post($url('approve'), ['comment' => 'Fine'])->assertSessionHas('success', __('formulas.workflow.approved'));
        $this->assertSame(FormulaStatus::Approved, $version->fresh()->status);
        $this->post($url('reject'), ['reason' => 'Late'])->assertSessionHas('error');
    }

    public function test_the_editor_shows_the_workflow_panel_of_the_version(): void
    {
        $version = $this->inLab();
        $trial = $this->trialWith($version, [LabProperty::Ph]);

        $this->get(route('formulas.versions.edit', [$version->formula_id, $version->version]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('version.id', $version->id)
            ->where('workflow.status', 'lab_testing')
            ->where('workflow.next', ['approved', 'rejected'])
            ->where('workflow.hash', $version->hash)
            ->where('workflow.coverage.covered', false)
            ->where('workflow.coverage.missing', ['density', 'viscosity'])
            ->where('workflow.signatures.0.to', 'lab_testing')
            ->where('workflow.trials.0.code', $trial->code)
            ->where('workflow.urls.newTrial', route('lab.trials.create', ['formula_version' => $version->id], false)));
    }
}
