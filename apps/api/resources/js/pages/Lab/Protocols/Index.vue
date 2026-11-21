<script setup>
/**
 * The test protocol templates (O-06): the simple, repeatable steps for the common measurements, the instrument each one
 * needs and its unit. They carry no acceptance limits. Search, a "measures" filter and sort are kept in the URL.
 */
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import { Badge, DataTable, EmptyState, PageHeader, Pagination } from '@kit';
import ListFilters from '../../../components/list/ListFilters.vue';
import { useI18n } from '../../../composables/useI18n';
import { useListQuery } from '../../../composables/useListQuery';

const props = defineProps({
    list: { type: Object, required: true },
});

const { t } = useI18n();
const query = useListQuery(props.list.schema);

const columns = computed(() => [
    { key: 'name', label: t('lab.protocols_page.columns.name') },
    { key: 'property', label: t('lab.protocols_page.columns.property'), sortable: true, hideBelow: 'sm' },
    { key: 'unit', label: t('lab.protocols_page.columns.unit'), hideBelow: 'md' },
    { key: 'instrument', label: t('lab.protocols_page.columns.instrument'), hideBelow: 'md' },
    { key: 'steps', label: t('lab.protocols_page.columns.steps'), hideBelow: 'lg' },
]);

const filtered = computed(() => query.activeCount.value > 0);
</script>

<template>
    <Head :title="t('lab.protocols_page.title')" />
    <div class="kit-page is-full">
        <PageHeader :title="t('lab.protocols_page.title')" :description="t('lab.protocols_page.description')" />

        <div class="flex flex-col gap-4">
            <ListFilters :query="query" />

            <DataTable
                :columns="columns"
                :rows="list.rows"
                row-key="id"
                :row-href="(row) => row.href"
                :mobile-columns="['name', 'property']"
                v-model:sort="query.sort.value"
                :busy="query.busy.value"
                :title="t('lab.protocols_page.title')"
                :count="list.meta.total"
            >
                <template #cell-name="{ row }"><span class="font-semibold text-ink">{{ row.name }}</span></template>
                <template #cell-property="{ row }"><Badge tone="info">{{ t(`enums.lab_property.${row.property}`) }}</Badge></template>
                <template #cell-unit="{ row }"><bdi dir="ltr" class="tnum">{{ row.unit }}</bdi></template>
                <template #cell-steps="{ row }"><Badge tone="neutral" mono>{{ row.steps }}</Badge></template>
                <template #empty>
                    <EmptyState
                        compact
                        :icon="filtered ? 'ph:funnel-x' : 'ph:list-checks'"
                        :title="filtered ? t('filters.filtered_empty_title') : t('lab.protocols_page.empty_title')"
                        :description="filtered ? t('filters.filtered_empty_description') : t('lab.protocols_page.empty_description')"
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
