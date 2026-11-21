<script setup>
/**
 * The compliance rule sets list: search, status / scope product type / updated-date filters and sort kept in the URL,
 * and a way to start a new draft rule set. A row opens the rule set with its rules.
 * A rule set is data, not code: every rule carries its source, and only a published rule set is immutable and hashed.
 */
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { Badge, Button, DataTable, EmptyState, PageHeader, Pagination, Tooltip } from '@kit';
import ListFilters from '../../components/list/ListFilters.vue';
import RuleSetFormModal from '../../components/compliance/RuleSetFormModal.vue';
import StatusBadge from '../../components/StatusBadge.vue';
import { useI18n } from '../../composables/useI18n';
import { useListQuery } from '../../composables/useListQuery';

const props = defineProps({
    list: { type: Object, required: true },
    urls: { type: Object, required: true },
    sourceKindOptions: { type: Array, required: true },
});

const { t } = useI18n();
const query = useListQuery(props.list.schema);
const creating = ref(false);

const columns = computed(() => [
    { key: 'code', label: t('compliance.columns.code'), sortable: true },
    { key: 'name', label: t('compliance.columns.name'), sortable: true },
    { key: 'version', label: t('compliance.columns.version'), hideBelow: 'sm' },
    { key: 'status', label: t('compliance.columns.status'), sortable: true },
    { key: 'product_types', label: t('compliance.columns.product_types'), hideBelow: 'md' },
    { key: 'rules_count', label: t('compliance.columns.rules_count'), hideBelow: 'lg' },
    { key: 'updated', label: t('compliance.columns.updated'), sortable: true, hideBelow: 'lg' },
]);

const filtered = computed(() => query.activeCount.value > 0);
</script>

<template>
    <Head :title="t('compliance.title')" />
    <div class="kit-page is-full">
        <PageHeader :title="t('compliance.title')" :description="t('compliance.description')">
            <template #actions>
                <Button icon="ph:plus" data-testid="new-rule-set" @click="creating = true">{{ t('compliance.new') }}</Button>
            </template>
        </PageHeader>

        <div class="flex flex-col gap-4">
            <ListFilters :query="query" />

            <DataTable
                :columns="columns"
                :rows="list.rows"
                row-key="id"
                :row-href="(row) => row.href"
                :mobile-columns="['code', 'status', 'name']"
                v-model:sort="query.sort.value"
                :busy="query.busy.value"
                :title="t('compliance.title')"
                :count="list.meta.total"
            >
                <template #cell-code="{ row }"><bdi dir="ltr" class="font-semibold text-ink">{{ row.code }}</bdi></template>
                <template #cell-version="{ row }"><Badge tone="neutral" mono>{{ row.version }}</Badge></template>
                <template #cell-status="{ row }"><StatusBadge kind="rule_set_status" :status="row.status" /></template>
                <template #cell-product_types="{ row }">
                    <span v-if="row.product_types.length" class="flex flex-wrap gap-1">
                        <Badge v-for="type in row.product_types.slice(0, 3)" :key="type" tone="neutral" size="sm">{{ type }}</Badge>
                        <Tooltip v-if="row.product_types.length > 3" :content="row.product_types.slice(3).join(', ')">
                            <Badge tone="neutral" size="sm" tabindex="0">+{{ row.product_types.length - 3 }}</Badge>
                        </Tooltip>
                    </span>
                    <span v-else class="text-ink-3">{{ t('common.no_data') }}</span>
                </template>
                <template #cell-rules_count="{ row }"><bdi dir="ltr" class="tnum">{{ row.rules_count }}</bdi></template>
                <template #cell-updated="{ row }"><bdi dir="ltr" class="tnum">{{ row.updated_at }}</bdi></template>
                <template #empty>
                    <EmptyState
                        compact
                        :icon="filtered ? 'ph:funnel-x' : 'ph:seal-check'"
                        :title="filtered ? t('filters.filtered_empty_title') : t('compliance.empty_title')"
                        :description="filtered ? t('filters.filtered_empty_description') : t('compliance.empty_description')"
                    >
                        <template #actions>
                            <Button v-if="filtered" variant="secondary" icon="ph:funnel-x" @click="query.clear()">{{ t('filters.clear_all') }}</Button>
                            <Button v-else icon="ph:plus" @click="creating = true">{{ t('compliance.new') }}</Button>
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

        <RuleSetFormModal v-model:open="creating" :urls="urls" :source-kind-options="sourceKindOptions" />
    </div>
</template>
