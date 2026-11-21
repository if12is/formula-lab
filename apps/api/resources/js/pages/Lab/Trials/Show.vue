<script setup>
/**
 * One lab trial: the batch facts, every measurable property with the value that counts (or a clear "Not measured": a
 * property nobody measured is never shown as zero), the readings it replaced, how far the version's required measurements
 * are covered, and, while the trial is open, the form to record a measurement and the action that completes (closes) it.
 */
import { computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Alert, Badge, Button, Card, DataTable, DescriptionList, PageHeader, useConfirm } from '@kit';
import ConfidenceBadge from '../../../components/materials/ConfidenceBadge.vue';
import CoveragePanel from '../../../components/lab/CoveragePanel.vue';
import LimitText from '../../../components/lab/LimitText.vue';
import MeasurementForm from '../../../components/lab/MeasurementForm.vue';
import StatusBadge from '../../../components/StatusBadge.vue';
import { useDateTime } from '../../../composables/useDateTime';
import { useI18n } from '../../../composables/useI18n';

const props = defineProps({
    trial: { type: Object, required: true },
    formula: { type: Object, required: true },
    properties: { type: Array, required: true },
    coverage: { type: Object, required: true },
    form: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const { t } = useI18n();
const { when } = useDateTime();
const { confirm } = useConfirm();

const facts = computed(() => [
    { term: t('lab.show.formula'), key: 'formula' },
    { term: t('lab.show.batch'), value: `${props.trial.batch_size} ${props.trial.batch_unit}`, mono: true },
    { term: t('lab.show.trial_date'), value: props.trial.trial_date, mono: true },
    { term: t('lab.show.operator'), value: props.trial.operator },
    { term: t('lab.show.notes'), value: props.trial.notes ?? t('common.no_data') },
    ...(props.trial.completed_at ? [{ term: t('lab.show.completed_at'), value: when(props.trial.completed_at) }] : []),
]);

const columns = computed(() => [
    { key: 'property', label: t('lab.columns.property') },
    { key: 'value', label: t('lab.columns.value') },
    { key: 'method', label: t('lab.columns.method'), hideBelow: 'md' },
    { key: 'instrument', label: t('lab.columns.instrument'), hideBelow: 'lg' },
    { key: 'measured_at', label: t('lab.columns.measured_at'), hideBelow: 'lg' },
    { key: 'confidence', label: t('lab.columns.confidence'), hideBelow: 'md' },
    { key: 'limits', label: t('lab.columns.limits'), hideBelow: 'lg' },
]);

const earlier = computed(() => props.properties.flatMap((row) => row.earlier.map((reading) => ({ ...reading, property: row.property }))));
const earlierColumns = computed(() => [
    { key: 'property', label: t('lab.columns.property') },
    { key: 'value', label: t('lab.columns.value') },
    { key: 'method', label: t('lab.columns.method'), hideBelow: 'md' },
    { key: 'instrument', label: t('lab.columns.instrument'), hideBelow: 'md' },
    { key: 'measured_at', label: t('lab.columns.measured_at'), hideBelow: 'sm' },
]);
const measuredCount = computed(() => props.properties.filter((row) => row.latest).length);

async function complete() {
    const confirmed = await confirm({
        title: t('lab.show.complete_title'),
        description: t('lab.show.complete_description'),
        confirmLabel: t('lab.show.complete'),
    });

    if (confirmed) {
        router.post(props.urls.complete, {}, { preserveScroll: true });
    }
}
</script>

<template>
    <Head :title="trial.code" />
    <div class="kit-page">
        <PageHeader :title="trial.code" :description="t('lab.show.facts')">
            <template #actions>
                <StatusBadge kind="lab_trial_status" :status="trial.status" />
                <Button variant="ghost" :href="urls.index" icon="ph:arrow-right">{{ t('lab.show.back') }}</Button>
                <Button v-if="trial.open" icon="ph:check-circle" :disabled="measuredCount === 0" data-testid="complete-trial" @click="complete">{{ t('lab.show.complete') }}</Button>
            </template>
        </PageHeader>

        <div class="flex flex-col gap-4">
            <Card>
                <DescriptionList :items="facts" :columns="3">
                    <template #formula>
                        <a :href="formula.editorHref" class="text-primary-ink underline underline-offset-2" data-testid="formula-link">
                            <bdi dir="ltr">{{ formula.code }}</bdi> · {{ formula.name }}
                        </a>
                        <span class="ms-2 inline-flex items-center gap-2">
                            <Badge tone="neutral" mono>{{ t('formulas.version_short', { version: formula.version }) }}</Badge>
                            <StatusBadge kind="formula_status" :status="formula.status" />
                        </span>
                    </template>
                </DescriptionList>
            </Card>

            <Alert v-if="!trial.open" tone="info" icon="ph:lock-simple" :title="t('lab.show.closed_title')">{{ t('lab.show.closed_description') }}</Alert>
            <Alert v-else-if="measuredCount === 0" tone="info" icon="ph:info">{{ t('lab.show.complete_needs_measurement') }}</Alert>

            <Card :title="t('lab.show.measurements_title')" :description="t('lab.show.measurements_description')">
                <DataTable :columns="columns" :rows="properties" row-key="property" flush :mobile-columns="['property', 'value']" :title="t('lab.show.measurements_title')">
                    <template #cell-property="{ row }">
                        <span class="flex flex-col gap-1" :data-property-row="row.property">
                            <span class="font-semibold text-ink">{{ t(`enums.lab_property.${row.property}`) }}</span>
                            <Badge :tone="row.required ? 'info' : 'neutral'" size="sm">{{ row.required ? t('lab.measurement.required') : t('lab.measurement.optional') }}</Badge>
                        </span>
                    </template>
                    <template #cell-value="{ row }">
                        <span v-if="row.latest" class="tnum text-ink" :data-value="row.property"><bdi dir="ltr">{{ row.latest.value }} {{ row.latest.unit }}</bdi></span>
                        <Badge v-else tone="warning" icon="ph:hourglass" :data-value="row.property" data-missing="true">{{ t('lab.measurement.not_measured') }}</Badge>
                    </template>
                    <template #cell-method="{ row }">
                        <a v-if="row.latest?.protocol" :href="row.latest.protocol_href" class="text-primary-ink underline underline-offset-2">{{ row.latest.protocol }}</a>
                        <span v-else-if="row.latest?.method">{{ row.latest.method }}</span>
                        <span v-else class="text-ink-3">{{ t('common.no_data') }}</span>
                    </template>
                    <template #cell-instrument="{ row }"><span v-if="row.latest">{{ row.latest.instrument }}</span><span v-else class="text-ink-3">{{ t('common.no_data') }}</span></template>
                    <template #cell-measured_at="{ row }"><span v-if="row.latest" class="whitespace-nowrap tnum">{{ when(row.latest.measured_at) }}</span><span v-else class="text-ink-3">{{ t('common.no_data') }}</span></template>
                    <template #cell-confidence="{ row }"><ConfidenceBadge v-if="row.latest" :confidence="row.latest.confidence" /><span v-else class="text-ink-3">{{ t('common.no_data') }}</span></template>
                    <template #cell-limits="{ row }"><LimitText :limits="row.limits" /></template>
                </DataTable>
            </Card>

            <Card v-if="earlier.length" :title="t('lab.show.earlier_title')" :description="t('lab.show.earlier_description')" data-testid="earlier-readings">
                <DataTable :columns="earlierColumns" :rows="earlier" row-key="id" flush :mobile-columns="['property', 'value']" :title="t('lab.show.earlier_title')">
                    <template #cell-property="{ row }">{{ t(`enums.lab_property.${row.property}`) }} <Badge tone="neutral" size="sm">{{ t('lab.measurement.superseded') }}</Badge></template>
                    <template #cell-value="{ row }"><span class="tnum"><bdi dir="ltr">{{ row.value }} {{ row.unit }}</bdi></span></template>
                    <template #cell-method="{ row }">{{ row.protocol ?? row.method ?? t('common.no_data') }}</template>
                    <template #cell-measured_at="{ row }"><span class="whitespace-nowrap tnum">{{ when(row.measured_at) }}</span></template>
                </DataTable>
            </Card>

            <Card :title="t('lab.coverage.title')" :description="t('lab.coverage.description')">
                <CoveragePanel :coverage="coverage" />
            </Card>

            <MeasurementForm v-if="trial.open" :choices="form" :url="urls.measurements" />
        </div>
    </div>
</template>
