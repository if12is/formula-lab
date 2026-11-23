<script setup>
/**
 * Closure compatibility (H-02): the neck finish pairs people declared, each with its source, filtered, sorted and paged through
 * ListFilters / useListQuery, and the BOM check built on them. Nothing is pre-filled: with no pair declared every closure is refused.
 */
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Button, DataTable, EmptyState, IconButton, PageHeader, Pagination, useConfirm } from '@kit';
import CompatibilityCheck from '../../components/packaging/CompatibilityCheck.vue';
import CompatibilityFormModal from '../../components/packaging/CompatibilityFormModal.vue';
import ListFilters from '../../components/list/ListFilters.vue';
import ConfidenceBadge from '../../components/materials/ConfidenceBadge.vue';
import { useI18n } from '../../composables/useI18n';
import { useListQuery } from '../../composables/useListQuery';
import { path } from '../../lib/routes';

const props = defineProps({
    list: { type: Object, required: true },
    confidenceOptions: { type: Array, required: true },
    sourceKindOptions: { type: Array, required: true },
    containerOptions: { type: Array, required: true },
    closureOptions: { type: Array, required: true },
    urls: { type: Object, required: true },
});

const { t } = useI18n();
const { confirm } = useConfirm();
const query = useListQuery(props.list.schema);
const formOpen = ref(false);

const columns = computed(() => [
    { key: 'container_neck_finish', label: t('packaging.compat.columns.container_neck_finish'), sortable: true },
    { key: 'closure_neck_finish', label: t('packaging.compat.columns.closure_neck_finish'), sortable: true },
    { key: 'source_title', label: t('packaging.compat.columns.source'), hideBelow: 'lg' },
    { key: 'confidence', label: t('packaging.compat.columns.confidence'), sortable: true, hideBelow: 'md' },
    { key: 'note', label: t('packaging.compat.columns.note'), hideBelow: 'xl' },
    { key: 'created_at', label: t('packaging.compat.columns.created_at'), sortable: true, hideBelow: 'xl' },
    { key: 'actions', label: '', align: 'end' },
]);

const filtered = computed(() => query.activeCount.value > 0);

async function withdraw(row) {
    const confirmed = await confirm({
        tone: 'danger',
        title: t('packaging.compat.withdraw_title'),
        description: t('packaging.compat.withdraw_description'),
        confirmLabel: t('packaging.compat.withdraw'),
    });

    if (confirmed) {
        router.delete(path('packaging.compatibility.destroy', row.id), { preserveScroll: true });
    }
}
</script>

<template>
    <Head :title="t('packaging.compat.title')" />
    <div class="kit-page is-full">
        <PageHeader :title="t('packaging.compat.title')" :description="t('packaging.compat.description')">
            <template #actions>
                <Link :href="path('packaging.index')" class="type-body text-primary underline-offset-2 hover:underline">{{ t('packaging.compat.back') }}</Link>
                <Button icon="ph:plus" data-testid="compat-new" @click="formOpen = true">{{ t('packaging.compat.declare') }}</Button>
            </template>
        </PageHeader>

        <div class="flex flex-col gap-4">
            <CompatibilityCheck :container-options="containerOptions" :closure-options="closureOptions" :url="urls.check" />

            <ListFilters :query="query" />

            <DataTable
                :columns="columns"
                :rows="list.rows"
                row-key="id"
                :mobile-columns="['container_neck_finish', 'closure_neck_finish', 'actions']"
                v-model:sort="query.sort.value"
                :busy="query.busy.value"
                :title="t('packaging.compat.title')"
                :count="list.meta.total"
            >
                <template #cell-container_neck_finish="{ row }"><bdi dir="ltr" class="font-semibold text-ink">{{ row.container_neck_finish }}</bdi></template>
                <template #cell-closure_neck_finish="{ row }"><bdi dir="ltr" class="font-semibold text-ink">{{ row.closure_neck_finish }}</bdi></template>
                <template #cell-source_title="{ row }">{{ row.source_title ?? t('common.no_data') }}</template>
                <template #cell-confidence="{ row }"><ConfidenceBadge :confidence="row.confidence" /></template>
                <template #cell-note="{ row }">{{ row.note ?? t('common.no_data') }}</template>
                <template #cell-actions="{ row }">
                    <IconButton icon="ph:trash" size="sm" variant="ghost" :label="t('packaging.compat.withdraw')" :data-testid="`compat-withdraw-${row.id}`" @click="withdraw(row)" />
                </template>
                <template #empty>
                    <EmptyState
                        compact
                        :icon="filtered ? 'ph:funnel-x' : 'ph:plugs-connected'"
                        :title="filtered ? t('filters.filtered_empty_title') : t('packaging.compat.empty_title')"
                        :description="filtered ? t('filters.filtered_empty_description') : t('packaging.compat.empty_description')"
                    >
                        <template #actions>
                            <Button v-if="filtered" variant="secondary" icon="ph:funnel-x" @click="query.clear()">{{ t('filters.clear_all') }}</Button>
                            <Button v-else icon="ph:plus" @click="formOpen = true">{{ t('packaging.compat.declare') }}</Button>
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

        <CompatibilityFormModal v-model:open="formOpen" :confidence-options="confidenceOptions" :source-kind-options="sourceKindOptions" :urls="urls" />
    </div>
</template>
