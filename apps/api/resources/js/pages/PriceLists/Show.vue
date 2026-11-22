<script setup>
/**
 * One price list, products tab: every product on the list with how it is priced (a margin rule, or an agreed price with its
 * source), the unit cost behind it, and what the engine says: the price, the margin and a warning when the price is below
 * cost. The price opens the engine's calculation tree. Search, filters, sort and paging live in the URL (ListFilters,
 * ListQuery). A line the engine cannot price says exactly what is missing and blocks the publish; it is never shown as zero.
 */
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Alert, Badge, Button, DataTable, EmptyState, IconButton, Pagination, useConfirm } from '@kit';
import ListFilters from '../../components/list/ListFilters.vue';
import Num from '../../components/Num.vue';
import ConfidenceBadge from '../../components/materials/ConfidenceBadge.vue';
import ExplainableNum from '../../components/explain/ExplainableNum.vue';
import PriceListHeader from '../../components/pricing/PriceListHeader.vue';
import PriceListLineModal from '../../components/pricing/PriceListLineModal.vue';
import UnitCostModal from '../../components/pricing/UnitCostModal.vue';
import { useI18n } from '../../composables/useI18n';
import { useListQuery } from '../../composables/useListQuery';
import { evaluateLine, priceSources } from '../../lib/priceList';
import { path } from '../../lib/routes';

const props = defineProps({
    priceList: { type: Object, required: true },
    list: { type: Object, required: true },
    rules: { type: Array, required: true },
    modeOptions: { type: Array, required: true },
    confidenceOptions: { type: Array, required: true },
    sourceKindOptions: { type: Array, required: true },
    urls: { type: Object, required: true },
});

const { t } = useI18n();
const { confirm } = useConfirm();
const query = useListQuery(props.list.schema);
const lineOpen = ref(false);
const editing = ref(null);
const costOpen = ref(false);
const costing = ref(null);

const columns = computed(() => [
    { key: 'product', label: t('price_lists.items.columns.product'), sortable: true },
    { key: 'pricing', label: t('price_lists.items.columns.pricing'), hideBelow: 'md' },
    { key: 'cost', label: t('price_lists.items.columns.cost'), align: 'end', hideBelow: 'sm' },
    { key: 'price', label: t('price_lists.items.columns.price'), align: 'end' },
    { key: 'margin', label: t('price_lists.items.columns.margin'), align: 'end', hideBelow: 'md' },
]);

// The engine's answer for each line of this page, keyed by line id.
const lines = computed(() => Object.fromEntries(props.list.rows.map((row) => [row.id, evaluateLine(row.engine)])));
const sources = computed(() => priceSources(props.list.rows, props.rules));
const belowCostCount = computed(() => Object.values(lines.value).filter((line) => line.ok && line.belowCost).length);
const filtered = computed(() => query.activeCount.value > 0);

function add() {
    editing.value = null;
    lineOpen.value = true;
}

function edit(row) {
    editing.value = row;
    lineOpen.value = true;
}

function editCost(row) {
    costing.value = row;
    costOpen.value = true;
}

async function remove(row) {
    const confirmed = await confirm({
        title: t('price_lists.items.remove_title'),
        description: t('price_lists.items.remove_description', { product: row.code }),
        confirmLabel: t('price_lists.items.remove'),
        tone: 'danger',
    });

    if (confirmed) {
        router.delete(path('price-lists.items.destroy', { priceList: props.priceList.id, item: row.id }), { preserveScroll: true });
    }
}
</script>

