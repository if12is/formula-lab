<script setup>
/**
 * The packaging component library (H-01): bottles, closures, labels and cartons with their spec, filtered, sorted and paged
 * through ListFilters / useListQuery (state in the URL). A spec value never recorded reads "not recorded", not zero.
 */
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { Badge, Button, DataTable, EmptyState, PageHeader, Pagination } from '@kit';
import ListFilters from '../../components/list/ListFilters.vue';
import ConfidenceBadge from '../../components/materials/ConfidenceBadge.vue';
import Num from '../../components/Num.vue';
import PackagingFormModal from '../../components/packaging/PackagingFormModal.vue';
import { useI18n } from '../../composables/useI18n';
import { useListQuery } from '../../composables/useListQuery';
import { path } from '../../lib/routes';

const props = defineProps({
    list: { type: Object, required: true },
    kindOptions: { type: Array, required: true },
    materialOptions: { type: Array, required: true },
    confidenceOptions: { type: Array, required: true },
    sourceKindOptions: { type: Array, required: true },
    supplierOptions: { type: Array, required: true },
    urls: { type: Object, required: true },
});

const { t } = useI18n();
const query = useListQuery(props.list.schema);
const editing = ref(null);
const formOpen = ref(false);

const columns = computed(() => [
    { key: 'trade_name', label: t('packaging.columns.trade_name'), sortable: true },
    { key: 'item_kind', label: t('packaging.columns.item_kind'), sortable: true },
    { key: 'packaging_material', label: t('packaging.columns.packaging_material'), hideBelow: 'md' },
    { key: 'nominal_capacity_ml', label: t('packaging.columns.nominal_capacity_ml'), sortable: true, hideBelow: 'md' },
    { key: 'brimful_capacity_ml', label: t('packaging.columns.brimful_capacity_ml'), sortable: true, hideBelow: 'lg' },
    { key: 'unit_weight_g', label: t('packaging.columns.unit_weight_g'), sortable: true, hideBelow: 'lg' },
    { key: 'neck_finish', label: t('packaging.columns.neck_finish'), hideBelow: 'lg' },
    { key: 'supplier_name', label: t('packaging.columns.supplier'), hideBelow: 'xl' },
    { key: 'packaging_confidence', label: t('packaging.columns.confidence'), hideBelow: 'xl' },
]);

const filtered = computed(() => query.activeCount.value > 0);

function create() {
    editing.value = null;
    formOpen.value = true;
}

function edit(row) {
    editing.value = row;
    formOpen.value = true;
}
</script>

<template>
    <Head :title="t('packaging.title')" />
    <div class="kit-page is-full">
        <PageHeader :title="t('packaging.title')" :description="t('packaging.description')">
            <template #actions>
                <Link :href="path('packaging.compatibility.index')" class="type-body text-primary underline-offset-2 hover:underline" data-testid="packaging-compat-link">{{ t('packaging.compat.open') }}</Link>
                <Button icon="ph:plus" data-testid="packaging-new" @click="create">{{ t('packaging.new') }}</Button>
            </template>
        </PageHeader>

        <div class="flex flex-col gap-4">
            <ListFilters :query="query" />

            <DataTable
                :columns="columns"
                :rows="list.rows"
                row-key="id"
                :mobile-columns="['trade_name', 'item_kind', 'nominal_capacity_ml']"
                v-model:sort="query.sort.value"
                :busy="query.busy.value"
                :title="t('packaging.title')"
                :count="list.meta.total"
            >
                <template #cell-trade_name="{ row }">
                    <button type="button" class="flex flex-col text-start" :data-testid="`packaging-row-${row.id}`" @click="edit(row)">
                        <span class="font-semibold text-ink">{{ row.trade_name }}</span>
                        <Badge v-if="row.has_sanad_link" tone="info" size="sm" icon="ph:link-simple" class="self-start">{{ t('packaging.sanad_linked') }}</Badge>
                    </button>
                </template>
                <template #cell-item_kind="{ row }">{{ t(`enums.item_kind.${row.item_kind}`) }}</template>
                <template #cell-packaging_material="{ row }">{{ row.packaging_material ? t(`enums.packaging_material.${row.packaging_material}`) : t('packaging.not_recorded') }}</template>
                <template #cell-nominal_capacity_ml="{ row }">
                    <Num :value="row.nominal_capacity_ml" reason-key="packaging.not_recorded"><template #suffix> {{ t('packaging.units.ml') }}</template></Num>
                </template>
                <template #cell-brimful_capacity_ml="{ row }">
                    <Num :value="row.brimful_capacity_ml" reason-key="packaging.not_recorded"><template #suffix> {{ t('packaging.units.ml') }}</template></Num>
                </template>
                <template #cell-unit_weight_g="{ row }">
                    <Num :value="row.unit_weight_g" reason-key="packaging.not_recorded"><template #suffix> {{ t('packaging.units.g') }}</template></Num>
                </template>
                <template #cell-neck_finish="{ row }">
                    <bdi v-if="row.neck_finish" dir="ltr">{{ row.neck_finish }}</bdi>
                    <span v-else class="text-ink-3">{{ t('packaging.not_recorded') }}</span>
                </template>
                <template #cell-supplier_name="{ row }">{{ row.supplier_name ?? t('common.no_data') }}</template>
                <template #cell-packaging_confidence="{ row }">
                    <ConfidenceBadge v-if="row.packaging_confidence" :confidence="row.packaging_confidence" />
                    <span v-else class="text-ink-3">{{ t('packaging.not_recorded') }}</span>
                </template>
                <template #empty>
                    <EmptyState
                        compact
                        :icon="filtered ? 'ph:funnel-x' : 'ph:package'"
                        :title="filtered ? t('filters.filtered_empty_title') : t('packaging.empty_title')"
                        :description="filtered ? t('filters.filtered_empty_description') : t('packaging.empty_description')"
                    >
                        <template #actions>
                            <Button v-if="filtered" variant="secondary" icon="ph:funnel-x" @click="query.clear()">{{ t('filters.clear_all') }}</Button>
                            <Button v-else icon="ph:plus" @click="create">{{ t('packaging.new') }}</Button>
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

        <PackagingFormModal
            v-model:open="formOpen"
            :item="editing"
            :kind-options="kindOptions"
            :material-options="materialOptions"
            :confidence-options="confidenceOptions"
            :source-kind-options="sourceKindOptions"
            :supplier-options="supplierOptions"
            :urls="urls"
        />
    </div>
</template>
