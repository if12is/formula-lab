<?php

namespace App\Services\Pricing;

use App\Enums\Confidence;

/**
 * The margin rules of config/pricing.php as the engine and the screens take them. The service adds nothing: a rule part that
 * is not a string of the right shape is left out, so the engine reports exactly what is missing instead of a default
 * filling the gap. The source id the engine traces is "pricing-rule:<key>"; its title is the rule's own `source` text.
 */
final class PricingRuleService
{
    public const SOURCE_PREFIX = 'pricing-rule:';

    private const BASES = ['markup_on_cost', 'margin_on_price'];

    /** @return list<array{key: string, label: string, basis: string|null, margin_pct: string|null, distribution_cost: string|null, source: string|null, confidence: string|null, complete: bool, engine: array<string, mixed>}> */
    public function all(): array
    {
        return array_values(array_map(fn (string $key): array => $this->present($key), array_keys($this->rules())));
    }

    public function exists(string $key): bool
    {
        return array_key_exists($key, $this->rules());
    }

    /** @return list<array{value: string, label: string}> */
    public function options(): array
    {
        return array_map(fn (array $rule): array => ['value' => $rule['key'], 'label' => $rule['label']], $this->all());
    }

    /**
     * The rule as the engine's PriceRuleInput (contract of priceListLine), or null for an unknown key.
     *
     * @return array<string, mixed>|null
     */
    public function engineRule(string $key): ?array
    {
        if (! $this->exists($key)) {
            return null;
        }

        $rule = $this->rules()[$key];
        $rule = is_array($rule) ? $rule : [];
        $source = $this->text($rule['source'] ?? null);
        $confidence = $this->confidence($rule['confidence'] ?? null);
        $validFrom = $this->text($rule['valid_from'] ?? null);
        $sourced = fn (mixed $value, string $unit): ?array => $this->decimal($value) === null || $source === null || $confidence === null || $validFrom === null
            ? null
            : ['value' => $this->decimal($value), 'unit' => $unit, 'sourceId' => self::SOURCE_PREFIX.$key, 'confidence' => $confidence, 'validFrom' => $validFrom];

        return array_filter([
            'key' => $key,
            'basis' => in_array($rule['basis'] ?? null, self::BASES, true) ? $rule['basis'] : null,
            'marginPct' => $sourced($rule['margin_pct'] ?? null, '%w/w'),
            'distributionCostPerUnit' => $sourced($rule['distribution_cost'] ?? null, 'EGP'),
        ], fn (mixed $part): bool => $part !== null);
    }

    /** @return array{key: string, label: string, basis: string|null, margin_pct: string|null, distribution_cost: string|null, source: string|null, confidence: string|null, complete: bool, engine: array<string, mixed>} */
    private function present(string $key): array
    {
        $rule = $this->rules()[$key];
        $rule = is_array($rule) ? $rule : [];
        $labels = is_array($rule['label'] ?? null) ? $rule['label'] : [];
        $engine = $this->engineRule($key) ?? [];

        return [
            'key' => $key,
            'label' => (string) ($labels[app()->getLocale()] ?? $labels['ar'] ?? $key),
            'basis' => $engine['basis'] ?? null,
            'margin_pct' => $this->decimal($rule['margin_pct'] ?? null),
            'distribution_cost' => $this->decimal($rule['distribution_cost'] ?? null),
            'source' => $this->text($rule['source'] ?? null),
            'confidence' => $this->confidence($rule['confidence'] ?? null),
            'engine' => $engine,
            'complete' => isset($engine['basis'], $engine['marginPct']) && (($rule['distribution_cost'] ?? null) === null || isset($engine['distributionCostPerUnit'])),
        ];
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return (array) config('pricing.rules');
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function decimal(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d+(\.\d+)?$/', $value) === 1 ? $value : null;
    }

    private function confidence(mixed $value): ?string
    {
        return is_string($value) && Confidence::tryFrom($value) !== null ? $value : null;
    }
}
