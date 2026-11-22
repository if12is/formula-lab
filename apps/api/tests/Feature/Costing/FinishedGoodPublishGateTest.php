<?php

namespace Tests\Feature\Costing;

use App\Exceptions\Domain\FinishedGoodPublishBlockedException;
use App\Exceptions\Domain\SanadLinkException;
use App\Models\Formula;
use App\Models\FormulaPublication;
use App\Models\Price;
use App\Models\SanadDevice;
use App\Models\SanadOutbox;
use App\Models\Source;
use App\Services\Costing\CostApprovalService;
use App\Services\Costing\FinishedGoodPublishService;
use Database\Seeders\TaxRegimeSeeder;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsCostedFormulas;
use Tests\fakes\sanad\SanadFake;
use Tests\Feature\Sanad\SanadTestCase;

/**
 * G-09: the finished good reaches Sanad only through the approved cost and the publish-to-sanad decision rule set.
 * Hand-computed: approved prices A 10 and B 25 EGP/kg; a rise of A to 10.4 is 4 % (inside the 5 % limit), to 10.6 is 6 % (outside).
 */
class FinishedGoodPublishGateTest extends SanadTestCase
{
    use BuildsCostedFormulas;

    private const DETAILS = ['sku' => 'FG-SHP-400', 'barcode' => '6221234567890', 'uom' => 'unit', 'sell_price' => '39.00', 'wholesale_price' => '31.00', 'guide_price' => '45.00', 'name_en' => 'Premium Shampoo 400ml'];

    private Formula $formula;

