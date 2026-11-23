<?php

namespace App\Services\Assistant\Tools;

use App\Enums\Confidence;
use App\Support\Assistant\AssistantTool;
use App\Support\Assistant\Concerns\BuildsToolAnswers;
use App\Support\Assistant\ToolContext;
use Illuminate\Validation\Rule;

/**
 * What stands behind an answer the model already got in this turn: every source it relies on with its confidence, the weakest
 * confidence, the values that are below L2 and what is missing. It only reads the earlier answer (no new number is made), so the
 * model can say how far a figure can be trusted without judging it itself.
 */
final class ExplainResultTool implements AssistantTool
{
    use BuildsToolAnswers;

    /** Confidence levels worse than this are flagged to the user (system prompt rule 7). */
    private const FLAG_BELOW = Confidence::L2SupplierTds;

    /** @param  list<string>  $explainable  The names of the tools whose answers can be explained. */
    public function __construct(private readonly array $explainable = [
        'get_material', 'calculate_formula', 'optimize_tier', 'check_compliance', 'convert_units',
    ]) {}

    public function name(): string
    {
        return 'explain_result';
    }

    public function description(): string
    {
        return 'Explain how far an earlier tool answer of this conversation turn can be trusted: its sources, their confidence levels (L1 best, L7 estimate), the weakest level, values below L2 and what is missing. Call it after another tool when the user asks why or how sure.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['tool' => ['type' => 'string', 'enum' => $this->explainable]],
            'required' => ['tool'],
            'additionalProperties' => false,
        ];
    }

    public function __invoke(array $input, ToolContext $context): array
    {
        $valid = $this->validated($input, ['tool' => ['required', Rule::in($this->explainable)]]);
        $result = $context->latest($valid['tool']);
        if ($result === null) {
            return $this->missing([['property' => 'tool_result', 'reason' => 'explain.no_result_in_this_turn']]);
        }

        $sources = [];
        $this->collectSources($result['data'] ?? [], $sources);
        ksort($sources);
        $order = array_map(fn (Confidence $case): string => $case->value, Confidence::cases());
        $rank = fn (string $confidence): int => (int) array_search($confidence, $order, true);
        $flag = $rank(self::FLAG_BELOW->value);

        $rows = [];
        foreach ($sources as $sourceId => $confidences) {
            foreach (array_keys($confidences) as $confidence) {
                $rows[] = ['sourceId' => $sourceId, 'confidence' => $confidence];
            }
        }
        $weakest = $rows === [] ? null : array_reduce($rows, fn (?string $worst, array $row): string => $worst === null || $rank($row['confidence']) > $rank($worst) ? $row['confidence'] : $worst);

        return $this->answer([
            'tool' => $valid['tool'],
            'answerOk' => (bool) ($result['ok'] ?? false),
            'sources' => $rows,
            'weakestConfidence' => $weakest,
            'belowL2' => array_values(array_filter($rows, fn (array $row): bool => $rank($row['confidence']) > $flag)),
            'missing' => $result['missing'] ?? [],
            'warnings' => $result['warnings'] ?? [],
        ]);
    }

    /**
     * @param  array<string, array<string, true>>  $sources  Source id => the confidence levels seen with it.
     */
    private function collectSources(mixed $node, array &$sources): void
    {
        if (! is_array($node)) {
            return;
        }
        $confidence = $node['confidence'] ?? null;
        $sourceId = $node['sourceId'] ?? $node['source_id'] ?? null;
        if (is_string($confidence) && Confidence::tryFrom($confidence) !== null && is_string($sourceId)) {
            $sources[$sourceId][$confidence] = true;
        }
        foreach ($node as $child) {
            $this->collectSources($child, $sources);
        }
    }
}
