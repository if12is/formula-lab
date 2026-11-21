<?php

namespace App\Actions\Compliance;

use App\Enums\RuleSetStatus;
use App\Exceptions\Domain\RuleSetException;
use App\Models\ComplianceRule;
use App\Models\RuleSet;
use Illuminate\Support\Facades\DB;

/**
 * Loads a rule set written in the engine's rules DSL (a file such as packages/engine/data/rules/*.json) as a DRAFT rule
 * set. Whatever the file says, it arrives as a draft: publishing is a separate, validated step. Does nothing when that
 * code and version are already there, so a seeder can run again.
 */
final class ImportRuleSetAction
{
    /**
     * @param  array{id: string, version: string, rules: list<array<string, mixed>>, status?: string}  $document
     *
     * @throws RuleSetException When the document lacks an id, a version or a rule id.
     */
    public function __invoke(array $document, string $name, ?string $scopeNotes = null): RuleSet
    {
        $code = (string) ($document['id'] ?? '');
        $version = (string) ($document['version'] ?? '');

        if ($code === '' || $version === '') {
            throw RuleSetException::invalid([['property' => '$.id', 'reason' => 'dsl.required']]);
        }

        $existing = RuleSet::query()->where('code', $code)->where('version', $version)->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($document, $code, $version, $name, $scopeNotes): RuleSet {
            $ruleSet = RuleSet::query()->create([
                'code' => $code,
                'version' => $version,
                'name' => $name,
                'scope_notes' => $scopeNotes,
                'status' => RuleSetStatus::Draft,
            ]);

            foreach (array_values($document['rules'] ?? []) as $index => $rule) {
                ComplianceRule::query()->create([
                    'rule_set_id' => $ruleSet->getKey(),
                    'rule' => $rule,
                    'message' => null,
                    'active' => true,
                    'position' => $index + 1,
                ]);
            }

            return $ruleSet;
        });
    }
}
