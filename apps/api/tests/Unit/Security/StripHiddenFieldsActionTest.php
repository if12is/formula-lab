<?php

namespace Tests\Unit\Security;

use App\Actions\Security\StripHiddenFieldsAction;
use PHPUnit\Framework\TestCase;

/** U-07: the pure stripping step. */
class StripHiddenFieldsActionTest extends TestCase
{
    public function test_it_removes_matching_keys_at_any_depth_and_in_lists(): void
    {
        $payload = ['code' => 'F-1', 'unit_cost' => '18.4', 'rows' => [['id' => 1, 'sellPrice' => '25', 'name' => 'A'], ['id' => 2, 'name' => 'B']], 'deep' => ['a' => ['cost' => ['x' => 1], 'keep' => 1]]];

        $kept = (new StripHiddenFieldsAction)($payload, ['unit_cost', 'sell_price', 'cost']);

        $this->assertSame(['code' => 'F-1', 'rows' => [['id' => 1, 'name' => 'A'], ['id' => 2, 'name' => 'B']], 'deep' => ['a' => ['keep' => 1]]], $kept);
    }

    public function test_wildcards_match_the_snake_case_form_of_camel_case_keys(): void
    {
        $kept = (new StripHiddenFieldsAction)(['percentWW' => '12', 'percent_ww' => '12', 'totalCostPerKg' => '3', 'percentile' => 'p9', 'name' => 'n'], ['percent_*', '*_cost_*']);

        $this->assertSame(['percentile' => 'p9', 'name' => 'n'], $kept);
    }

    public function test_an_exempt_key_is_kept_and_not_searched(): void
    {
        $kept = (new StripHiddenFieldsAction)(['audience' => ['sees_cost' => false, 'cost' => 0], 'cost' => 1], ['cost', '*_cost'], ['audience']);

        $this->assertSame(['audience' => ['sees_cost' => false, 'cost' => 0]], $kept);
    }

    public function test_without_patterns_the_payload_is_returned_as_it_is(): void
    {
        $payload = ['cost' => 1, 'list' => [1, 2]];

        $this->assertSame($payload, (new StripHiddenFieldsAction)($payload, []));
    }

    public function test_list_indexes_and_scalars_are_never_taken_for_keys(): void
    {
        $this->assertSame(['cost', 5, ['x']], (new StripHiddenFieldsAction)(['cost', 5, ['x']], ['cost', '*']));
    }
}
