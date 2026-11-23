<?php

namespace Tests\Unit\Assistant;

use App\Support\Assistant\AssistantCostCalculator;
use Tests\TestCase;

class AssistantCostCalculatorTest extends TestCase
{
    /** @param  array<string, mixed>  $pricing */
    private function cost(array $pricing, int $in, int $out): ?array
    {
        config(['assistant.pricing' => $pricing]);

        return (new AssistantCostCalculator)->cost($in, $out);
    }

    public function test_no_prices_means_no_cost_not_zero(): void
    {
        $this->assertNull($this->cost(['currency' => null, 'input_per_million_tokens' => null, 'output_per_million_tokens' => null], 1000, 1000));
    }

    public function test_one_missing_price_or_currency_means_no_cost(): void
    {
        $this->assertNull($this->cost(['currency' => 'USD', 'input_per_million_tokens' => '1', 'output_per_million_tokens' => null], 10, 10));
        $this->assertNull($this->cost(['currency' => 'USD', 'input_per_million_tokens' => null, 'output_per_million_tokens' => '1'], 10, 10));
        $this->assertNull($this->cost(['currency' => '', 'input_per_million_tokens' => '1', 'output_per_million_tokens' => '1'], 10, 10));
    }

    public function test_a_price_that_is_not_a_plain_decimal_is_not_used(): void
    {
        $this->assertNull($this->cost(['currency' => 'USD', 'input_per_million_tokens' => '1e3', 'output_per_million_tokens' => '1'], 10, 10));
        $this->assertNull($this->cost(['currency' => 'USD', 'input_per_million_tokens' => '-1', 'output_per_million_tokens' => '1'], 10, 10));
    }

    public function test_the_cost_is_exact_decimal_arithmetic(): void
    {
        // TEST-FIXTURE prices: 3 per million input tokens, 15 per million output tokens. (1,500,000 x 3 + 200,000 x 15) / 1,000,000 = 7.5
        $this->assertSame(['amount' => '7.50000000', 'currency' => 'USD'], $this->cost(['currency' => 'USD', 'input_per_million_tokens' => '3', 'output_per_million_tokens' => '15'], 1_500_000, 200_000));
        // 1 token at 0.1 per million = 0.0000001 (no float drift).
        $this->assertSame('0.00000010', $this->cost(['currency' => 'USD', 'input_per_million_tokens' => '0.1', 'output_per_million_tokens' => '0'], 1, 0)['amount']);
    }

    public function test_zero_tokens_cost_zero_when_the_prices_are_known(): void
    {
        $this->assertSame('0.00000000', $this->cost(['currency' => 'USD', 'input_per_million_tokens' => '3', 'output_per_million_tokens' => '15'], 0, 0)['amount']);
    }
}
