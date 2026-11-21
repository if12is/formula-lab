<script setup>
/**
 * Test-only playground (route exists in local and testing only): proves ListFilters, useListQuery,
 * ListQuery and EntitySelect together on factory-seeded trade materials. Playwright drives it.
 * Real index pages copy this shape: PageHeader, ListFilters, DataTable, Pagination, two empty states.
 */
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { Badge, Card, DataTable, EmptyState, PageHeader, Pagination } from '@kit';
import EntitySelect from '../../components/form/EntitySelect.vue';
import ListFilters from '../../components/list/ListFilters.vue';
import { useI18n } from '../../composables/useI18n';
import { useListQuery } from '../../composables/useListQuery';

const props = defineProps({
    list: { type: Object, required: true },
    materialOptions: { type: Array, required: true },
    searchUrl: { type: String, required: true },
});

const { t } = useI18n();
const query = useListQuery(props.list.schema);

const localChoice = ref(null);
const remoteChoice = ref(null);

const columns = computed(() => [
    { key: 'trade_name', label: t('dev.list_demo.columns.trade_name'), sortable: true },
    { key: 'grade', label: t('dev.list_demo.columns.grade'), sortable: true, hideBelow: 'md' },
    { key: 'function', label: t('dev.list_demo.columns.function'), hideBelow: 'md' },
    { key: 'status', label: t('dev.list_demo.columns.status'), sortable: true },
    { key: 'is_mixture', label: t('dev.list_demo.columns.is_mixture'), hideBelow: 'lg' },
    { key: 'created_at', label: t('dev.list_demo.columns.created_at'), sortable: true, hideBelow: 'lg' },
]);

const filtered = computed(() => query.activeCount.value > 0);
</script>

<template>
    <Head :title="t('dev.list_demo.title')" />
    <div class="kit-page is-full">
        <PageHeader :title="t('dev.list_demo.title')" :description="t('dev.list_demo.description')" />

        <div class="flex flex-col gap-4">
            <ListFilters :query="query" />

            <DataTable
                :columns="columns"
                :rows="list.rows"
                row-key="id"
                :mobile-columns="['trade_name', 'status', 'grade']"
                v-model:sort="query.sort.value"
                :busy="query.busy.value"
                :title="t('dev.list_demo.title')"
                :count="list.meta.total"
            >
                <template #cell-status="{ row }">
                    <Badge :tone="row.status === 'active' ? 'success' : 'neutral'" dot>{{ t(`enums.trade_material_status.${row.status}`) }}</Badge>
                </template>
                <template #cell-is_mixture="{ row }">{{ row.is_mixture ? t('dev.list_demo.yes') : t('dev.list_demo.no') }}</template>
                <template #empty>
                    <EmptyState
                        compact
                        :icon="filtered ? 'ph:funnel-x' : 'ph:cube'"
                        :title="filtered ? t('filters.filtered_empty_title') : t('filters.empty_title')"
                        :description="filtered ? t('filters.filtered_empty_description') : t('filters.empty_description')"
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

        <Card class="mt-8 grid gap-4 p-4 md:grid-cols-2">
            <EntitySelect v-model="localChoice" label-key="dev.list_demo.local_select" :options="materialOptions" />
            <EntitySelect
                v-model="remoteChoice"
                label-key="dev.list_demo.remote_select"
                hint-key="dev.list_demo.select_hint"
                :search-url="searchUrl"
            />
        </Card>
        <p class="mt-2 text-label text-ink-3" data-testid="selected-values">
            {{ t('dev.list_demo.selected') }}: <bdi dir="ltr">{{ localChoice ?? t('common.no_data') }}</bdi> / <bdi dir="ltr">{{ remoteChoice ?? t('common.no_data') }}</bdi>
        </p>
    </div>
</template>
