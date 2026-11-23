<script setup>
/**
 * The EDA notification files (N-08): every product whose family EDA regulates, with how ready its file is. The readiness is the engine's
 * answer for each product; this page lists it with search, family and group filters and sort kept in the URL. A product with no sourced
 * document list reads "document list not defined", never "ready".
 */
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import { Badge, DataTable, EmptyState, PageHeader, Pagination, StatCard } from '@kit';
import ListFilters from '../../components/list/ListFilters.vue';
import StatusBadge from '../../components/StatusBadge.vue';
import { useI18n } from '../../composables/useI18n';
import { useListQuery } from '../../composables/useListQuery';

const props = defineProps({
    list: { type: Object, required: true },
    summary: { type: Object, required: true },
});

const { t } = useI18n();
const query = useListQuery(props.list.schema);

const columns = computed(() => [
    { key: 'code', label: t('eda.columns.code'), sortable: true },
    { key: 'name', label: t('eda.columns.name'), sortable: true },
    { key: 'family', label: t('eda.columns.family'), hideBelow: 'md' },
    { key: 'state', label: t('eda.columns.readiness') },
    { key: 'counts', label: t('eda.columns.progress'), hideBelow: 'lg' },
]);

const states = ['ready', 'incomplete', 'items_not_sourced'];
const filtered = computed(() => query.activeCount.value > 0);
</script>

<template>
    <Head :title="t('eda.title')" />
    <div class="kit-page is-full" data-testid="eda-index">
        <PageHeader :title="t('eda.title')" :description="t('eda.description')" />

        <div class="flex flex-col gap-4">
            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" :aria-label="t('eda.summary.title')" data-testid="eda-summary">
                <StatCard :label="t('eda.summary.total')" :value="String(summary.total)" icon="ph:file-text" :caption="t('eda.summary.description')" data-kpi="total" />
                <StatCard v-for="state in states" :key="state" :label="t(`enums.eda_readiness.${state}`)" :value="String(summary.states[state] ?? 0)" :alert="state === 'items_not_sourced' && (summary.states[state] ?? 0) > 0" :data-kpi="state" />
            </section>

            <ListFilters :query="query" />

            <DataTable
                :columns="columns"
                :rows="list.rows"
                row-key="id"
                :row-href="(row) => row.href"
                :mobile-columns="['code', 'state', 'name']"
                v-model:sort="query.sort.value"
                :busy="query.busy.value"
                :title="t('eda.title')"
                :count="list.meta.total"
            >
                <template #cell-code="{ row }"><bdi dir="ltr" class="font-semibold text-ink">{{ row.code }}</bdi></template>
                <template #cell-state="{ row }"><StatusBadge kind="eda_readiness" :status="row.state" /></template>
                <template #cell-counts="{ row }">
                    <Badge v-if="row.counts" tone="neutral" mono><bdi dir="ltr">{{ row.counts.attached }}/{{ row.counts.total }}</bdi></Badge>
                    <span v-else class="text-ink-3">{{ t('common.no_data') }}</span>
                </template>
                <template #empty>
                    <EmptyState
                        compact
                        :icon="filtered ? 'ph:funnel-x' : 'ph:file-text'"
                        :title="filtered ? t('filters.filtered_empty_title') : t('eda.empty_title')"
                        :description="filtered ? t('filters.filtered_empty_description') : t('eda.empty_description')"
                    />
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
