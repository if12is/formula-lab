<script setup>
/**
 * The lab trials list: search, formula / trial status / version status / date filters and sort kept in the URL, and a way
 * to open a new trial. A row opens the trial with its measurements.
 */
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import { path } from '../../../lib/routes';
import { Badge, Button, DataTable, EmptyState, PageHeader, Pagination } from '@kit';
import ListFilters from '../../../components/list/ListFilters.vue';
import StatusBadge from '../../../components/StatusBadge.vue';
import { useI18n } from '../../../composables/useI18n';
import { useListQuery } from '../../../composables/useListQuery';

const props = defineProps({
    list: { type: Object, required: true },
});

const { t } = useI18n();
const query = useListQuery(props.list.schema);

const columns = computed(() => [
    { key: 'code', label: t('lab.trials.columns.code'), sortable: true },
    { key: 'formula', label: t('lab.trials.columns.formula'), sortable: true },
    { key: 'version', label: t('lab.trials.columns.version'), hideBelow: 'md' },
    { key: 'status', label: t('lab.trials.columns.status'), sortable: true },
    { key: 'trial_date', label: t('lab.trials.columns.trial_date'), sortable: true, hideBelow: 'md' },
    { key: 'batch', label: t('lab.trials.columns.batch'), hideBelow: 'lg' },
    { key: 'operator', label: t('lab.trials.columns.operator'), hideBelow: 'lg' },
    { key: 'measurements', label: t('lab.trials.columns.measurements'), hideBelow: 'lg' },
]);

const filtered = computed(() => query.activeCount.value > 0);
</script>

<template>
    <Head :title="t('lab.trials.title')" />
    <div class="kit-page is-full">
        <PageHeader :title="t('lab.trials.title')" :description="t('lab.trials.description')">
            <template #actions>
                <Button :href="path('lab.trials.create')" icon="ph:plus" data-testid="new-trial">{{ t('lab.trials.new') }}</Button>
            </template>
        </PageHeader>

        <div class="flex flex-col gap-4">
            <ListFilters :query="query" />

            <DataTable
                :columns="columns"
                :rows="list.rows"
                row-key="id"
                :row-href="(row) => row.href"
                :mobile-columns="['code', 'status', 'formula']"
                v-model:sort="query.sort.value"
                :busy="query.busy.value"
                :title="t('lab.trials.title')"
                :count="list.meta.total"
            >
                <template #cell-code="{ row }"><bdi dir="ltr" class="font-semibold text-ink">{{ row.code }}</bdi></template>
                <template #cell-formula="{ row }">
                    <span class="flex flex-col"><bdi dir="ltr" class="text-ink">{{ row.formula_code }}</bdi><span class="text-label text-ink-3">{{ row.formula_name }}</span></span>
                </template>
                <template #cell-version="{ row }">
                    <span class="flex flex-wrap items-center gap-2">
                        <Badge tone="neutral" mono>{{ t('formulas.version_short', { version: row.version }) }}</Badge>
                        <StatusBadge kind="formula_status" :status="row.version_status" />
                    </span>
                </template>
                <template #cell-status="{ row }"><StatusBadge kind="lab_trial_status" :status="row.status" /></template>
                <template #cell-trial_date="{ row }"><bdi dir="ltr" class="tnum">{{ row.trial_date }}</bdi></template>
                <template #cell-batch="{ row }"><bdi dir="ltr" class="tnum">{{ row.batch_size }} {{ row.batch_unit }}</bdi></template>
                <template #cell-measurements="{ row }">
                    <Badge v-if="row.measurements > 0" tone="info" mono>{{ row.measurements }}</Badge>
                    <span v-else class="text-ink-3">{{ t('common.no_data') }}</span>
                </template>
                <template #empty>
                    <EmptyState
                        compact
                        :icon="filtered ? 'ph:funnel-x' : 'ph:test-tube'"
                        :title="filtered ? t('filters.filtered_empty_title') : t('lab.trials.empty_title')"
                        :description="filtered ? t('filters.filtered_empty_description') : t('lab.trials.empty_description')"
                    >
                        <template #actions>
                            <Button v-if="filtered" variant="secondary" icon="ph:funnel-x" @click="query.clear()">{{ t('filters.clear_all') }}</Button>
                            <Button v-else :href="path('lab.trials.create')" icon="ph:plus">{{ t('lab.trials.new') }}</Button>
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
