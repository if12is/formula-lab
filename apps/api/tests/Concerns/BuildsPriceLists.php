<?php

namespace Tests\Concerns;

use App\Enums\Confidence;
use App\Enums\PriceListMode;
use App\Models\Formula;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Source;
use Illuminate\Support\Str;

/**
 * Price lists with hand-computable numbers (TEST-FIXTURE values, not business data): the rule "merchants" is +25 % on cost,
 * so a unit cost of 32.00 prices at 40.00 and, with a distribution cost of 10, at 50.00.
 */
trait BuildsPriceLists
{
    /** @param  array<string, mixed>  $override */
    protected function defineRules(array $override = []): void
    {
        config(['pricing.rules' => [
            'merchants' => array_replace([
                'label' => ['ar' => 'تجار الجملة', 'en' => 'Wholesale merchants'],
                'basis' => 'markup_on_cost',
                'margin_pct' => '25',
                'distribution_cost' => null,
                'source' => 'Test rule, owner decision 2026-11-22',
                'confidence' => 'L6_community',
                'valid_from' => '2026-11-22',
            ], $override),
        ]]);
    }

    /** A formula published to Sanad with a sourced unit cost. @param  array<string, mixed>  $attributes */
    protected function costedFormula(string $tenantId, string $unitCost = '32.00', array $attributes = []): Formula
    {
        return Formula::factory()->create($attributes + [
            'tenant_id' => $tenantId,
            'sanad_product_uuid' => (string) Str::uuid(),
            'unit_cost' => $unitCost,
            'unit_cost_source_id' => Source::factory()->create()->id,
            'unit_cost_confidence' => Confidence::L3Standard,
        ]);
    }

    protected function ruleItem(PriceList $list, Formula $formula, string $rule = 'merchants'): PriceListItem
    {
        return PriceListItem::factory()->create(['price_list_id' => $list->id, 'formula_id' => $formula->id, 'mode' => PriceListMode::Rule, 'rule_key' => $rule]);
    }

    protected function overrideItem(PriceList $list, Formula $formula, string $price): PriceListItem
    {
        return PriceListItem::factory()->override($price)->create(['price_list_id' => $list->id, 'formula_id' => $formula->id]);
    }
}
