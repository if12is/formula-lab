<script setup>
/**
 * The formulas list: search, product type / tier / latest status / updated-date filters and sort kept in the URL,
 * and a way to start a new formula. A row opens the formula at its latest version.
 */
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import { path } from '../../lib/routes';
import { Badge, Button, DataTable, EmptyState, PageHeader, Pagination } from '@kit';
import ListFilters from '../../components/list/ListFilters.vue';
import StatusBadge from '../../components/StatusBadge.vue';
import { useI18n } from '../../composables/useI18n';
import { useListQuery } from '../../composables/useListQuery';

const props = defineProps({
    list: { type: Object, required: true },
});

const { t } = useI18n();
const query = useListQuery(props.list.schema);

const columns = computed(() => [
    { key: 'code', label: t('formulas.columns.code'), sortable: true },
    { key: 'name', label: t('formulas.columns.name'), sortable: true },
    { key: 'product_type', label: t('formulas.columns.product_type'), hideBelow: 'md' },
    { key: 'tier', label: t('formulas.columns.tier'), hideBelow: 'md' },
    { key: 'latest_status', label: t('formulas.columns.latest_status') },
    { key: 'latest_version', label: t('formulas.columns.latest_version'), hideBelow: 'lg' },
    { key: 'last_activity_at', label: t('formulas.columns.updated'), sortable: true, hideBelow: 'lg' },
]);

const filtered = computed(() => query.activeCount.value > 0);
</script>

<template>
    <Head :title="t('formulas.title')" />
    <div class="kit-page is-full">
        <PageHeader :title="t('formulas.title')" :description="t('formulas.description')">
            <template #actions>
                <Button :href="path('formulas.create')" icon="ph:plus">{{ t('formulas.new') }}</Button>
            </template>
        </PageHeader>

        <div class="flex flex-col gap-4">
            <ListFilters :query="query" />

            <DataTable
                :columns="columns"
                :rows="list.rows"
                row-key="id"
                :row-href="(row) => row.href"
                :mobile-columns="['code', 'latest_status', 'name']"
                v-model:sort="query.sort.value"
                :busy="query.busy.value"
                :title="t('formulas.title')"
                :count="list.meta.total"
            >
                <template #cell-code="{ row }"><bdi dir="ltr" class="font-semibold text-ink">{{ row.code }}</bdi></template>
                <template #cell-tier="{ row }">
                    <Badge v-if="row.tier" tone="neutral">{{ t(`enums.product_tier.${row.tier}`) }}</Badge>
                    <span v-else class="text-ink-3">{{ t('common.no_data') }}</span>
                </template>
                <template #cell-latest_status="{ row }">
                    <StatusBadge v-if="row.latest_status" kind="formula_status" :status="row.latest_status" />
                    <span v-else class="text-ink-3">{{ t('common.no_data') }}</span>
                </template>
                <template #cell-latest_version="{ row }">
                    <Badge v-if="row.latest_version" tone="neutral" mono>{{ t('formulas.version_short', { version: row.latest_version }) }}</Badge>
                </template>
                <template #cell-last_activity_at="{ row }"><bdi dir="ltr" class="tnum">{{ row.last_activity_at }}</bdi></template>
                <template #empty>
                    <EmptyState
                        compact
                        :icon="filtered ? 'ph:funnel-x' : 'ph:flask'"
                        :title="filtered ? t('filters.filtered_empty_title') : t('formulas.empty_title')"
                        :description="filtered ? t('filters.filtered_empty_description') : t('formulas.empty_description')"
                    >
                        <template #actions>
                            <Button v-if="filtered" variant="secondary" icon="ph:funnel-x" @click="query.clear()">{{ t('filters.clear_all') }}</Button>
                            <Button v-else :href="path('formulas.create')" icon="ph:plus">{{ t('formulas.new') }}</Button>
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
