<script setup>
/**
 * The materials library: trade materials with search, filters, sort and paging kept in the URL (ListFilters,
 * useListQuery, ListQuery on the server), a mobile-friendly table, and a modal to add a material.
 */
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { path } from '../../lib/routes';
import { Badge, Button, DataTable, EmptyState, PageHeader, Pagination } from '@kit';
import ListFilters from '../../components/list/ListFilters.vue';
import ConfidenceBadge from '../../components/materials/ConfidenceBadge.vue';
import MaterialFormModal from '../../components/materials/MaterialFormModal.vue';
import StatusBadge from '../../components/StatusBadge.vue';
import { useI18n } from '../../composables/useI18n';
import { useListQuery } from '../../composables/useListQuery';

const props = defineProps({
    list: { type: Object, required: true },
    statusOptions: { type: Array, required: true },
    urls: { type: Object, required: true },
});

const { t } = useI18n();
const query = useListQuery(props.list.schema);
const creating = ref(false);

const columns = computed(() => [
    { key: 'trade_name', label: t('materials.columns.trade_name'), sortable: true },
    { key: 'grade', label: t('materials.columns.grade'), hideBelow: 'md' },
    { key: 'function', label: t('materials.columns.function'), hideBelow: 'md' },
    { key: 'status', label: t('materials.columns.status') },
    { key: 'worst_price_confidence', label: t('materials.columns.price_confidence'), hideBelow: 'lg' },
    { key: 'has_sanad_link', label: t('materials.columns.sanad'), hideBelow: 'lg' },
    { key: 'updated_at', label: t('materials.columns.updated_at'), sortable: true, hideBelow: 'xl' },
]);

const filtered = computed(() => query.activeCount.value > 0);
const open = (row) => path('materials.show', row.id);
const substanceLine = (row) => [row.substance?.inci ?? row.substance?.name_en, row.substance?.cas].filter(Boolean).join(' · ');
</script>

<template>
    <Head :title="t('materials.title')" />
    <div class="kit-page is-full">
        <PageHeader :title="t('materials.title')" :description="t('materials.description')">
            <template #actions>
                <Button icon="ph:plus" @click="creating = true">{{ t('materials.new') }}</Button>
            </template>
        </PageHeader>

        <div class="flex flex-col gap-4">
            <ListFilters :query="query" />

            <DataTable
                :columns="columns"
                :rows="list.rows"
                row-key="id"
                :row-href="open"
                :mobile-columns="['trade_name', 'status', 'grade']"
                v-model:sort="query.sort.value"
                :busy="query.busy.value"
                :title="t('materials.title')"
                :count="list.meta.total"
            >
                <template #cell-trade_name="{ row }">
                    <span class="flex flex-col">
                        <span class="font-semibold text-ink">{{ row.trade_name }}</span>
                        <bdi v-if="substanceLine(row)" dir="ltr" class="text-label text-ink-3">{{ substanceLine(row) }}</bdi>
                        <Badge v-else-if="row.is_mixture" tone="neutral" size="sm" class="self-start">{{ t('materials.mixture') }}</Badge>
                    </span>
                </template>
                <template #cell-grade="{ row }">{{ row.grade ?? t('common.no_data') }}</template>
                <template #cell-function="{ row }">{{ row.function ?? t('common.no_data') }}</template>
                <template #cell-status="{ row }"><StatusBadge kind="trade_material_status" :status="row.status" /></template>
                <template #cell-worst_price_confidence="{ row }">
                    <ConfidenceBadge v-if="row.worst_price_confidence" :confidence="row.worst_price_confidence" />
                    <span v-else class="text-ink-3">{{ t('materials.no_price') }}</span>
                </template>
                <template #cell-has_sanad_link="{ row }">
                    <Badge v-if="row.has_sanad_link" tone="info" icon="ph:link-simple">{{ t('materials.sanad_linked') }}</Badge>
                    <span v-else class="text-ink-3">{{ t('common.no_data') }}</span>
                </template>
                <template #empty>
                    <EmptyState
                        compact
                        :icon="filtered ? 'ph:funnel-x' : 'ph:cube'"
                        :title="filtered ? t('filters.filtered_empty_title') : t('materials.empty_title')"
                        :description="filtered ? t('filters.filtered_empty_description') : t('materials.empty_description')"
                    >
                        <template #actions>
                            <Button v-if="filtered" variant="secondary" icon="ph:funnel-x" @click="query.clear()">{{ t('filters.clear_all') }}</Button>
                            <Button v-else icon="ph:plus" @click="creating = true">{{ t('materials.new') }}</Button>
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

        <MaterialFormModal v-model:open="creating" mode="create" :urls="urls" :status-options="statusOptions" />
    </div>
</template>
