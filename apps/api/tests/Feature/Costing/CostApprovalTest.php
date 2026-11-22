<?php

namespace Tests\Feature\Costing;

use App\Enums\Confidence;
use App\Exceptions\Domain\CostApprovalException;
use App\Exceptions\Domain\ImmutableRecordException;
use App\Models\Formula;
use App\Models\FormulaCostApproval;
use App\Models\Price;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Costing\CostApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCostedFormulas;
use Tests\TestCase;

/** The cost approval (Y-05 input): a signed statement on an approved version, resting on the engine's prices; a stale one stops counting. */
class CostApprovalTest extends TestCase
{
    use BuildsCostedFormulas, RefreshDatabase;

    private Formula $formula;

    private CostApprovalService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formula = $this->costedProduct(Tenant::factory()->create()->id);
        $this->service = app(CostApprovalService::class);
    }

    public function test_approving_records_the_unit_cost_the_version_and_the_engine_prices_it_rests_on(): void
    {
        $user = User::factory()->create();

        $approval = $this->service->approve($this->formula, $user, 'Checked against the November invoices.');

        $this->assertSame('18.4573', $approval->unit_cost);
        $this->assertSame($this->formula->versions()->firstOrFail()->id, $approval->formula_version_id);
        $this->assertSame($user->id, $approval->approved_by);
        $this->assertSame(['egp_per_kg' => '10', 'quote_id' => $this->priceA->id, 'confidence' => 'L1_coa_batch'], $approval->material_prices[$this->materialA->id]);
        $this->assertSame('25', $approval->material_prices[$this->materialB->id]['egp_per_kg']);
        $this->assertSame(64, strlen($approval->report_output_hash));
        $this->assertSame($approval->id, $this->service->current($this->formula)?->id);
    }

    public function test_a_formula_with_no_approved_version_cannot_have_its_cost_approved(): void
    {
        $draft = $this->costedProduct(Tenant::factory()->create()->id, ['code' => 'DRAFT'], approved: false);

        $this->expectException(CostApprovalException::class);
        $this->service->approve($draft, null, null);
    }

    public function test_a_missing_unit_cost_and_an_estimate_unit_cost_are_refused(): void
    {
        $this->formula->update(['unit_cost' => null, 'unit_cost_source_id' => null, 'unit_cost_confidence' => null]);
        try {
            $this->service->approve($this->formula->fresh(), null, null);
            $this->fail('A product with no unit cost must not be approved.');
        } catch (CostApprovalException $exception) {
            $this->assertStringContainsString($this->formula->code, $exception->getMessage());
        }

        $this->formula->update(['unit_cost' => '18.4573', 'unit_cost_source_id' => Source::factory()->create()->id, 'unit_cost_confidence' => Confidence::L7Estimate]);
        $this->expectExceptionMessage(__('exceptions.cost_unit_cost_is_estimate', ['formula' => $this->formula->code]));
        $this->service->approve($this->formula->fresh(), null, null);
    }

    public function test_an_incomplete_cost_report_blocks_the_approval_and_names_the_material(): void
    {
        Price::query()->where('trade_material_id', $this->materialB->id)->delete();

        try {
            $this->service->approve($this->formula, null, null);
            $this->fail('A cost with a missing price must not be approved.');
        } catch (CostApprovalException $exception) {
            $this->assertStringContainsString('Material B', $exception->getMessage());
            $this->assertSame(0, FormulaCostApproval::query()->count());
        }
    }

    public function test_changing_the_unit_cost_after_approval_makes_it_stale(): void
    {
        $this->service->approve($this->formula, null, null);
        $this->assertNull($this->service->staleReason($this->formula));

        $this->formula->update(['unit_cost' => '19.0000']);

        $this->assertSame('unit_cost', $this->service->staleReason($this->formula->fresh()));
        $this->assertNull($this->service->current($this->formula->fresh()));
    }

    public function test_a_newer_approved_version_makes_the_approval_stale(): void
    {
        $this->service->approve($this->formula, null, null);

        $this->version($this->formula, 2, [[$this->materialA, '50'], [$this->materialB, '50']]);

        $this->assertSame('version', $this->service->staleReason($this->formula->fresh()));
    }

    public function test_a_product_never_approved_reports_none(): void
    {
        $this->assertSame('none', $this->service->staleReason($this->formula));
        $this->assertNull($this->service->current($this->formula));
    }

    public function test_an_approval_can_never_be_changed_or_deleted(): void
    {
        $approval = $this->service->approve($this->formula, null, null);

        try {
            $approval->update(['unit_cost' => '1']);
            $this->fail('An approval is append-only.');
        } catch (ImmutableRecordException) {
            $this->expectException(ImmutableRecordException::class);
            $approval->delete();
        }
    }

    public function test_the_approve_route_records_it_and_flashes_the_result(): void
    {
        $this->post(route('formulas.cost-approvals.store', $this->formula), ['comment' => 'ok'])->assertRedirect()->assertSessionHas('success', __('cost_report.approved'));

        $this->assertSame('ok', FormulaCostApproval::query()->firstOrFail()->comment);

        $draft = $this->costedProduct(Tenant::factory()->create()->id, ['code' => 'DRAFT-2'], approved: false);
        $this->post(route('formulas.cost-approvals.store', $draft))->assertRedirect()->assertSessionHas('error');
    }
}
