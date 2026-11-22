<script setup>
/**
 * The compliance panel of the formula editor. It takes the draft being edited (its lines) and each material's composition,
 * and asks the engine, in the browser, what every rule of the chosen rule sets says (evaluateCompliance), plus the IFRA check of
 * the fragrance oils (checkIfraCompliance), and shows the separate EDA path notice for disinfectants (DisinfectionNotice). The rule sets that name the formula's product type are chosen at first; any
 * other can be added. The panel only shows: a missing composition is UNKNOWN (never PASS) and says which material to fill in.
 *
 *   <CompliancePanel :draft="draft" :formula="formula" :rule-sets="compliance.ruleSets" :entities="entities" :sources="sources" />
 */
import { computed, ref } from 'vue';
import { Alert, Button, Card, FormField, MultiSelect, SegmentedControl } from '@kit';
import { path } from '../../lib/routes';
import { useI18n } from '../../composables/useI18n';
import { combinedStatus, complianceState, evaluateIfra, evaluateRuleSet, formulaComposition, gapsByMaterial, ruleSetsForProductType } from '../../lib/compliance';
import DisinfectionNotice from './DisinfectionNotice.vue';
import IfraBlock from './IfraBlock.vue';
import RuleResults from './RuleResults.vue';
import StatusBadge from '../StatusBadge.vue';

const props = defineProps({
    draft: { type: Object, required: true },
    formula: { type: Object, required: true },
    ruleSets: { type: Array, required: true },
    entities: { type: Object, default: () => ({}) },
    sources: { type: Object, default: () => ({}) },
});

const { t } = useI18n();

const selectedIds = ref(ruleSetsForProductType(props.ruleSets, props.formula.product_type).map((ruleSet) => ruleSet.id));
const rinse = ref('any');
const ifraCategory = ref(props.formula.ifra_category ?? '');

const options = computed(() => props.ruleSets.map((ruleSet) => ({
    value: ruleSet.id,
    label: `${ruleSet.name} · ${ruleSet.version}`,
    description: `${ruleSet.code} · ${t(`enums.rule_set_status.${ruleSet.status}`)}`,
})));
const rinseOptions = computed(() => ['any', 'yes', 'no'].map((value) => ({ value, label: t(`compliance.rules.form.rinse_off_${value}`) })));

const state = computed(() => complianceState(props.draft.lines.value));
// A q.s. line with no share yet is balanced by the engine for the check, so the typed shares not adding up is expected.
const hasOpenQs = computed(() => props.draft.lines.value.some((line) => line.is_qs && line.percent === ''));
const labelOf = (materialId) => props.draft.labelOf(materialId);

/** What the engine's rule scopes are matched against: the product type, rinse-off or leave-on, the IFRA category. */
const context = computed(() => ({
    productType: props.formula.product_type,
    ...(rinse.value === 'any' ? {} : { rinseOff: rinse.value === 'yes' }),
    ...(ifraCategory.value.trim() === '' ? {} : { ifraCategory: ifraCategory.value.trim() }),
}));

const evaluations = computed(() => {
    if (state.value !== 'ready') {
        return [];
    }

    const composition = formulaComposition(props.draft.lines.value, props.draft.materials);

    return props.ruleSets
        .filter((ruleSet) => selectedIds.value.includes(ruleSet.id))
        .map((ruleSet) => evaluateRuleSet(composition, ruleSet, context.value));
});

const ifra = computed(() => (state.value === 'ready' ? evaluateIfra(props.draft.lines.value, props.draft.materials, ifraCategory.value) : null));

const overall = computed(() => (state.value === 'ready' ? combinedStatus([...evaluations.value.map((evaluation) => evaluation.overall), ...(ifra.value ? [ifra.value.report.overall] : [])]) : null));

/** Materials whose composition (or its source) stops a rule being decided: where to go to fill it in. */
const compositionGaps = computed(() => gapsByMaterial(evaluations.value.flatMap((evaluation) => evaluation.report?.missing ?? []))
    .map((gap) => ({ ...gap, label: labelOf(gap.materialId), href: path('materials.show', { material: gap.materialId, tab: 'composition' }) })));
