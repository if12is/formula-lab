<script setup>
/**
 * The computed value of a trial's formula against what the lab measured (O-05): the expected active matter (the engine
 * works it out from the version's lines and each material's registered active matter), the measured reading (MBAS or
 * titration), and the absolute and relative difference. It decides nothing: no acceptance limit exists, so the
 * difference is shown, never judged. A missing side is listed by the engine; a zero or an empty box is never shown for it.
 *
 *   <ExpectedMeasuredPanel :comparison="comparison" />
 */
import { computed } from 'vue';
import { Alert, Card, EmptyState } from '@kit';
import { useI18n } from '../../composables/useI18n';
import { runComparison } from '../../lib/lab/comparison';
import { path } from '../../lib/routes';
import EngineMissing from '../explain/EngineMissing.vue';
import EngineWarnings from '../explain/EngineWarnings.vue';
import ExplainableNum from '../explain/ExplainableNum.vue';

const props = defineProps({
    /** { property, lines, measured } from App\Services\Lab\LabComparisonService. */
    comparison: { type: Object, required: true },
});

const { t } = useI18n();
const outcome = computed(() => runComparison(props.comparison));
const hasMeasured = computed(() => props.comparison.measured !== null);

// The explain panel names where each figure came from: the registered values and the lab reading.
const sources = computed(() => ({
    ...Object.fromEntries(props.comparison.lines.flatMap((line) => line.properties.map((row) => [row.source_id, { title: row.source_title, href: path('materials.show', { material: line.material_id, tab: 'properties' }) }]))),
    ...(props.comparison.measured ? { [props.comparison.measured.sourceId]: { title: props.comparison.measured.sourceTitle, href: null } } : {}),
}));
const entities = computed(() => Object.fromEntries(props.comparison.lines.map((line) => [line.label, { href: path('materials.show', { material: line.material_id, tab: 'properties' }) }])));
const unitOf = (traced) => (traced.unit === '%' ? '%' : t(`calculators.units.${traced.unit}`));
</script>

<template>
    <Card :title="t('lab.comparison.title')" :description="t('lab.comparison.description')" data-testid="comparison" :data-state="outcome.state">
        <div class="flex flex-col gap-3">
            <EmptyState v-if="!hasMeasured && outcome.state === 'missing' && outcome.missing.length === 1" variant="dashed" icon="ph:hourglass" :title="t('lab.comparison.no_measured_title')" :description="t('lab.comparison.no_measured_description')" data-testid="comparison-no-measured" />
            <EngineMissing v-else-if="outcome.state === 'missing'" :missing="outcome.missing" />
            <template v-else>
                <ul class="flex flex-col" data-testid="comparison-rows">
                    <li v-for="row in outcome.rows" :key="row.key" class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 border-b border-line py-2 last:border-b-0" :data-result="row.key">
                        <span :class="row.primary ? 'font-semibold text-ink' : 'text-ink-2'">{{ t(`lab.comparison.${row.key}`) }}</span>
                        <span :class="row.primary ? 'type-metric text-ink' : 'text-ink'">
                            <ExplainableNum :traced="row.traced" :decimals="row.decimals" :label="t(`lab.comparison.${row.key}`)" :entities="entities" :sources="sources" />
                            <span class="text-label font-normal text-ink-3">{{ unitOf(row.traced) }}</span>
                        </span>
                    </li>
                </ul>
                <p v-if="comparison.measured?.method" class="text-label text-ink-3">{{ t('lab.comparison.method') }}: {{ comparison.measured.method }}</p>
                <Alert tone="info" icon="ph:scales" data-testid="comparison-no-verdict">{{ t('lab.comparison.no_verdict') }}</Alert>
            </template>
            <p class="text-label text-ink-3">{{ t('lab.comparison.lines_hint') }}</p>
            <EngineWarnings :warnings="outcome.warnings" />
        </div>
    </Card>
</template>
