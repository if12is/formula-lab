<script setup>
/**
 * The phases of a formula (M-01): the share of the water, oil and cool-down phases as the engine adds them up
 * (phaseSummary) and what its phase check refuses (validatePhases). It shows nothing until a line has a phase: phases
 * are optional, and a formula without them behaves as before. The sums are the engine's; this panel only lays them out.
 *
 *   <PhasePanel :check="draft.phases.value" :phase-options="phaseOptions" />
 */
import { computed } from 'vue';
import { Alert, Card } from '@kit';
import Num from '../Num.vue';
import { useI18n } from '../../composables/useI18n';

const props = defineProps({
    check: { type: Object, required: true },
    phaseOptions: { type: Array, required: true },
});

const { t } = useI18n();
const labelOf = (phase) => props.phaseOptions.find((option) => option.value === phase)?.label ?? phase;
const rows = computed(() => props.check.summary.totals.map((entry) => ({ ...entry, label: labelOf(entry.phase) })));
const unphased = computed(() => props.check.summary.unphased);
</script>

<template>
    <Card v-if="check.active" :title="t('formulas.phases.title')" :description="t('formulas.phases.description')" data-testid="phases-card">
        <ul class="flex flex-col divide-y divide-line" data-testid="phase-totals">
            <li v-for="entry in rows" :key="entry.phase" class="flex flex-wrap items-baseline justify-between gap-x-3 py-2" :data-phase="entry.phase">
                <span class="text-ink">{{ entry.label }}</span>
                <span class="flex items-baseline gap-2 text-ink-2">
                    <span class="text-label text-ink-3">{{ t('formulas.phases.lines', { count: entry.lines }) }}</span>
                    <span class="font-semibold text-ink"><Num :value="entry.sum" :decimals="3" /> %</span>
                </span>
            </li>
            <li v-if="unphased.lines > 0" class="flex flex-wrap items-baseline justify-between gap-x-3 py-2" data-phase="none">
                <span class="text-ink-3">{{ t('formulas.phases.unphased') }}</span>
                <span class="flex items-baseline gap-2 text-ink-2">
                    <span class="text-label text-ink-3">{{ t('formulas.phases.lines', { count: unphased.lines }) }}</span>
                    <span class="font-semibold text-ink"><Num :value="unphased.sum" :decimals="3" /> %</span>
                </span>
            </li>
        </ul>
        <Alert v-for="issue in check.formulaIssues" :key="issue.code" tone="warning" icon="ph:warning" class="mt-3" data-testid="phase-issue">
            {{ t(`engine.${issue.messageKey}`) }}
            <template v-if="issue.detail"> (<Num :value="issue.detail.sum" :decimals="3" /> %)</template>
        </Alert>
    </Card>
</template>
