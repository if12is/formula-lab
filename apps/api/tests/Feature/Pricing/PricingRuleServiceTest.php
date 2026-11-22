<?php

namespace Tests\Feature\Pricing;

use App\Services\Pricing\PricingRuleService;
use Tests\Concerns\BuildsPriceLists;
use Tests\TestCase;

/** The margin rules of config/pricing.php reach the engine exactly as configured: nothing is defaulted, a gap stays a gap. */
class PricingRuleServiceTest extends TestCase
{
    use BuildsPriceLists;

    private function rules(): PricingRuleService
    {
        return app(PricingRuleService::class);
    }

    public function test_no_rules_are_configured_by_default(): void
    {
        $this->assertSame([], config('pricing.rules'));
        $this->assertSame([], $this->rules()->all());
        $this->assertSame([], $this->rules()->options());
        $this->assertFalse($this->rules()->exists('merchants'));
        $this->assertNull($this->rules()->engineRule('merchants'));
    }

    public function test_a_complete_rule_becomes_the_engine_input_with_its_source_and_confidence(): void
    {
        $this->defineRules(['distribution_cost' => '10']);

        $engine = $this->rules()->engineRule('merchants');

        $this->assertSame('merchants', $engine['key']);
        $this->assertSame('markup_on_cost', $engine['basis']);
        $this->assertSame(['value' => '25', 'unit' => '%w/w', 'sourceId' => 'pricing-rule:merchants', 'confidence' => 'L6_community', 'validFrom' => '2026-11-22'], $engine['marginPct']);
        $this->assertSame(['value' => '10', 'unit' => 'EGP', 'sourceId' => 'pricing-rule:merchants', 'confidence' => 'L6_community', 'validFrom' => '2026-11-22'], $engine['distributionCostPerUnit']);
        $this->assertTrue($this->rules()->all()[0]['complete']);
    }

    public function test_a_rule_part_that_is_missing_or_malformed_is_left_out_not_filled_in(): void
    {
        foreach ([['margin_pct' => null], ['margin_pct' => 25], ['margin_pct' => 25.5], ['margin_pct' => '-5'], ['margin_pct' => 'abc'], ['source' => ''], ['source' => null], ['confidence' => 'L9'], ['valid_from' => null]] as $broken) {
            $this->defineRules($broken);

            $engine = $this->rules()->engineRule('merchants');

            $this->assertArrayNotHasKey('marginPct', $engine, json_encode($broken));
            $this->assertFalse($this->rules()->all()[0]['complete'], json_encode($broken));
        }
    }

    public function test_an_unknown_basis_is_left_out_and_a_broken_distribution_cost_makes_the_rule_incomplete(): void
    {
        $this->defineRules(['basis' => 'sideways']);
        $this->assertArrayNotHasKey('basis', $this->rules()->engineRule('merchants'));
        $this->assertFalse($this->rules()->all()[0]['complete']);

        $this->defineRules(['distribution_cost' => 'x']);
        $this->assertArrayNotHasKey('distributionCostPerUnit', $this->rules()->engineRule('merchants'));
        $this->assertFalse($this->rules()->all()[0]['complete']);
    }

    public function test_the_label_follows_the_locale_and_falls_back_to_arabic_then_the_key(): void
    {
        $this->defineRules();
        $this->assertSame('Wholesale merchants', (function (): string {
            app()->setLocale('en');

            return $this->rules()->options()[0]['label'];
        })());
        app()->setLocale('ar');
        $this->assertSame('تجار الجملة', $this->rules()->options()[0]['label']);

        $this->defineRules(['label' => null]);
        $this->assertSame('merchants', $this->rules()->options()[0]['label']);
    }
}