</script>

<template>
    <Card :title="t('compliance.panel.title')" :description="t('compliance.panel.description')" data-testid="compliance-card">
        <div class="flex flex-col gap-5">
            <!-- N-12: kept apart from the rule results below; the engine decides, nothing here changes the overall status. -->
            <DisinfectionNotice :formula-id="formula.id" :family-group="formula.family_group ?? null" :saved-claims="formula.claims ?? []" />

            <div class="grid gap-4 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                <FormField :label="t('compliance.panel.rule_sets')" :hint="t('compliance.panel.rule_sets_hint')">
                    <MultiSelect v-model="selectedIds" :options="options" :placeholder="t('compliance.panel.rule_sets_placeholder')" :aria-label="t('compliance.panel.rule_sets')" data-testid="rule-set-select" />
                </FormField>
                <FormField :label="t('compliance.rules.form.rinse_off')" :hint="t('compliance.panel.rinse_off_hint')">
                    <SegmentedControl v-model="rinse" :options="rinseOptions" />
                </FormField>
            </div>

            <Alert v-if="ruleSets.length === 0" tone="info" icon="ph:info" data-testid="no-rule-sets">
                {{ t('compliance.panel.no_rule_sets') }}
                <template #actions><Button :href="path('compliance.index')" variant="secondary" size="sm">{{ t('compliance.title') }}</Button></template>
            </Alert>
            <Alert v-else-if="state === 'empty'" tone="info" icon="ph:info" data-testid="state-empty">{{ t('compliance.panel.state_empty') }}</Alert>
            <Alert v-else-if="state === 'incomplete'" tone="info" icon="ph:info" data-testid="state-incomplete">{{ t('compliance.panel.state_incomplete') }}</Alert>
            <template v-else>
                <Alert v-if="!draft.summary.value.withinTolerance && !hasOpenQs" tone="warning" icon="ph:warning" data-testid="sum-warning">{{ t('compliance.panel.sum_warning') }}</Alert>

                <div class="flex flex-wrap items-center gap-3" data-testid="compliance-overall-row">
                    <span class="eyebrow text-ink-3">{{ t('compliance.panel.overall') }}</span>
                    <StatusBadge kind="check_status" :status="overall" data-testid="compliance-overall" />
                    <span v-if="overall === 'UNKNOWN'" class="text-label text-ink-3">{{ t('compliance.panel.unknown_hint') }}</span>
                    <span v-else-if="overall === 'NOT_APPLICABLE'" class="text-label text-ink-3">{{ t('compliance.panel.nothing_checked') }}</span>
                </div>

                <div v-if="compositionGaps.length" data-testid="composition-gaps">
                    <h3 class="mb-2 type-h6 text-ink">{{ t('compliance.panel.gaps_title') }}</h3>
                    <ul class="flex flex-col divide-y divide-line rounded-lg border border-line">
                        <li v-for="gap in compositionGaps" :key="gap.materialId" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2" :data-gap-material="gap.materialId">
                            <span class="font-semibold text-ink">{{ gap.label }}</span>
                            <span class="min-w-0 flex-1 text-label text-ink-3">{{ t('compliance.panel.gap_composition') }}</span>
                            <Button :href="gap.href" variant="link" size="sm" icon="ph:arrow-square-out">{{ t('compliance.panel.fill_composition') }}</Button>
                        </li>
                    </ul>
                </div>

                <Alert v-if="evaluations.length === 0" tone="info" icon="ph:info" data-testid="no-selection">{{ t('compliance.panel.no_selection') }}</Alert>
                <RuleResults v-for="evaluation in evaluations" :key="evaluation.ruleSet.id" :evaluation="evaluation" :label-of="labelOf" :entities="entities" :sources="sources" />
            </template>

            <IfraBlock
                v-model:category="ifraCategory"
                :formula-id="formula.id"
                :saved-category="formula.ifra_category"
                :ifra="ifra"
                :label-of="labelOf"
                :entities="entities"
                :sources="sources"
            />
        </div>
    </Card>
</template>