<template>
    <Head :title="priceList.name" />
    <div class="kit-page is-full">
        <PriceListHeader :price-list="priceList" />

        <div class="flex flex-col gap-4">
            <Alert tone="info" icon="ph:info">{{ t('price_lists.items.note') }}</Alert>
            <Alert v-if="belowCostCount > 0" tone="danger" icon="ph:warning-octagon" data-testid="below-cost-summary">{{ t('price_lists.items.below_cost_summary', { count: belowCostCount }) }}</Alert>

            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0 flex-1 basis-80"><ListFilters :query="query" /></div>
                <Button icon="ph:plus" data-testid="add-line" @click="add">{{ t('price_lists.items.add') }}</Button>
            </div>

            <DataTable
                :columns="columns"
                :rows="list.rows"
                row-key="id"
                :mobile-columns="['product', 'cost', 'price']"
                v-model:sort="query.sort.value"
                :busy="query.busy.value"
                :title="t('price_lists.tabs.products')"
                :count="list.meta.total"
            >
                <template #cell-product="{ row }">
                    <span class="flex flex-col gap-1" :data-line="row.code">
                        <span class="font-semibold text-ink"><bdi dir="ltr">{{ row.code }}</bdi></span>
                        <span class="text-label text-ink-3">{{ row.name }}</span>
                        <span class="flex flex-wrap items-center gap-1">
                            <Badge v-if="!lines[row.id].ok" tone="warning" size="sm" icon="ph:hourglass" :data-status="row.code" data-state="missing">{{ t('price_lists.items.status.missing') }}</Badge>
                            <Badge v-else-if="lines[row.id].belowCost" tone="danger" size="sm" icon="ph:warning-octagon" :data-status="row.code" data-state="below_cost">{{ t('price_lists.items.status.below_cost') }}</Badge>
                            <Badge v-else tone="success" size="sm" icon="ph:check-circle" :data-status="row.code" data-state="ok">{{ t('price_lists.items.status.ok') }}</Badge>
                            <Badge v-if="!row.product_published" tone="warning" size="sm" icon="ph:cloud-slash" data-testid="not-published">{{ t('price_lists.items.not_published') }}</Badge>
                        </span>
                    </span>
                </template>
                <template #cell-pricing="{ row }">
                    <span class="flex flex-col gap-1">
                        <Badge :tone="row.mode === 'rule' ? 'info' : 'neutral'" size="sm">{{ t(`enums.price_list_mode.${row.mode}`) }}</Badge>
                        <span v-if="row.mode === 'rule'" class="text-label text-ink-3">{{ row.rule_label }}</span>
                        <span v-else class="flex flex-wrap items-center gap-2 text-label text-ink-3">{{ row.override.source_title }} <ConfidenceBadge v-if="row.override.confidence" :confidence="row.override.confidence" /></span>
                    </span>
                </template>
                <template #cell-cost="{ row }">
                    <span class="flex items-center justify-end gap-2" :data-cost="row.code">
                        <template v-if="row.unit_cost">
                            <Num :value="row.unit_cost.value" :decimals="2" />
                            <ConfidenceBadge :confidence="row.unit_cost.confidence" />
                        </template>
                        <Num v-else :value="null" reason-key="price_lists.items.cost_missing" />
                        <IconButton icon="ph:pencil-simple" variant="ghost" size="sm" :label="t('price_lists.items.edit_cost', { product: row.code })" :data-testid="`edit-cost-${row.code}`" @click="editCost(row)" />
                    </span>
                </template>
                <template #cell-price="{ row }">
                    <span :data-price="row.code">
                        <ExplainableNum v-if="lines[row.id].ok" :traced="lines[row.id].priceTraced" :value="lines[row.id].price" :decimals="2" :label="t('price_lists.preview.price')" :sources="sources" />
                        <Num v-else :value="null" reason-key="price_lists.items.price_missing" />
                    </span>
                </template>
                <template #cell-margin="{ row }">
                    <span v-if="lines[row.id].ok" class="flex flex-col items-end" :class="lines[row.id].belowCost ? 'text-danger-ink' : 'text-ink'" :data-margin="row.code">
                        <Num :value="lines[row.id].margin" :decimals="2" />
                        <span class="text-label text-ink-3"><bdi dir="ltr"><Num :value="lines[row.id].marginOnPricePct" :decimals="1" />%</bdi></span>
                    </span>
                    <Num v-else :value="null" reason-key="price_lists.items.price_missing" />
                </template>
                <template #row-actions="{ row }">
                    <span class="flex justify-end gap-1">
                        <IconButton icon="ph:pencil-simple" variant="ghost" size="sm" :label="t('price_lists.items.edit', { product: row.code })" :data-testid="`edit-line-${row.code}`" @click="edit(row)" />
                        <IconButton icon="ph:trash" variant="ghost" size="sm" :label="t('price_lists.items.remove_label', { product: row.code })" :data-testid="`remove-line-${row.code}`" @click="remove(row)" />
                    </span>
                </template>
                <template #empty>
                    <EmptyState
                        compact
                        :icon="filtered ? 'ph:funnel-x' : 'ph:tag'"
                        :title="filtered ? t('filters.filtered_empty_title') : t('price_lists.items.empty_title')"
                        :description="filtered ? t('filters.filtered_empty_description') : t('price_lists.items.empty_description')"
                    >
                        <template #actions>
                            <Button v-if="filtered" variant="secondary" icon="ph:funnel-x" @click="query.clear()">{{ t('filters.clear_all') }}</Button>
                            <Button v-else icon="ph:plus" @click="add">{{ t('price_lists.items.add') }}</Button>
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

        <PriceListLineModal
            v-model:open="lineOpen"
            :price-list-id="priceList.id"
            :row="editing"
            :rules="rules"
            :mode-options="modeOptions"
            :confidence-options="confidenceOptions"
            :source-kind-options="sourceKindOptions"
            :urls="urls"
        />
        <UnitCostModal v-model:open="costOpen" :row="costing" :confidence-options="confidenceOptions" :source-kind-options="sourceKindOptions" :urls="urls" />
    </div>
</template>
