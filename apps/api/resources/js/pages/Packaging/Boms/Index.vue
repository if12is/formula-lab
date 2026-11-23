<script setup>
/**
 * The packaging BOMs, one per SKU (S-12): name, code, how many components, the carton packing and whether it has a pack image, filtered,
 * sorted and paged through ListFilters / useListQuery (state in the URL). A row opens the BOM editor.
 */
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { Badge, Button, DataTable, EmptyState, PageHeader, Pagination } from '@kit';
import ListFilters from '../../../components/list/ListFilters.vue';
import Num from '../../../components/Num.vue';
import BomFormModal from '../../../components/packaging/BomFormModal.vue';
import { useI18n } from '../../../composables/useI18n';
import { useListQuery } from '../../../composables/useListQuery';
import { path } from '../../../lib/routes';

const props = defineProps({
    list: { type: Object, required: true },
    sanadProductOptions: { type: Array, required: true },
});

const { t } = useI18n();
const query = useListQuery(props.list.schema);
const formOpen = ref(false);

const columns = computed(() => [
    { key: 'name', label: t('packaging.bom.columns.name'), sortable: true },
    { key: 'sku_code', label: t('packaging.bom.columns.sku_code'), sortable: true, hideBelow: 'md' },
    { key: 'lines_count', label: t('packaging.bom.columns.lines_count'), sortable: true },
    { key: 'units_per_carton', label: t('packaging.bom.columns.units_per_carton'), sortable: true, hideBelow: 'lg' },
    { key: 'cartons_per_pallet', label: t('packaging.bom.columns.cartons_per_pallet'), hideBelow: 'xl' },
    { key: 'has_image', label: t('packaging.bom.columns.image'), hideBelow: 'md' },
    { key: 'updated_at', label: t('packaging.bom.columns.updated_at'), sortable: true, hideBelow: 'xl' },
]);

const filtered = computed(() => query.activeCount.value > 0);
</script>

<template>
    <Head :title="t('packaging.bom.title')" />
    <div class="kit-page is-full">
        <PageHeader :title="t('packaging.bom.title')" :description="t('packaging.bom.description')">
            <template #actions>
                <Link :href="path('packaging.index')" class="type-body text-primary underline-offset-2 hover:underline">{{ t('packaging.title') }}</Link>
                <Button icon="ph:plus" data-testid="bom-new" @click="formOpen = true">{{ t('packaging.bom.new') }}</Button>
            </template>
        </PageHeader>

        <div class="flex flex-col gap-4">
            <ListFilters :query="query" />

            <DataTable
                :columns="columns"
                :rows="list.rows"
                row-key="id"
                :mobile-columns="['name', 'lines_count', 'has_image']"
                v-model:sort="query.sort.value"
                :busy="query.busy.value"
                :title="t('packaging.bom.title')"
                :count="list.meta.total"
            >
                <template #cell-name="{ row }">
                    <Link :href="path('packaging.boms.show', row.id)" class="flex flex-col text-start" :data-testid="`bom-row-${row.id}`">
                        <span class="font-semibold text-ink">{{ row.name }}</span>
                        <Badge v-if="row.has_sanad_link" tone="info" size="sm" icon="ph:link-simple" class="self-start">{{ t('packaging.bom.sanad_linked') }}</Badge>
                    </Link>
                </template>
                <template #cell-sku_code="{ row }">
                    <bdi v-if="row.sku_code" dir="ltr">{{ row.sku_code }}</bdi>
                    <span v-else class="text-ink-3">{{ t('packaging.bom.no_sku_code') }}</span>
                </template>
                <template #cell-lines_count="{ row }"><Num :value="row.lines_count" :decimals="0" /></template>
                <template #cell-units_per_carton="{ row }"><Num :value="row.units_per_carton" :decimals="0" reason-key="packaging.bom.not_recorded" /></template>
                <template #cell-cartons_per_pallet="{ row }"><Num :value="row.cartons_per_pallet" :decimals="0" reason-key="packaging.bom.not_recorded" /></template>
                <template #cell-has_image="{ row }">
                    <Badge :tone="row.has_image ? 'success' : 'neutral'" size="sm" :icon="row.has_image ? 'ph:image' : 'ph:image-broken'">{{ t(`packaging.bom.has_image.${row.has_image ? 'yes' : 'no'}`) }}</Badge>
                </template>
                <template #cell-updated_at="{ row }">{{ row.updated_at }}</template>
                <template #empty>
                    <EmptyState
                        compact
                        :icon="filtered ? 'ph:funnel-x' : 'ph:package'"
                        :title="filtered ? t('filters.filtered_empty_title') : t('packaging.bom.empty_title')"
                        :description="filtered ? t('filters.filtered_empty_description') : t('packaging.bom.empty_description')"
                    >
                        <template #actions>
                            <Button v-if="filtered" variant="secondary" icon="ph:funnel-x" @click="query.clear()">{{ t('filters.clear_all') }}</Button>
                            <Button v-else icon="ph:plus" @click="formOpen = true">{{ t('packaging.bom.new') }}</Button>
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

        <BomFormModal v-model:open="formOpen" :bom="null" :sanad-product-options="sanadProductOptions" />
    </div>
</template>