    private FinishedGoodPublishService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        SanadFake::use();
        $this->seed(TaxRegimeSeeder::class);
        $this->formula = $this->costedProduct($this->tenant->id, ['tax_mode' => 'rate', 'tax_rate_pct' => '14']);
        $this->service = app(FinishedGoodPublishService::class);
    }

    private function approve(): void
    {
        app(CostApprovalService::class)->approve($this->formula, null, null);
    }

    /** @return list<string> */
    private function failedRules(array $details = self::DETAILS): array
    {
        return array_column($this->service->decide($this->formula->fresh(), $details, null)['reasons'] ?? [], 'rule');
    }

    public function test_an_unapproved_cost_blocks_the_publish_and_nothing_is_queued(): void
    {
        $this->assertSame(['PS-01'], $this->failedRules());

        try {
            $this->service->publish($this->formula, self::DETAILS, null);
            $this->fail('An unapproved cost must not be published.');
        } catch (FinishedGoodPublishBlockedException $exception) {
            $this->assertStringContainsString(__('decisions.ps.cost_not_approved'), $exception->getMessage());
            $this->assertSame('PS-01', $exception->reasons[0]['rule']);
            $this->assertSame(__('decisions.remedy.approve_cost'), $exception->reasons[0]['remedy']);
        }
        $this->assertSame(0, SanadOutbox::query()->count());
        $this->assertSame(0, FormulaPublication::query()->count());
        $this->assertNull($this->formula->fresh()->sanad_product_uuid);
    }

    public function test_an_approved_cost_publishes_with_the_approved_cost_sku_barcode_and_prices(): void
    {
        $this->approve();

        $publication = $this->service->publish($this->formula, [...self::DETAILS, 'cost_price' => '1.00'], null);

        $payload = SanadOutbox::query()->findOrFail($publication->outbox_id)->payload;
        $this->assertSame(['finished_good', '18.4573', '39.00', '31.00', '45.00', 'FG-SHP-400', '6221234567890', '14.00', 'unit'], [
            $payload['item_kind'], $payload['cost_price'], $payload['sell_price'], $payload['wholesale_price'], $payload['guide_price'], $payload['sku'], $payload['barcode'], $payload['tax_rate'], $payload['uom'],
        ]);
        $this->assertTrue($payload['is_sellable']);
        $this->assertSame($payload['uuid'], $this->formula->fresh()->sanad_product_uuid);
        $this->assertSame(['outbox', 'allowed'], [$publication->mode, $publication->decision['result']]);
        $this->assertSame(64, strlen($publication->decision_hash));
        $this->assertSame('18.4573', $publication->details['cost_price'], 'a cost typed into the form never replaces the approved one');
    }

    public function test_publishing_again_updates_the_same_sanad_product_and_prefills_the_form(): void
    {
        $this->approve();
        $first = $this->service->publish($this->formula, self::DETAILS, null);
        $second = $this->service->publish($this->formula->fresh(), [...self::DETAILS, 'sell_price' => '41.00'], null);

        $this->assertSame($first->sanad_product_uuid, $second->sanad_product_uuid);
        $this->assertSame('41.00', $this->service->panel($this->formula->fresh(), null)['details']['sell_price']);
        $this->assertCount(2, $this->service->panel($this->formula->fresh(), null)['history']);
    }

    public function test_a_missing_tax_setting_blocks_the_publish_through_the_price_waterfall_rule(): void
    {
        $this->approve();
        $this->formula->update(['tax_mode' => null, 'tax_rate_pct' => null]);

        $decision = $this->service->decide($this->formula->fresh(), self::DETAILS, null);

        $this->assertSame('blocked', $decision['state']);
        $this->assertSame(['PS-02'], array_column($decision['reasons'], 'rule'));
        $this->assertStringContainsString(__('sanad_publish.missing.tax_setting'), $decision['reasons'][0]['message']);
    }

    public function test_a_missing_selling_price_blocks_the_publish(): void
    {
        $this->approve();

        $this->assertSame(['PS-02'], $this->failedRules([...self::DETAILS, 'sell_price' => '']));
    }

    public function test_the_page_judges_the_selling_price_typed_in_the_form_before_it_is_saved(): void
    {
        $this->approve();

        $this->get(route('formulas.publish', $this->formula))->assertInertia(fn (Assert $page) => $page
            ->where('decision.state', 'blocked')->where('decision.reasons.0.rule', 'PS-02'));
        $this->get(route('formulas.publish', ['formula' => $this->formula, 'sell_price' => '39']))->assertInertia(fn (Assert $page) => $page
            ->where('decision.state', 'allowed')->where('details.sell_price', null));
    }

    public function test_a_disabled_tax_is_a_choice_and_does_not_block(): void
    {
        $this->approve();
        $this->formula->update(['tax_mode' => 'disabled', 'tax_rate_pct' => null]);

        $this->assertSame([], $this->failedRules());
    }

    public function test_a_price_rise_inside_the_limit_publishes_and_one_beyond_it_needs_reapproval(): void
    {
        $this->approve();

        $this->priceA->update(['amount' => '10.4']);
        $inside = $this->service->decide($this->formula->fresh(), self::DETAILS, null);
        $this->assertSame(['allowed', '4'], [$inside['state'], $inside['max_change_pct']]);

        $this->priceA->update(['amount' => '10.6']);
        $beyond = $this->service->decide($this->formula->fresh(), self::DETAILS, null);
        $this->assertSame('blocked', $beyond['state']);
        $this->assertSame(['PS-04'], array_column($beyond['reasons'], 'rule'));
        $this->assertSame('6', $beyond['max_change_pct']);
        $this->assertStringContainsString('Material A', $beyond['reasons'][0]['message']);
        $this->assertSame(__('decisions.ps.price_change_needs_reapproval', ['maxChangePct' => '6', 'thresholdPct' => '5', 'materialIds' => 'Material A']), $beyond['reasons'][0]['message']);
    }

    public function test_a_material_price_that_is_gone_makes_the_decision_undecidable_not_unchanged(): void
    {
        $this->approve();
        Price::query()->where('trade_material_id', $this->materialB->id)->delete();

        $decision = $this->service->decide($this->formula->fresh(), self::DETAILS, null);

        $this->assertSame('undecidable', $decision['state']);
        $this->assertStringContainsString('Material B', $decision['detail']);
    }

    public function test_a_changed_unit_cost_makes_the_approval_stale_and_blocks(): void
    {
        $this->approve();
        $this->formula->update(['unit_cost' => '20', 'unit_cost_source_id' => Source::factory()->create()->id]);

        $this->assertSame(['PS-01'], $this->failedRules());
    }

    public function test_the_same_decision_inputs_give_the_same_decision_hash(): void
    {
        $this->approve();

        $a = $this->service->decide($this->formula->fresh(), self::DETAILS, null);
        $b = $this->service->decide($this->formula->fresh(), self::DETAILS, null);

        $this->assertSame($a['decision_hash'], $b['decision_hash']);
    }

    public function test_without_a_device_and_a_lan_address_nothing_is_published(): void
    {
        $this->approve();
        SanadDevice::query()->delete();
        config(['sanad.client.base_url' => null]);

        $this->expectException(SanadLinkException::class);
        $this->service->publish($this->formula, self::DETAILS, null);
    }

    public function test_the_publish_page_and_post_work_through_the_routes(): void
    {
        $this->get(route('formulas.publish', $this->formula))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Formulas/Publish')
            ->where('decision.state', 'blocked')
            ->where('decision.reasons.0.rule', 'PS-01')
            ->where('inForce', false)
            ->where('details.sku', $this->formula->code));

        $this->post(route('formulas.publish.store', $this->formula), self::DETAILS)->assertRedirect()->assertSessionHas('error');

        $this->approve();
        $this->post(route('formulas.publish.store', $this->formula), self::DETAILS)->assertRedirect()->assertSessionHas('success', __('sanad_publish.published_outbox'));
        $this->assertSame(1, FormulaPublication::query()->count());
    }

    public function test_the_form_refuses_a_non_decimal_price_and_a_missing_sku(): void
    {
        $this->approve();

        $this->post(route('formulas.publish.store', $this->formula), [...self::DETAILS, 'sell_price' => '39,5'])->assertSessionHasErrors('sell_price');
        $this->post(route('formulas.publish.store', $this->formula), [...self::DETAILS, 'sku' => ''])->assertSessionHasErrors('sku');
        $this->post(route('formulas.publish.store', $this->formula), [...self::DETAILS, 'wholesale_price' => '1e3'])->assertSessionHasErrors('wholesale_price');
        $this->assertSame(0, FormulaPublication::query()->count());
    }
}
