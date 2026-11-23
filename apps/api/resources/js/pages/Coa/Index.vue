<script setup>
/**
 * The certificates of analysis the model has read (P-05): material, lot, state (waiting for a person, confirmed, rejected) and how many values it
 * proposed. Search, filters, sort and page live in the URL like every list. A row opens its review screen; "Read a COA" uploads a new one.
 */
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { Badge, Button, DataTable, EmptyState, PageHeader, Pagination } from '@kit';
import CoaStatusBadge from '../../components/coa/CoaStatusBadge.vue';
import CoaUploadModal from '../../components/coa/CoaUploadModal.vue';
import ListFilters from '../../components/list/ListFilters.vue';
import { useDateTime } from '../../composables/useDateTime';
import { useI18n } from '../../composables/useI18n';
import { useListQuery } from '../../composables/useListQuery';

const props = defineProps({
    list: { type: Object, required: true },
    upload: { type: Object, required: true },
});

const { t } = useI18n();
const { when } = useDateTime();
const query = useListQuery(props.list.schema);
const uploading = ref(false);

const columns = computed(() => [
    { key: 'when', label: t('coa.columns.when'), sortable: true },
    { key: 'material', label: t('coa.columns.material'), sortable: true },
    { key: 'lot', label: t('coa.columns.lot'), sortable: true },
    { key: 'status', label: t('coa.columns.status'), sortable: true },
    { key: 'fields', label: t('coa.columns.fields'), sortable: true, align: 'end', hideBelow: 'md' },
    { key: 'file', label: t('coa.columns.file'), hideBelow: 'lg' },
]);

const filtered = computed(() => query.activeCount.value > 0);
</script>

<template>
    <Head :title="t('coa.title')" />
    <div class="kit-page is-full" data-testid="coa-list">
        <PageHeader :title="t('coa.title')" :description="t('coa.description')">
            <template #actions>
                <Button icon="ph:upload-simple" data-testid="coa-upload-open" @click="uploading = true">{{ t('coa.upload.open') }}</Button>
            </template>
        </PageHeader>

        <div class="flex flex-col gap-4">
            <ListFilters :query="query" />

            <DataTable
                :columns="columns"
                :rows="list.rows"
                row-key="id"
                :row-href="(row) => row.href"
                :mobile-columns="['when', 'material', 'lot', 'status']"
                v-model:sort="query.sort.value"
                :busy="query.busy.value"
                :title="t('coa.title')"
                :count="list.meta.total"
            >
                <template #cell-when="{ row }"><bdi class="tnum">{{ when(row.created_at) }}</bdi></template>
                <template #cell-material="{ row }"><span class="font-semibold text-ink">{{ row.material_name }}</span></template>
                <template #cell-lot="{ row }">
                    <bdi v-if="row.lot_no" dir="ltr" class="font-mono text-label">{{ row.lot_no }}</bdi>
                    <span v-else class="text-ink-3" :title="t('coa.no_lot_hint')">{{ t('coa.no_lot') }}</span>
                </template>
                <template #cell-status="{ row }">
                    <span class="flex flex-wrap items-center gap-2">
                        <CoaStatusBadge :status="row.status" />
                        <Badge v-if="row.text_check === 'unverified'" tone="info" :title="t('coa.review.unverified')">{{ t('coa.unverified_short') }}</Badge>
                    </span>
                </template>
                <template #cell-fields="{ row }"><bdi class="tnum">{{ row.field_count }}</bdi></template>
                <template #cell-file="{ row }"><bdi dir="ltr" class="text-label text-ink-2">{{ row.file_name }}</bdi></template>
                <template #empty>
                    <EmptyState
                        compact
                        :icon="filtered ? 'ph:funnel-x' : 'ph:file-magnifying-glass'"
                        :title="filtered ? t('filters.filtered_empty_title') : t('coa.empty_title')"
                        :description="filtered ? t('filters.filtered_empty_description') : t('coa.empty_description')"
                    >
                        <template #actions>
                            <Button v-if="filtered" variant="secondary" icon="ph:funnel-x" @click="query.clear()">{{ t('filters.clear_all') }}</Button>
                            <Button v-else icon="ph:upload-simple" @click="uploading = true">{{ t('coa.upload.open') }}</Button>
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

        <CoaUploadModal v-model:open="uploading" :upload="upload" />
    </div>
</template>
