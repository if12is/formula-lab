<?php

namespace Tests\Feature\Decisions;

use App\Exceptions\Domain\DecisionOverrideException;
use App\Exceptions\Domain\FinishedGoodPublishBlockedException;
use App\Exceptions\Domain\ImmutableRecordException;
use App\Models\DecisionOverride;
use App\Models\Formula;
use App\Models\FormulaPublication;
use App\Models\User;
use App\Services\Costing\CostApprovalService;
use App\Services\Costing\FinishedGoodPublishService;
use Database\Seeders\TaxRegimeSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsCostedFormulas;
use Tests\fakes\sanad\SanadFake;
use Tests\Feature\Sanad\SanadTestCase;

/**
 * Y-04: a blocked publish decision can be overridden only by a signed-in user whose role is allowed, with a written reason and the
 * password typed again; the override is recorded for good and allows exactly that decision. Setup: approved prices A 10 and B 25 EGP/kg;
 * a rise of A to 10.6 is 6 % (over the 5 % limit, rule PS-04), to 10.7 is 7 %.
 */
class DecisionOverrideTest extends SanadTestCase
{
    use BuildsCostedFormulas;

    private const DETAILS = ['sku' => 'FG-SHP-400', 'uom' => 'unit', 'sell_price' => '39.00'];

    private const PASSWORD = 'correct horse battery';

    private Formula $formula;

