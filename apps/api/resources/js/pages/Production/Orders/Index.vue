<script setup>
/**
 * The production orders list: search, formula / status / batch / date filters and sort kept in the URL, and a way to open a new order.
 * A row opens the order with its snapshot, weighing sheet and batch.
 */
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import { path } from '../../../lib/routes';
import { Badge, Button, DataTable, EmptyState, PageHeader, Pagination } from '@kit';
import ListFilters from '../../../components/list/ListFilters.vue';
import StatusBadge from '../../../components/StatusBadge.vue';
import { useI18n } from '../../../composables/useI18n';
import { useListQuery } from '../../../composables/useListQuery';

const props = defineProps({
    list: { type: Object, required: true },
});

const { t } = useI18n();
const query = useListQuery(props.list.schema);

const columns = computed(() => [
    { key: 'code', label: t('production.orders.columns.code'), sortable: true },
    { key: 'formula', label: t('production.orders.columns.formula'), sortable: true },
    { key: 'status', label: t('production.orders.columns.status'), sortable: true },
    { key: 'planned', label: t('production.orders.columns.planned'), sortable: true, hideBelow: 'md' },
    { key: 'size', label: t('production.orders.columns.size'), sortable: true, hideBelow: 'md' },
    { key: 'batch_number', label: t('production.orders.columns.batch_number'), hideBelow: 'lg' },
    { key: 'batch_state', label: t('production.orders.columns.batch_state'), hideBelow: 'lg' },
]);

const filtered = computed(() => query.activeCount.value > 0);
</script>

<template>
    <Head :title="t('production.orders.title')" />
    <div class="kit-page is-full">
        <PageHeader :title="t('production.orders.title')" :description="t('production.orders.description')">
            <template #actions>
                <Button variant="ghost" :href="path('production.trace')" icon="ph:path" data-testid="open-trace">{{ t('production.trace.open') }}</Button>
                <Button :href="path('production.orders.create')" icon="ph:plus" data-testid="new-order">{{ t('production.orders.new') }}</Button>
            </template>
        </PageHeader>

        <div class="flex flex-col gap-4">
            <ListFilters :query="query" />

            <DataTable
                :columns="columns"
                :rows="list.rows"
                row-key="id"
                :row-href="(row) => row.href"
                :mobile-columns="['code', 'status', 'formula']"
                v-model:sort="query.sort.value"
                :busy="query.busy.value"
                :title="t('production.orders.title')"
                :count="list.meta.total"
            >
                <template #cell-code="{ row }"><bdi dir="ltr" class="font-semibold text-ink">{{ row.code }}</bdi></template>
                <template #cell-formula="{ row }">
                    <span class="flex flex-col">
                        <span class="flex flex-wrap items-center gap-2"><bdi dir="ltr" class="text-ink">{{ row.formula_code }}</bdi><Badge tone="neutral" mono>{{ t('formulas.version_short', { version: row.version }) }}</Badge></span>
                        <span class="text-label text-ink-3">{{ row.formula_name }}</span>
                    </span>
                </template>
                <template #cell-status="{ row }"><StatusBadge kind="production_order_status" :status="row.status" /></template>
                <template #cell-planned="{ row }"><bdi dir="ltr" class="tnum">{{ row.planned_on }}</bdi></template>
                <template #cell-size="{ row }"><bdi dir="ltr" class="tnum">{{ row.batch_size }} {{ row.batch_unit }}</bdi></template>
                <template #cell-batch_number="{ row }"><bdi dir="ltr" class="tnum">{{ row.batch_number }}</bdi></template>
                <template #cell-batch_state="{ row }">
                    <StatusBadge v-if="row.batch_state !== 'none'" kind="production_batch_status" :status="row.batch_state" />
                    <span v-else class="text-ink-3">{{ t('production.orders.batch_state.none') }}</span>
                </template>
                <template #empty>
                    <EmptyState
                        compact
                        :icon="filtered ? 'ph:funnel-x' : 'ph:factory'"
                        :title="filtered ? t('filters.filtered_empty_title') : t('production.orders.empty_title')"
                        :description="filtered ? t('filters.filtered_empty_description') : t('production.orders.empty_description')"
                    >
                        <template #actions>
                            <Button v-if="filtered" variant="secondary" icon="ph:funnel-x" @click="query.clear()">{{ t('filters.clear_all') }}</Button>
                            <Button v-else :href="path('production.orders.create')" icon="ph:plus">{{ t('production.orders.new') }}</Button>
                        </template>
                    </EmptyState>
                </template>
            </DataTable>

            <Pagination
                v-model:page="query.page.value"
                v-model:per-page="query.per.value"
                :total="list.meta.total"
                :per-page-options="list.schema.perPageOptions"
            />
        </div>
    </div>
</template>
