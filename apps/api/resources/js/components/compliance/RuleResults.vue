<script setup>
/**
 * What the engine says about each rule of one rule set for the formula being edited: the status (PASS / FAIL / UNKNOWN /
 * not applicable), the value against the limit with its margin (each one opens the engine's calculation), the rule's source
 * and, for UNKNOWN, why: which materials lack a composition, with a link to fill it in. Read-only: nothing is decided here.
 *
 *   <RuleResults :evaluation="evaluation" :label-of="draft.labelOf" :entities="entities" :sources="sources" />
 *
 * `evaluation` is the result of evaluateRuleSet (lib/compliance.js).
 */
import { computed } from 'vue';
import { Alert, Badge, Button, DataTable, Tooltip } from '@kit';
import { path } from '../../lib/routes';
import { useI18n } from '../../composables/useI18n';
import { reasonText } from '../../lib/compliance';
import ExplainableNum from '../explain/ExplainableNum.vue';
import Num from '../Num.vue';
import StatusBadge from '../StatusBadge.vue';

const props = defineProps({
    evaluation: { type: Object, required: true },
    labelOf: { type: Function, required: true },
    entities: { type: Object, default: () => ({}) },
    sources: { type: Object, default: () => ({}) },
});

const { t, has } = useI18n();

const ruleSet = computed(() => props.evaluation.ruleSet);
const report = computed(() => props.evaluation.report);
const rules = computed(() => Object.fromEntries(ruleSet.value.assembled.rules.map((rule) => [rule.id, rule])));
// A published rule set whose rules no longer hash to the hash stored when it was published has been tampered with.
const hashMismatch = computed(() => ruleSet.value.status === 'published' && report.value !== null && report.value.ruleSetHash !== ruleSet.value.hash);

const columns = computed(() => [
    { key: 'rule', label: t('compliance.panel.columns.rule') },
    { key: 'status', label: t('compliance.panel.columns.status') },
    { key: 'value', label: t('compliance.panel.columns.value'), align: 'end' },
    { key: 'margin', label: t('compliance.panel.columns.margin'), align: 'end', hideBelow: 'md' },
    { key: 'source', label: t('compliance.panel.columns.source'), hideBelow: 'lg' },
    { key: 'reason', label: t('compliance.panel.columns.reason'), hideBelow: 'md' },
]);

const rows = computed(() => (report.value?.results ?? []).map((result) => ({ ...result, id: result.ruleId })));

const messageOf = (result) => ruleSet.value.messages[result.ruleId] ?? (has(`engine.${result.messageKey}`) ? t(`engine.${result.messageKey}`) : result.ruleId);
const limitText = (result) => (result.limit === null ? t(`enums.rule_operator.${result.operator}`) : null);
const sourceOf = (result) => rules.value[result.ruleId]?.source ?? null;

/** The unmet needs of one result: { general: [text], materials: [{ materialId, label, href, texts }] }. */
function gapsOf(result) {
    const byMaterial = new Map();
    const general = [];

    for (const gap of result.missing) {
        const text = reasonText(t, has, gap.reason);

        if (gap.materialId) {
            byMaterial.set(gap.materialId, [...new Set([...(byMaterial.get(gap.materialId) ?? []), text])]);
        } else {
            general.push(text);
        }
    }

    return {
        general: [...new Set(general)],
        materials: [...byMaterial].map(([materialId, texts]) => ({ materialId, label: props.labelOf(materialId), href: path('materials.show', { material: materialId, tab: 'composition' }), texts })),
    };
}

const warnings = computed(() => [...new Set((report.value?.warnings ?? []).map((warning) => {
    const [key, ...details] = warning.split(':');

    return has(`engine.${key}`) ? t(`engine.${key}`, { material: props.labelOf(details[0]) ?? '', confidence: details[2] ? t(`enums.confidence.${details[2]}`) : '', rule: details[0] ?? '' }) : warning;
}))]);
</script>

