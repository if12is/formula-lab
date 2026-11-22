<?php

namespace Tests\Feature\Decisions;

use App\Enums\LabProperty;
use App\Services\Formula\FormulaWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLabFixtures;
use Tests\TestCase;

/** Y-08: the approval panel lists every failed condition with the step that fixes it, in the same shape the publish page uses. */
class ApprovalDecisionPanelTest extends TestCase
{
    use BuildsLabFixtures, RefreshDatabase;

    public function test_a_version_without_a_completed_trial_lists_the_lab_condition_and_the_step(): void
    {
        $decision = app(FormulaWorkflowService::class)->panel($this->versionWithLines())['decision'];

        $this->assertSame('blocked', $decision['state']);
        $this->assertSame(['AFV-06'], array_column($decision['reasons'], 'rule'));
        $this->assertStringContainsString(LabProperty::Ph->label(), $decision['reasons'][0]['message']);
        $this->assertSame(__('decisions.remedy.record_lab_results'), $decision['reasons'][0]['remedy']);
    }

    public function test_a_trial_that_lacks_a_required_measurement_names_exactly_what_is_missing(): void
    {
        $version = $this->versionWithLines();
        $this->trialWith($version, [LabProperty::from('ph')]);

        $decision = app(FormulaWorkflowService::class)->panel($version)['decision'];

        $this->assertSame('blocked', $decision['state']);
        $this->assertStringNotContainsString(LabProperty::Ph->label(), $decision['reasons'][0]['message']);
        $this->assertStringContainsString(LabProperty::Density->label(), $decision['reasons'][0]['message']);
    }

    public function test_a_covered_version_has_no_failed_condition(): void
    {
        $version = $this->versionWithLines();
        $this->trialWith($version, $this->requiredProperties());

        $this->assertSame(['state' => 'allowed', 'reasons' => []], app(FormulaWorkflowService::class)->panel($version)['decision']);
    }
}
