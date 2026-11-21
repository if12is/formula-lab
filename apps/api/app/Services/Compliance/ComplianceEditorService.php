<?php

namespace App\Services\Compliance;

use App\Models\RuleSet;
use App\Support\Compliance\RuleSetAssembler;

/**
 * The rule sets the formula editor and the label page hand to the engine: each one assembled as the engine reads it,
 * with the wording of the rules typed in the interface. The browser picks the ones that apply to a product type and
 * runs the engine; nothing is decided here.
 */
final class ComplianceEditorService
{
    public function __construct(private RuleSetAssembler $assembler) {}

    /** @return list<array<string, mixed>> */
    public function ruleSets(): array
    {
        return RuleSet::query()
            ->with(['rules' => fn ($rules) => $rules->where('active', true)])
            ->orderBy('code')
            ->orderByDesc('created_at')
            ->get()
            ->map(function (RuleSet $ruleSet): array {
                $assembled = $this->assembler->assemble($ruleSet);

                return [
                    'id' => $ruleSet->getKey(),
                    'code' => $ruleSet->code,
                    'version' => $ruleSet->version,
                    'name' => $ruleSet->name,
                    'status' => $ruleSet->status->value,
                    'hash' => $ruleSet->hash,
                    'href' => route('compliance.show', $ruleSet, false),
                    'productTypes' => array_values(array_unique(array_merge(...array_map(
                        fn (array $rule): array => $rule['scope']['productTypes'] ?? [],
                        $assembled['rules'],
                    ) ?: [[]]))),
                    'assembled' => $assembled,
                    // Wording of rules typed in the interface, by rule id; the others word themselves through their messageKey.
                    'messages' => $ruleSet->rules->filter(fn ($rule): bool => $rule->message !== null)->mapWithKeys(fn ($rule): array => [$rule->rule['id'] => $rule->message])->all(),
                ];
            })
            ->all();
    }
}
