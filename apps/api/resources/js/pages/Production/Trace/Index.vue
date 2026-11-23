<script setup>
/**
 * The recall report (Q-05): type a raw-material lot number and see every batch whose weighing recorded it, with the order, the formula
 * version, the quantity used and the finished-good batch Sanad made for the order (the reference to look the sales up there: sales are Sanad's,
 * nothing about a sale is kept here). The page prints as a document.
 */
import { computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Alert, Badge, Button, Card, DataTable, EmptyState, FormField, Input } from '@kit';
import PreviewShell from '../../../components/print/PreviewShell.vue';
import StatusBadge from '../../../components/StatusBadge.vue';
import { useDateTime } from '../../../composables/useDateTime';
import { useI18n } from '../../../composables/useI18n';

const props = defineProps({
    filters: { type: Object, required: true },
    material: { type: String, default: null },
    searched: { type: Boolean, required: true },
    rows: { type: Array, required: true },
    urls: { type: Object, required: true },
    document: { type: Object, default: null },
});

const { t } = useI18n();
const { when } = useDateTime();
const lot = ref(props.filters.lot);

const columns = computed(() => [
    { key: 'material', label: t('production.trace.columns.material') },
    { key: 'batch', label: t('production.trace.columns.batch') },
    { key: 'order', label: t('production.trace.columns.order'), hideBelow: 'md' },
    { key: 'formula', label: t('production.trace.columns.formula'), hideBelow: 'md' },
    { key: 'quantity', label: t('production.trace.columns.quantity') },
    { key: 'sanad', label: t('production.trace.columns.sanad'), hideBelow: 'lg' },
]);

function search() {
    router.get(props.urls.self, { lot: lot.value, ...(props.filters.material ? { material: props.filters.material } : {}) }, { preserveState: true, replace: true });
}
</script>

<template>
    <Head :title="t('production.trace.title')" />
    <PreviewShell :document="rows.length ? document : null" :title="t('production.trace.title')" :description="t('production.trace.description')" full>

        <div class="flex flex-col gap-4">
            <Card class="print:hidden">
                <form class="flex flex-wrap items-end gap-3" novalidate data-testid="trace-form" @submit.prevent="search">
                    <FormField :label="t('production.trace.lot')" :hint="material ? t('production.trace.material_filter', { material }) : t('production.trace.lot_hint')" class="min-w-64">
                        <Input v-model="lot" ltr maxlength="255" data-testid="trace-lot" />
                    </FormField>
                    <Button type="submit" icon="ph:magnifying-glass" data-testid="trace-search">{{ t('production.trace.search') }}</Button>
                </form>
            </Card>

            <Alert tone="info" icon="ph:info" data-testid="trace-note">{{ t('production.trace.sales_note') }}</Alert>

            <Card v-if="searched" :title="t('production.trace.result_title', { lot: filters.lot })" data-testid="trace-result" :data-count="rows.length">
                <DataTable v-if="rows.length" :columns="columns" :rows="rows" row-key="id" flush :mobile-columns="['material', 'batch', 'quantity']" :title="t('production.trace.title')">
                    <template #cell-material="{ row }"><span class="flex flex-col"><span class="text-ink">{{ row.material }}</span><Badge tone="neutral" mono size="sm">{{ row.lot }}</Badge></span></template>
                    <template #cell-batch="{ row }"><span class="flex flex-wrap items-center gap-2"><a :href="row.batch.href" class="text-primary-ink underline underline-offset-2"><bdi dir="ltr">{{ row.batch.number }}</bdi></a><StatusBadge kind="production_batch_status" :status="row.batch.status" /></span></template>
                    <template #cell-order="{ row }"><a :href="row.order.href" class="text-primary-ink underline underline-offset-2"><bdi dir="ltr">{{ row.order.code }}</bdi></a></template>
                    <template #cell-formula="{ row }"><span><bdi dir="ltr">{{ row.formula.code }}</bdi> · {{ t('formulas.version_short', { version: row.formula.version }) }}</span></template>
                    <template #cell-quantity="{ row }"><span class="flex flex-col"><span class="tnum"><bdi dir="ltr">{{ row.quantity }} {{ t(`production.units.${row.uom}`) }}</bdi></span><span v-if="row.weighed_at" class="text-label text-ink-3">{{ when(row.weighed_at) }}</span></span></template>
                    <template #cell-sanad="{ row }">
                        <span v-if="row.sanad.finished_good_batch" class="flex flex-col"><Badge tone="success" size="sm">{{ t('production.trace.sanad_received') }}</Badge><bdi dir="ltr" class="font-mono text-label text-ink-3">{{ row.sanad.finished_good_batch }}</bdi></span>
                        <Badge v-else-if="row.sanad.status" tone="info" size="sm">{{ t(`production.show.outbox.${row.sanad.status}`) }}</Badge>
                        <span v-else class="text-ink-3">{{ t('production.trace.not_sent') }}</span>
                    </template>
                </DataTable>
                <EmptyState v-else compact icon="ph:path" :title="t('production.trace.none_title')" :description="t('production.trace.none_description')" />
            </Card>
            <EmptyState v-else compact icon="ph:path" :title="t('production.trace.start_title')" :description="t('production.trace.start_description')" />
        </div>
    </PreviewShell>
</template>
