<script setup>
/**
 * The product family registry (K-01): search, group / regulator / IFRA category / verification filters and sort kept in
 * the URL. A family a source does not give a regulator or an IFRA category for shows "not stated", never a guess.
 */
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import { Badge, DataTable, EmptyState, PageHeader, Pagination } from '@kit';
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
    { key: 'name', label: t('families.columns.name'), sortable: true },
    { key: 'code', label: t('families.columns.code'), sortable: true, hideBelow: 'lg' },
    { key: 'group', label: t('families.columns.group'), sortable: true, hideBelow: 'md' },
    { key: 'regulator', label: t('families.columns.regulator'), sortable: true },
    { key: 'ifra_category', label: t('families.columns.ifra_category'), hideBelow: 'md' },
    { key: 'calculations', label: t('families.columns.calculations'), hideBelow: 'lg' },
    { key: 'properties', label: t('families.columns.properties'), hideBelow: 'lg' },
    { key: 'templates', label: t('families.columns.templates'), hideBelow: 'md' },
    { key: 'verification', label: t('families.columns.verification'), hideBelow: 'md' },
]);

const filtered = computed(() => query.activeCount.value > 0);
</script>

<template>
    <Head :title="t('families.title')" />
    <div class="kit-page is-full">
        <PageHeader :title="t('families.title')" :description="t('families.description')" />

        <div class="flex flex-col gap-4">
            <ListFilters :query="query" />

            <DataTable
                :columns="columns"
                :rows="list.rows"
                row-key="id"
                :row-href="(row) => row.href"
                :mobile-columns="['name', 'regulator']"
                v-model:sort="query.sort.value"
                :busy="query.busy.value"
                :title="t('families.title')"
                :count="list.meta.total"
            >
                <template #cell-name="{ row }"><span class="font-semibold text-ink">{{ row.name }}</span></template>
                <template #cell-code="{ row }"><bdi dir="ltr" class="text-ink-2">{{ row.code }}</bdi></template>
                <template #cell-group="{ row }">{{ t(`families.groups.${row.group}`) }}</template>
                <template #cell-regulator="{ row }">
                    <Badge v-if="row.regulator" tone="info" mono>{{ row.regulator }}</Badge>
                    <span v-else class="text-ink-3">{{ t('families.not_stated') }}</span>
                </template>
                <template #cell-ifra_category="{ row }">
                    <Badge v-if="row.ifra_category" tone="neutral" mono>{{ row.ifra_category }}</Badge>
                    <span v-else class="text-ink-3">{{ t('families.not_stated') }}</span>
                </template>
                <template #cell-calculations="{ row }"><bdi dir="ltr" class="tnum">{{ row.calculations }}</bdi></template>
                <template #cell-properties="{ row }"><bdi dir="ltr" class="tnum">{{ row.properties }}</bdi></template>
                <template #cell-templates="{ row }"><bdi dir="ltr" class="tnum">{{ row.templates }}</bdi></template>
                <template #cell-verification="{ row }"><VerificationBadge kind="data" :status="row.verification" /></template>
                <template #empty>
                    <EmptyState
                        compact
                        :icon="filtered ? 'ph:funnel-x' : 'ph:tree-structure'"
                        :title="filtered ? t('filters.filtered_empty_title') : t('families.empty_title')"
                        :description="filtered ? t('filters.filtered_empty_description') : t('families.empty_description')"
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
