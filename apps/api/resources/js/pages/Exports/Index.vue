<script setup>
/**
 * The export log (U-03): every document printed (or saved as PDF) from a print preview, with who, what, when and the hash printed on it.
 * Search, filters, sort and page live in the URL like every list. A row opens the page the document was printed from.
 */
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import { Badge, Button, CopyButton, DataTable, EmptyState, PageHeader, Pagination } from '@kit';
import ListFilters from '../../components/list/ListFilters.vue';
import { useDateTime } from '../../composables/useDateTime';
import { useI18n } from '../../composables/useI18n';
import { useListQuery } from '../../composables/useListQuery';

const props = defineProps({
    list: { type: Object, required: true },
});

const { t } = useI18n();
const { when } = useDateTime();
const query = useListQuery(props.list.schema);

const columns = computed(() => [
    { key: 'when', label: t('exports.columns.when'), sortable: true },
    { key: 'kind', label: t('exports.columns.kind'), sortable: true },
    { key: 'subject', label: t('exports.columns.subject'), sortable: true },
    { key: 'user', label: t('exports.columns.user'), sortable: true, hideBelow: 'md' },
    { key: 'watermark', label: t('exports.columns.watermark'), hideBelow: 'lg' },
    { key: 'hash', label: t('exports.columns.hash'), hideBelow: 'md' },
]);

const filtered = computed(() => query.activeCount.value > 0);
</script>

<template>
    <Head :title="t('exports.title')" />
    <div class="kit-page is-full" data-testid="export-log">
        <PageHeader :title="t('exports.title')" :description="t('exports.description')" />

        <div class="flex flex-col gap-4">
            <ListFilters :query="query" />

            <DataTable
                :columns="columns"
                :rows="list.rows"
                row-key="id"
                :row-href="(row) => row.href"
                :mobile-columns="['when', 'kind', 'subject', 'user', 'hash']"
                v-model:sort="query.sort.value"
                :busy="query.busy.value"
                :title="t('exports.title')"
                :count="list.meta.total"
            >
                <template #cell-when="{ row }"><bdi class="tnum">{{ when(row.exported_at) }}</bdi></template>
                <template #cell-kind="{ row }">{{ t(`enums.export_kind.${row.kind}`) }}</template>
                <template #cell-subject="{ row }"><bdi dir="ltr" class="font-semibold text-ink">{{ row.subject_label }}</bdi></template>
                <template #cell-user="{ row }">
                    <span v-if="row.user_name">{{ row.user_name }}</span>
                    <span v-else class="text-ink-3" :title="t('exports.no_user_hint')">{{ t('exports.no_user') }}</span>
                </template>
                <template #cell-watermark="{ row }"><Badge :tone="row.watermark === 'unapproved' ? 'warning' : 'neutral'">{{ t(`enums.print_watermark.${row.watermark}`) }}</Badge></template>
                <template #cell-hash="{ row }">
                    <span class="flex items-center gap-1">
                        <bdi dir="ltr" class="font-mono text-label text-ink-2" :title="row.content_hash" data-testid="export-hash">{{ row.content_hash.slice(0, 12) }}…</bdi>
                        <CopyButton :value="row.content_hash" :label="t('exports.copy_hash')" />
                    </span>
                </template>
                <template #empty>
                    <EmptyState
                        compact
                        :icon="filtered ? 'ph:funnel-x' : 'ph:printer'"
                        :title="filtered ? t('filters.filtered_empty_title') : t('exports.empty_title')"
                        :description="filtered ? t('filters.filtered_empty_description') : t('exports.empty_description')"
                    >
                        <template v-if="filtered" #actions>
                            <Button variant="secondary" icon="ph:funnel-x" @click="query.clear()">{{ t('filters.clear_all') }}</Button>
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
