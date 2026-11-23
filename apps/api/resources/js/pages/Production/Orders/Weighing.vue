<script setup>
/**
 * The operator's weighing sheet (Q-02): the ingredients in the order they are added, each with the weight to put on the balance (the
 * exact mass rounded once to the balance resolution), the phase and temperature, and the process steps. It holds WEIGHTS ONLY: no price,
 * no cost, for any role. The page prints as a document (the kit hides the shell) with the hash of the approved version it came from.
 */
import { Head } from '@inertiajs/vue3';
import { Alert, Badge, Button, Card, DataTable, DescriptionList } from '@kit';
import { computed } from 'vue';
import PreviewShell from '../../../components/print/PreviewShell.vue';
import ProcessStepsList from '../../../components/production/ProcessStepsList.vue';
import Num from '../../../components/Num.vue';
import { useI18n } from '../../../composables/useI18n';

const props = defineProps({
    order: { type: Object, required: true },
    sheet: { type: Object, required: true },
    steps: { type: Array, required: true },
    materials: { type: Object, required: true },
    urls: { type: Object, required: true },
    document: { type: Object, required: true },
});

const { t } = useI18n();

const facts = computed(() => [
    { term: t('production.show.formula'), value: `${props.order.formula_code} · ${props.order.formula_name} · ${t('formulas.version_short', { version: props.order.version })}` },
    { term: t('production.show.batch_number'), value: props.order.batch_number, mono: true },
    { term: t('production.show.batch_size'), value: `${props.order.batch_size} ${props.order.batch_unit}`, mono: true },
    { term: t('production.show.planned_on'), value: props.order.planned_on, mono: true },
    ...(props.sheet.ok ? [{ term: t('production.show.resolution'), value: `${props.sheet.sheet.resolutionG} ${t('production.units.g')}`, mono: true }] : []),
]);

const columns = computed(() => [
    { key: 'order', label: '#' },
    { key: 'material', label: t('production.show.columns.material') },
    { key: 'phase', label: t('production.show.columns.phase') },
    { key: 'weigh', label: t('production.weighing.weigh') },
    { key: 'temp', label: t('production.show.columns.temp'), hideBelow: 'md' },
    { key: 'done', label: t('production.weighing.done'), hideBelow: 'md' },
]);
</script>

<template>
    <Head :title="t('production.weighing.title', { order: order.code })" />
    <PreviewShell :document="document" :title="t('production.weighing.title', { order: order.code })" :description="t('production.weighing.description')">
        <template #actions>
            <Button variant="ghost" :href="urls.order" icon="ph:arrow-right">{{ t('production.show.back_to_order') }}</Button>
        </template>

        <div class="flex flex-col gap-4">
            <Card><DescriptionList :items="facts" :columns="3" /></Card>

            <Alert v-if="!sheet.ok" tone="danger" icon="ph:warning" :title="t('production.show.sheet_blocked')">
                <ul class="list-disc ps-5"><li v-for="reason in sheet.missing" :key="reason">{{ reason }}</li></ul>
            </Alert>
            <Card v-else :title="t('production.weighing.sheet')" data-testid="weighing-sheet" :data-hash="sheet.sheet.sheetHash">
                <DataTable :columns="columns" :rows="sheet.sheet.lines" row-key="lineNo" flush :mobile-columns="['order', 'material', 'weigh']" :title="t('production.weighing.sheet')">
                    <template #cell-order="{ row }"><Badge tone="neutral" mono>{{ row.order }}</Badge></template>
                    <template #cell-phase="{ row }"><span v-if="row.phase">{{ t(`enums.formula_phase.${row.phase}`) }}</span><span v-else class="text-ink-3">{{ t('common.no_data') }}</span></template>
                    <template #cell-weigh="{ row }"><span class="tnum type-h5 text-ink" :data-weigh="row.lineNo"><bdi dir="ltr">{{ row.weighG }} {{ t('production.units.g') }}</bdi></span></template>
                    <template #cell-temp="{ row }"><Num :value="row.additionTempC"><template #suffix> °C</template></Num></template>
                    <template #cell-done><span class="inline-block h-5 w-5 rounded border border-line" aria-hidden="true" /></template>
                </DataTable>
                <p class="mt-3 text-label text-ink-2" data-testid="sheet-total">
                    {{ t('production.show.sheet_total') }}: <bdi dir="ltr" class="tnum">{{ sheet.sheet.totalWeighG }} {{ t('production.units.g') }}</bdi>
                    <template v-if="sheet.sheet.rounding.hasResidual"> · {{ t('production.show.sheet_residual') }}: <bdi dir="ltr" class="tnum">{{ sheet.sheet.rounding.residual }} {{ t('production.units.g') }}</bdi></template>
                </p>
                <Alert v-if="sheet.sheet.belowResolution.length" tone="warning" icon="ph:warning" class="mt-3" data-testid="below-resolution">{{ t('production.weighing.below_resolution', { lines: sheet.sheet.belowResolution.join(', ') }) }}</Alert>
                <p class="mt-2 break-all text-label text-ink-3">{{ t('production.weighing.sheet_hash') }}: <bdi dir="ltr" class="font-mono">{{ sheet.sheet.sheetHash }}</bdi></p>
            </Card>

            <Card v-if="steps.length" :title="t('production.show.steps_title')">
                <ProcessStepsList :steps="steps" :materials="materials" />
            </Card>
        </div>
    </PreviewShell>
</template>
