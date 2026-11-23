<?php

namespace App\Support\Engine;

use App\Exceptions\Domain\EngineException;

/**
 * The production calculations of the engine (packages/engine/scripts/production.ts), one method per operation. Every number in an
 * answer is the engine's; PHP only shapes the input and reads the answer. An answer is `{ok: true, value, warnings}` or
 * `{ok: false, missing: [{materialId?, property, reason}], warnings}`.
 */
final class ProductionEngine
{
    public function __construct(private readonly EngineRunner $engine) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, value?: array<string, mixed>, missing?: list<array<string, mixed>>, warnings: list<string>}
     *
     * @throws EngineException
     */
    public function run(string $operation, array $input): array
    {
        /** @var array{ok: bool, value?: array<string, mixed>, missing?: list<array<string, mixed>>, warnings: list<string>} */
        return $this->engine->run('production', ['op' => $operation, 'input' => $input]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, value?: array<string, mixed>, missing?: list<array<string, mixed>>, warnings: list<string>}
     */
    public function weighingSheet(array $input): array
    {
        return $this->run('weighing-sheet', $input);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, value?: array<string, mixed>, missing?: list<array<string, mixed>>, warnings: list<string>}
     */
    public function deviation(array $input): array
    {
        return $this->run('deviation', $input);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, value?: array<string, mixed>, missing?: list<array<string, mixed>>, warnings: list<string>}
     */
    public function waste(array $input): array
    {
        return $this->run('waste', $input);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, value?: array<string, mixed>, missing?: list<array<string, mixed>>, warnings: list<string>}
     */
    public function lotAlkali(array $input): array
    {
        return $this->run('lot-alkali', $input);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, value?: array<string, mixed>, missing?: list<array<string, mixed>>, warnings: list<string>}
     */
    public function actualCost(array $input): array
    {
        return $this->run('actual-cost', $input);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, value?: array<string, mixed>, missing?: list<array<string, mixed>>, warnings: list<string>}
     */
    public function checkLimits(array $input): array
    {
        return $this->run('check-limits', $input);
    }

    /**
     * Many planned-versus-actual pairs judged in one call: `items` is `key => {planned, actual, unit}`; the answer maps each key to
     * `{ok: true, value}` or `{ok: false, missing}`.
     *
     * @param  array<string, array{planned: string, actual: string, unit: string}>  $items
     * @return array<string, array{ok: bool, value?: array<string, mixed>, missing?: list<array<string, mixed>>}>
     */
    public function deviations(array $items, ?string $thresholdPct): array
    {
        if ($items === []) {
            return [];
        }

        return $this->run('deviations', ['items' => $items, 'thresholdPct' => $thresholdPct])['value'] ?? [];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, value?: array<string, mixed>, missing?: list<array<string, mixed>>, warnings: list<string>}
     */
    public function lotUnitCost(array $input): array
    {
        return $this->run('lot-unit-cost', $input);
    }

    /**
     * Values written at the 6 decimals of the Sanad contract.
     *
     * @param  array<string, string>  $values
     * @return array<string, string>
     *
     * @throws EngineException When a value is not a decimal.
     */
    public function contractDecimals(array $values): array
    {
        $answer = $this->run('contract-decimals', ['values' => $values]);
        if (! $answer['ok']) {
            throw EngineException::invalidInput('production');
        }

        return $answer['value'] ?? [];
    }
}
