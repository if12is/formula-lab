<script setup>
/**
 * The salt curve of one formula (O-03): viscosity against added salt %, from the lab readings recorded on its trials and
 * nothing else (no generic curve). Below the chart, the engine reads the curve for a salt % or a wanted viscosity: it
 * interpolates between the two neighbouring measured points, never extrapolates, and says what is missing instead of a number.
 */
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { ApexChart, Badge, Button, Card, DataTable, EmptyState, PageHeader, SegmentedControl } from '@kit';
import EngineMissing from '../../components/explain/EngineMissing.vue';
import EngineWarnings from '../../components/explain/EngineWarnings.vue';
import ExplainableNum from '../../components/explain/ExplainableNum.vue';
import DecimalField from '../../components/form/DecimalField.vue';
import ConfidenceBadge from '../../components/materials/ConfidenceBadge.vue';
import Num from '../../components/Num.vue';
import { useI18n } from '../../composables/useI18n';
import { chartSeries, MODES, runSaltCurve } from '../../lib/lab/saltCurve';
import { path } from '../../lib/routes';

const props = defineProps({
    formula: { type: Object, required: true },
    /** [{ id, salt_pct, viscosity, unit, source_id, confidence, measured_at, trial, trial_href, version, instrument }], ascending by salt %. */
    points: { type: Array, required: true },
    /** The curve as the engine takes it. */
    curve: { type: Object, required: true },
    /** The explain panel's source map: engine source id -> { title, href }. */
    sources: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const { t } = useI18n();
const mode = ref(MODES[0]);
const text = ref('');
const outcome = computed(() => runSaltCurve(props.curve, mode.value, text.value));
const modeOptions = computed(() => MODES.map((value) => ({ value, label: t(`lab.salt_curve.modes.${value}`) })));
const range = computed(() => (props.points.length >= 2 ? { from: props.points[0].salt_pct, to: props.points[props.points.length - 1].salt_pct } : null));

const columns = computed(() => [
    { key: 'salt_pct', label: t('lab.salt_curve.columns.salt') },
    { key: 'viscosity', label: t('lab.salt_curve.columns.viscosity') },
    { key: 'version', label: t('lab.salt_curve.columns.version'), hideBelow: 'sm' },
    { key: 'trial', label: t('lab.salt_curve.columns.trial'), hideBelow: 'md' },
    { key: 'instrument', label: t('lab.salt_curve.columns.instrument'), hideBelow: 'lg' },
    { key: 'confidence', label: t('lab.salt_curve.columns.confidence'), hideBelow: 'md' },
]);

const series = computed(() => {
    const { measured, query } = chartSeries(props.points, outcome.value, mode.value, text.value);

    return [
        { name: t('lab.salt_curve.series_measured'), type: 'line', data: measured },
        ...(query.length ? [{ name: t('lab.salt_curve.series_query'), type: 'scatter', data: query }] : []),
    ];
});
const chartOptions = computed(() => ({
    xaxis: { type: 'numeric', decimalsInFloat: 2, title: { text: t('lab.salt_curve.columns.salt') } },
    yaxis: { title: { text: t('lab.salt_curve.columns.viscosity') } },
    stroke: { width: [2, 0] },
    markers: { size: [5, 8] },
}));

const resultLabel = (result) => (result.many ? t('lab.salt_curve.result_salt_n', result.params) : t(result.key.startsWith('salt') ? 'lab.salt_curve.result_salt' : 'lab.salt_curve.result_viscosity'));
const bracketOf = (result) => (result.bracket ? t('lab.salt_curve.bracket', { from: result.bracket.from.saltPct, to: result.bracket.to.saltPct }) : null);
</script>

<template>
    <Head :title="t('lab.salt_curve.title')" />
    <div class="kit-page">
        <PageHeader :title="`${t('lab.salt_curve.title')} · ${formula.code}`" :description="t('lab.salt_curve.description')" :back="path('formulas.show', { formula: formula.id })" :back-label="formula.name">
            <template #actions>
                <Button variant="ghost" icon="ph:flask" :href="urls.trials">{{ t('lab.salt_curve.trials') }}</Button>
                <Button icon="ph:plus" :href="urls.newTrial" data-testid="salt-new-trial">{{ t('lab.salt_curve.new_trial') }}</Button>
            </template>
        </PageHeader>

        <div class="flex flex-col gap-4" data-testid="salt-curve" :data-points="points.length">
            <EmptyState v-if="points.length === 0" variant="dashed" icon="ph:chart-line" :title="t('lab.salt_curve.empty_title')" :description="t('lab.salt_curve.empty_description')" data-testid="salt-empty" />

            <div v-else class="flex flex-col gap-4">
                <Card :title="t('lab.salt_curve.chart_title')" :description="t('lab.salt_curve.chart_description')">
                    <ApexChart type="line" :series="series" :options="chartOptions" :aria-label="t('lab.salt_curve.chart_label')" data-testid="salt-chart" />
                </Card>
                <Card :title="t('lab.salt_curve.points_title')" :description="t('lab.salt_curve.points_description')">
                    <DataTable :columns="columns" :rows="points" row-key="id" flush :mobile-columns="['salt_pct', 'viscosity']" :title="t('lab.salt_curve.points_title')">
                        <template #cell-salt_pct="{ row }"><span class="tnum" :data-salt-point="row.salt_pct"><Num :value="row.salt_pct" /> <span class="text-ink-3">{{ t('calculators.units.%w/w') }}</span></span></template>
                        <template #cell-viscosity="{ row }"><span class="tnum"><Num :value="row.viscosity" /> <span class="text-ink-3">{{ row.unit }}</span></span></template>
                        <template #cell-version="{ row }"><Badge tone="neutral" mono>{{ t('formulas.version_short', { version: row.version }) }}</Badge></template>
                        <template #cell-trial="{ row }"><a :href="row.trial_href" class="text-primary-ink underline underline-offset-2"><bdi dir="ltr">{{ row.trial }}</bdi></a></template>
                        <template #cell-instrument="{ row }">{{ row.instrument }}</template>
                        <template #cell-confidence="{ row }"><ConfidenceBadge :confidence="row.confidence" /></template>
                    </DataTable>
                </Card>
            </div>

            <Card :title="t('lab.salt_curve.use_title')" :description="t('lab.salt_curve.use_description')" data-testid="salt-use">
                <div class="grid gap-4 lg:grid-cols-2 lg:items-start">
                    <div class="flex flex-col gap-4">
                        <SegmentedControl v-model="mode" :options="modeOptions" :aria-label="t('lab.salt_curve.mode')" block />
                        <DecimalField
                            v-model="text"
                            :label-key="mode === 'viscosity' ? 'lab.salt_curve.salt_input' : 'lab.salt_curve.viscosity_input'"
                            :hint-key="mode === 'viscosity' ? 'lab.salt_curve.salt_hint' : 'lab.salt_curve.viscosity_hint'"
                            :suffix="mode === 'viscosity' ? t('calculators.units.%w/w') : t('calculators.units.mPa·s')"
                            data-testid="salt-query"
                        />
                        <p v-if="range" class="text-label text-ink-3" data-testid="salt-range">{{ t('lab.salt_curve.range', range) }}</p>
                    </div>
                    <div class="flex flex-col gap-3" data-testid="salt-result" :data-state="outcome.state">
                        <p v-if="outcome.state === 'empty'" class="text-label text-ink-3">{{ t('lab.salt_curve.empty_query') }}</p>
                        <EngineMissing v-else-if="outcome.state === 'missing'" :missing="outcome.missing" />
                        <ul v-else class="flex flex-col">
                            <li v-for="result in outcome.results" :key="result.key" class="flex flex-col gap-1 border-b border-line py-2 last:border-b-0" :data-result="result.key">
                                <span class="flex flex-wrap items-baseline justify-between gap-x-4">
                                    <span class="font-semibold text-ink">{{ resultLabel(result) }}</span>
                                    <span class="type-metric text-ink">
                                        <ExplainableNum :traced="result.traced" :decimals="result.decimals" :label="resultLabel(result)" :sources="sources" />
                                        <span class="text-label font-normal text-ink-3">{{ t(`calculators.units.${result.traced.unit}`) }}<template v-if="result.to"> {{ t('lab.salt_curve.flat_to', { to: result.to }) }}</template></span>
                                    </span>
                                </span>
                                <span v-if="bracketOf(result)" class="text-label text-ink-3">{{ bracketOf(result) }}</span>
                            </li>
                        </ul>
                        <EngineWarnings :warnings="outcome.warnings" />
                    </div>
                </div>
            </Card>
        </div>
    </div>
</template>
