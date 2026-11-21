<?php

namespace App\Services\Compliance;

use App\Enums\RuleOperator;
use App\Http\Filters\ListQuery;
use App\Models\ComplianceRule;
use App\Models\RuleSet;
use App\Support\Compliance\RuleDocument;
use App\Support\Compliance\RuleSetAssembler;
use Illuminate\Http\Request;

/** Read side of one rule set's page: its facts, the filtered rules table and the rule set as the engine reads it. */
final class RuleSetDetailService
{
    public function __construct(private RuleSetAssembler $assembler) {}

    /**
     * @return array{ruleSet: array<string, mixed>, list: array<string, mixed>, assembled: array<string, mixed>, versions: list<array<string, mixed>>}
     */
    public function detail(RuleSet $ruleSet, Request $request): array
    {
        $ruleSet->load('source');

        return [
            'ruleSet' => $this->summary($ruleSet),
            'list' => $this->rules($ruleSet, $request),
            // The whole rule set, whatever the table's filters show: the browser validates and hashes this.
            'assembled' => $this->assembler->assemble($ruleSet),
            'versions' => RuleSet::query()
                ->where('code', $ruleSet->code)
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (RuleSet $version): array => [
                    'id' => $version->getKey(),
                    'version' => $version->version,
                    'status' => $version->status->value,
                    'href' => route('compliance.show', $version, false),
                    'current' => $version->is($ruleSet),
                ])
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function summary(RuleSet $ruleSet): array
    {
        return [
            'id' => $ruleSet->getKey(),
            'code' => $ruleSet->code,
            'version' => $ruleSet->version,
            'name' => $ruleSet->name,
            'scope_notes' => $ruleSet->scope_notes,
            'status' => $ruleSet->status->value,
            'hash' => $ruleSet->hash,
            'published_at' => $ruleSet->published_at?->toIso8601String(),
            'source' => $ruleSet->source === null ? null : ['id' => $ruleSet->source->getKey(), 'title' => $ruleSet->source->title],
            'rules_count' => $ruleSet->rules()->count(),
            'active_rules_count' => $ruleSet->rules()->where('active', true)->count(),
            'created_at' => $ruleSet->created_at?->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    private function rules(RuleSet $ruleSet, Request $request): array
    {
        $units = $ruleSet->rules()->reorder()->whereNotNull('unit')->distinct()->orderBy('unit')->pluck('unit')->all();

        return ListQuery::for($ruleSet->rules()->getQuery(), $request)
            ->search(['rule_key', 'message', 'group_key'], 'compliance.rules.search_placeholder')
            ->select('operator', RuleOperator::class, labelKey: 'compliance.rules.filters.operator')
            ->select('unit', $units, labelKey: 'compliance.rules.filters.unit')
            ->boolean('pending_source', labelKey: 'compliance.rules.filters.pending_source')
            ->boolean('active', labelKey: 'compliance.rules.filters.active')
            ->sortable(['position', 'rule_key', 'operator'], default: 'position', labelPrefix: 'compliance.rules.sorts')
            ->perPage(25, [10, 25, 50, 100])
            ->paginate(fn (ComplianceRule $complianceRule): array => $this->row($complianceRule));
    }

    /** @return array<string, mixed> */
    private function row(ComplianceRule $complianceRule): array
    {
        $rule = $complianceRule->rule;
        $targetType = RuleDocument::targetType($rule);

        return [
            'id' => $complianceRule->getKey(),
            'position' => $complianceRule->position,
            'rule_key' => $rule['id'] ?? '',
            'message' => $complianceRule->message,
            'message_key' => $rule['messageKey'] ?? null,
            'product_types' => $rule['scope']['productTypes'] ?? [],
            'rinse_off' => $rule['scope']['rinseOff'] ?? null,
            'ifra_category' => $rule['scope']['ifraCategory'] ?? null,
            'target_type' => $targetType,
            'target_value' => $targetType === null ? null : ($rule['target'][$targetType] ?? null),
            'operator' => $rule['operator'] ?? null,
            'limit' => $rule['limit'] ?? null,
            'unit' => $rule['unit'] ?? null,
            'group' => $rule['group'] ?? null,
            'source' => isset($rule['source']['sourceId']) ? ['id' => $rule['source']['sourceId'], 'title' => $rule['source']['title'] ?? '', 'ref' => $rule['source']['ref'] ?? ''] : null,
            'pending_source' => (bool) ($rule['pendingSource'] ?? false),
            'active' => $complianceRule->active,
            'rule' => $rule,
        ];
    }
}
