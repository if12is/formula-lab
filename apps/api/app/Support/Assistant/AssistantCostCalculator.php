<?php

namespace App\Support\Assistant;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The cost of the tokens a request used, from the prices in config/assistant.php (money per million tokens). Exact decimals, no floats.
 * A price that is not written (null: not yet sourced, see HUMAN-TODO) gives no cost at all (null), never 0 and never a remembered price.
 */
final class AssistantCostCalculator
{
    /** Decimals kept in a stored cost. */
    private const SCALE = 8;

    private const TOKENS_PER_PRICE_UNIT = 1_000_000;

    /** @return array{amount: string, currency: string}|null */
    public function cost(int $inputTokens, int $outputTokens): ?array
    {
        $pricing = (array) config('assistant.pricing');
        $input = $this->price($pricing['input_per_million_tokens'] ?? null);
        $output = $this->price($pricing['output_per_million_tokens'] ?? null);
        $currency = $pricing['currency'] ?? null;

        if ($input === null || $output === null || ! is_string($currency) || $currency === '') {
            return null;
        }

        $amount = $input->multipliedBy($inputTokens)
            ->plus($output->multipliedBy($outputTokens))
            ->dividedBy(self::TOKENS_PER_PRICE_UNIT, self::SCALE, RoundingMode::HalfEven);

        return ['amount' => (string) $amount, 'currency' => $currency];
    }

    private function price(mixed $value): ?BigDecimal
    {
        if (! is_string($value) || preg_match('/^\d+(\.\d+)?$/', $value) !== 1) {
            return null;
        }

        return BigDecimal::of($value);
    }
}