<template>
    <div class="flex flex-col gap-3" :data-testid="`ruleset-${ruleSet.code}-${ruleSet.version}`">
        <div class="flex flex-wrap items-center gap-2">
            <h3 class="type-h6 text-ink">{{ ruleSet.name }}</h3>
            <bdi dir="ltr" class="font-mono text-label text-ink-3">{{ ruleSet.code }}</bdi>
            <Badge tone="neutral" mono>{{ ruleSet.version }}</Badge>
            <StatusBadge kind="rule_set_status" :status="ruleSet.status" />
            <span class="ms-auto flex items-center gap-2">
                <StatusBadge kind="check_status" :status="evaluation.overall" data-testid="ruleset-overall" />
                <Button :href="ruleSet.href" variant="link" size="sm" icon="ph:arrow-square-out">{{ t('compliance.panel.open_rule_set') }}</Button>
            </span>
        </div>

        <Alert v-if="evaluation.errors.length" tone="danger" icon="ph:warning" :title="t('compliance.panel.invalid_rule_set')">
            <ul class="list-disc ps-5"><li v-for="gap in evaluation.errors" :key="`${gap.property}-${gap.reason}`">{{ reasonText(t, has, gap.reason) }} <bdi dir="ltr" class="font-mono text-label">{{ gap.property }}</bdi></li></ul>
        </Alert>
        <Alert v-if="hashMismatch" tone="danger" icon="ph:warning" :title="t('compliance.panel.hash_mismatch')" data-testid="hash-mismatch">{{ t('compliance.panel.hash_mismatch_description') }}</Alert>
        <Alert v-if="ruleSet.status === 'draft'" tone="warning" icon="ph:info" data-testid="draft-notice">{{ t('compliance.panel.draft_notice') }}</Alert>
        <Alert v-if="evaluation.overall === 'NOT_APPLICABLE' && report" tone="info" icon="ph:info" data-testid="not-applicable-notice">{{ t('compliance.panel.none_applicable') }}</Alert>

        <DataTable v-if="report" :columns="columns" :rows="rows" row-key="id" :mobile-columns="['rule', 'status', 'value']" :caption="ruleSet.name" :row-tone="(row) => (row.status === 'FAIL' ? 'danger' : null)">
            <template #cell-rule="{ row }">
                <span class="flex flex-col gap-0.5" :data-rule-result="row.ruleId" :data-status="row.status">
                    <span>{{ messageOf(row) }}</span>
                    <bdi dir="ltr" class="font-mono text-label text-ink-3">{{ row.ruleId }}</bdi>
                    <span v-if="row.group" class="text-label text-ink-3">{{ t('compliance.panel.group', { group: row.group }) }}</span>
                </span>
            </template>
            <template #cell-status="{ row }"><StatusBadge kind="check_status" :status="row.status" /></template>
            <template #cell-value="{ row }">
                <span v-if="row.status === 'NOT_APPLICABLE'" class="text-ink-3">{{ t('common.no_data') }}</span>
                <span v-else class="flex flex-col items-end gap-0.5">
                    <span class="flex items-baseline gap-1 whitespace-nowrap" data-testid="rule-value">
                        <span v-if="!row.valueComplete" class="text-label text-ink-3">{{ t('compliance.panel.at_least') }}</span>
                        <ExplainableNum :traced="row.value" :label="messageOf(row)" :entities="entities" :sources="sources" />
                        <span class="text-label text-ink-3">%</span>
                    </span>
                    <span class="flex items-baseline gap-1 whitespace-nowrap text-label text-ink-3" data-testid="rule-limit">
                        <template v-if="row.limit !== null">{{ t(`enums.rule_operator.${row.operator}`) }} <Num :value="row.limit" /> <bdi dir="ltr">{{ row.unit }}</bdi></template>
                        <template v-else>{{ limitText(row) }}</template>
                    </span>
                </span>
            </template>
            <template #cell-margin="{ row }">
                <ExplainableNum v-if="row.margin" :traced="row.margin" :label="t('compliance.panel.columns.margin')" :entities="entities" :sources="sources" />
                <span v-else class="text-ink-3">{{ t('common.no_data') }}</span>
            </template>
            <template #cell-source="{ row }">
                <span v-if="sourceOf(row)" class="flex flex-col">
                    <span>{{ sourceOf(row).title }}</span>
                    <span class="text-label text-ink-3">{{ sourceOf(row).ref }}</span>
                </span>
                <Tooltip v-else :content="t('compliance.rules.pending_source_hint')">
                    <Badge tone="warning" icon="ph:warning" tabindex="0">{{ t('compliance.rules.pending_source') }}</Badge>
                </Tooltip>
            </template>
            <template #cell-reason="{ row }">
                <div v-if="row.missing.length" class="flex flex-col gap-1 text-label" data-testid="rule-gaps">
                    <p v-for="text in gapsOf(row).general" :key="text" class="text-ink-2">{{ text }}</p>
                    <p v-for="gap in gapsOf(row).materials" :key="gap.materialId" class="flex flex-wrap items-baseline gap-x-2 text-ink-2">
                        <a :href="gap.href" class="font-semibold text-primary-ink underline underline-offset-2">{{ gap.label }}</a>
                        <span>{{ gap.texts.join(' ') }}</span>
                    </p>
                </div>
                <span v-else class="text-ink-3">{{ t('common.no_data') }}</span>
            </template>
        </DataTable>

        <ul v-if="warnings.length" class="list-disc ps-5 text-label text-ink-3">
            <li v-for="warning in warnings" :key="warning">{{ warning }}</li>
        </ul>
    </div>
</template>
