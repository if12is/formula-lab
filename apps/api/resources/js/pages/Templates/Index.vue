<script setup>
/**
 * The template library (K-09): search, family and verification-status filters and sort kept in the URL. The status says how far a
 * template has been proven (theoretical, lab tested, chemist approved); a share nobody provided is counted, never filled in.
 */
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import { DataTable, EmptyState, PageHeader, Pagination } from '@kit';
import ListFilters from '../../components/list/ListFilters.vue';
import VerificationBadge from '../../components/families/VerificationBadge.vue';
import { useI18n } from '../../composables/useI18n';
import { useListQuery } from '../../composables/useListQuery';

const props = defineProps({
    list: { type: Object, required: true },
});

const { t } = useI18n();
const query = useListQuery(props.list.schema);

const columns = computed(() => [
    { key: 'name', label: t('templates.columns.name'), sortable: true },
    { key: 'family', label: t('templates.columns.family'), hideBelow: 'sm' },
    { key: 'status', label: t('templates.columns.status'), sortable: true },
    { key: 'lines', label: t('templates.columns.lines'), hideBelow: 'md' },
    { key: 'missing_percents', label: t('templates.columns.missing_percents'), hideBelow: 'md' },
]);

const filtered = computed(() => query.activeCount.value > 0);
</script>

<template>
    <Head :title="t('templates.title')" />
    <div class="kit-page is-full">
        <PageHeader :title="t('templates.title')" :description="t('templates.description')" />

        <div class="flex flex-col gap-4">
            <ListFilters :query="query" />

            <DataTable
                :columns="columns"
                :rows="list.rows"
                row-key="id"
                :row-href="(row) => row.href"
                :mobile-columns="['name', 'status']"
                v-model:sort="query.sort.value"
                :busy="query.busy.value"
                :title="t('templates.title')"
                :count="list.meta.total"
            >
                <template #cell-name="{ row }"><span class="font-semibold text-ink">{{ row.name }}</span></template>
                <template #cell-status="{ row }"><VerificationBadge kind="template" :status="row.status" /></template>
                <template #cell-lines="{ row }"><bdi dir="ltr" class="tnum">{{ row.lines }}</bdi></template>
                <template #cell-missing_percents="{ row }"><bdi dir="ltr" class="tnum">{{ row.missing_percents }}</bdi></template>
                <template #empty>
                    <EmptyState
                        compact
                        :icon="filtered ? 'ph:funnel-x' : 'ph:files'"
                        :title="filtered ? t('filters.filtered_empty_title') : t('templates.empty_title')"
                        :description="filtered ? t('filters.filtered_empty_description') : t('templates.empty_description')"
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