    private FinishedGoodPublishService $service;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        SanadFake::use();
        $this->seed(TaxRegimeSeeder::class);
        config(['decisions.override_roles' => ['quality_manager']]);
        $this->formula = $this->costedProduct($this->tenant->id, ['tax_mode' => 'rate', 'tax_rate_pct' => '14']);
        $this->service = app(FinishedGoodPublishService::class);
        $this->manager = User::factory()->withRole('quality_manager')->create(['password' => self::PASSWORD]);
        app(CostApprovalService::class)->approve($this->formula, null, null);
        $this->priceA->update(['amount' => '10.6']);
    }

    private function override(?User $user = null, string $password = self::PASSWORD, string $reason = 'Trial batch, price rise reviewed'): DecisionOverride
    {
        return $this->service->overrideBlocked($this->formula->fresh(), self::DETAILS, $user ?? $this->manager, $reason, $password);
    }

    public function test_an_authorized_signed_override_is_recorded_with_who_role_reason_and_the_failed_rules(): void
    {
        $override = $this->override();

        $this->assertSame([$this->manager->id, 'quality_manager', 'Trial batch, price rise reviewed', 'password_reentry'], [$override->signed_by, $override->role, $override->reason, $override->signature_method]);
        $record = $override->record;
        $this->assertSame(['overridden', 'blocked', ['PS-04']], [$record['result'], $record['originalDecision'], array_column($record['reasons'], 'ruleId')]);
        $this->assertSame([(string) $this->manager->id, 'quality_manager', $override->id], [$record['override']['by'], $record['override']['role'], $record['override']['signatureRef']]);
        $this->assertSame($record['decisionHash'], $override->decision_hash);
        $this->assertSame(1, DecisionOverride::query()->count());
    }

    public function test_the_override_lets_exactly_that_decision_publish_and_the_publication_keeps_it(): void
    {
        try {
            $this->service->publish($this->formula->fresh(), self::DETAILS, $this->manager);
            $this->fail('A blocked decision must not publish without an override.');
        } catch (FinishedGoodPublishBlockedException) {
            $this->assertSame(0, FormulaPublication::query()->count());
        }

        $override = $this->override();
        $publication = $this->service->publish($this->formula->fresh(), self::DETAILS, $this->manager);

        $this->assertSame($override->id, $publication->decision_override_id);
        $this->assertSame('overridden', $publication->decision['result']);
        $this->assertSame($override->decision_hash, $publication->decision_hash);
    }

    public function test_an_override_does_not_cover_a_decision_whose_inputs_changed(): void
    {
        $this->override();
        $this->priceA->update(['amount' => '10.7']);

        $this->expectException(FinishedGoodPublishBlockedException::class);
        $this->service->publish($this->formula->fresh(), self::DETAILS, $this->manager);
    }

    public function test_a_wrong_password_records_nothing_and_too_many_wrong_tries_lock_the_signature(): void
    {
        RateLimiter::clear('esign:'.$this->manager->id);

        for ($try = 0; $try < (int) config('decisions.signature_max_attempts'); $try++) {
            $this->assertThrowsException(DecisionOverrideException::class, fn () => $this->override(null, 'wrong'), __('exceptions.override_wrong_password'));
        }
        $this->assertThrowsException(DecisionOverrideException::class, fn () => $this->override());
        $this->assertSame(0, DecisionOverride::query()->count());
    }

    public function test_a_role_that_may_not_override_and_nobody_signed_in_are_refused(): void
    {
        $clerk = User::factory()->withRole('clerk')->create(['password' => self::PASSWORD]);

        $this->assertThrowsException(DecisionOverrideException::class, fn () => $this->override($clerk), __('exceptions.override_role_not_allowed'));
        $this->assertThrowsException(DecisionOverrideException::class, fn () => $this->service->overrideBlocked($this->formula->fresh(), self::DETAILS, null, 'x', self::PASSWORD), __('exceptions.override_not_signed_in'));
        $this->assertSame(0, DecisionOverride::query()->count());
    }

    public function test_with_no_roles_configured_nobody_can_override(): void
    {
        config(['decisions.override_roles' => []]);

        $this->assertThrowsException(DecisionOverrideException::class, fn () => $this->override(), __('exceptions.override_role_not_allowed'));
    }

    public function test_a_decision_that_is_not_blocked_cannot_be_overridden(): void
    {
        $this->priceA->update(['amount' => '10.4']);

        $this->assertThrowsException(DecisionOverrideException::class, fn () => $this->override(), __('exceptions.override_not_blocked'));
    }

    public function test_an_override_row_cannot_be_changed_or_deleted_by_the_app_or_the_database(): void
    {
        $override = $this->override();

        $this->assertThrowsException(ImmutableRecordException::class, fn () => $override->update(['reason' => 'tamper']));
        $this->assertThrowsException(ImmutableRecordException::class, fn () => $override->delete());
        $this->assertThrowsException(QueryException::class, fn () => DB::transaction(fn () => DB::table('decision_overrides')->where('id', $override->id)->update(['reason' => 'tamper'])), 'append-only');
        $this->assertSame('Trial batch, price rise reviewed', $override->fresh()->reason);
    }

    public function test_the_panel_offers_the_override_to_an_allowed_role_and_explains_why_not_to_others(): void
    {
        $this->get(route('formulas.publish', $this->formula))->assertInertia(fn (Assert $page) => $page
            ->where('decision.state', 'blocked')
            ->where('override.available', false)->where('override.why', __('decision_ui.override.why_not_signed_in'))->where('override.active', null));

        $this->actingAs($this->manager)->get(route('formulas.publish', $this->formula))->assertInertia(fn (Assert $page) => $page
            ->where('override.available', true)->where('override.why', null));

        $this->override();
        $this->actingAs($this->manager)->get(route('formulas.publish', ['formula' => $this->formula, 'sell_price' => '39.00']))->assertInertia(fn (Assert $page) => $page
            ->where('override.active.role', 'quality_manager')->where('override.active.by', $this->manager->name)->where('override.active.reason', 'Trial batch, price rise reviewed'));
    }

    public function test_the_override_route_signs_with_the_password_and_validates_the_reason(): void
    {
        $url = route('formulas.publish.override', $this->formula);
        $body = ['reason' => 'Trial batch', 'password' => self::PASSWORD, 'sell_price' => '39.00'];

        $this->post($url, $body)->assertForbidden();
        $this->actingAs(User::factory()->withRole('clerk')->create())->post($url, $body)->assertForbidden();

        $this->actingAs($this->manager)->post($url, [...$body, 'reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($this->manager)->post($url, [...$body, 'password' => 'wrong'])->assertSessionHasErrors(['password' => __('exceptions.override_wrong_password')]);
        $this->assertSame(0, DecisionOverride::query()->count());

        $this->actingAs($this->manager)->post($url, $body)->assertRedirect()->assertSessionHas('success', __('decision_ui.override.recorded'));
        $this->assertSame(1, DecisionOverride::query()->count());
    }
}
