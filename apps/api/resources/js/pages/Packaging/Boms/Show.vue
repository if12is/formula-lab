<script setup>
/**
 * The packaging BOM editor of one SKU (S-12): its packing configuration, the lines (the pack's components, each with its quantity, reject %
 * and piece price, every figure with its source), the pack image, and the cost per unit as the engine works it out for the saved lines. A figure
 * not recorded reads "not recorded" and the cost then lists what is missing: nothing is ever counted as zero.
 */
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Badge, Button, Card, DataTable, DescriptionList, EmptyState, IconButton, PageHeader, useConfirm } from '@kit';
import ConfidenceBadge from '../../../components/materials/ConfidenceBadge.vue';
import Num from '../../../components/Num.vue';
import BomCostPreview from '../../../components/packaging/BomCostPreview.vue';
import BomFormModal from '../../../components/packaging/BomFormModal.vue';
import BomImagePanel from '../../../components/packaging/BomImagePanel.vue';
import BomLineFormModal from '../../../components/packaging/BomLineFormModal.vue';
import { useI18n } from '../../../composables/useI18n';
import { path } from '../../../lib/routes';

const props = defineProps({
    bom: { type: Object, required: true },
    lines: { type: Array, required: true },
    componentOptions: { type: Array, required: true },
    sanadProductOptions: { type: Array, required: true },
    levelOptions: { type: Array, required: true },
    confidenceOptions: { type: Array, required: true },
    sourceKindOptions: { type: Array, required: true },
    limits: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const { t } = useI18n();
const { confirm } = useConfirm();
const bomFormOpen = ref(false);
const lineFormOpen = ref(false);
const editingLine = ref(null);

const columns = computed(() => [
    { key: 'name', label: t('packaging.bom.lines.columns.name') },
    { key: 'level', label: t('packaging.bom.lines.columns.level'), hideBelow: 'md' },
    { key: 'qty_per_unit', label: t('packaging.bom.lines.columns.qty_per_unit') },
    { key: 'reject_pct', label: t('packaging.bom.lines.columns.reject_pct'), hideBelow: 'lg' },
    { key: 'price_egp', label: t('packaging.bom.lines.columns.price_egp') },
]);

const packing = computed(() => [
    { term: t('packaging.bom.detail.units_per_carton'), key: 'units_per_carton' },
    { term: t('packaging.bom.detail.cartons_per_pallet'), key: 'cartons_per_pallet' },
]);

// The cost is asked for again whenever a line or the packing changes.
const signature = computed(() => JSON.stringify([props.bom.units_per_carton, props.bom.cartons_per_pallet, props.lines]));

function addLine() {
    editingLine.value = null;
    lineFormOpen.value = true;
}

function editLine(row) {
    editingLine.value = row;
    lineFormOpen.value = true;
}

async function removeLine(row) {
    const confirmed = await confirm({
        tone: 'danger',
        title: t('packaging.bom.lines.remove_title'),
        description: t('packaging.bom.lines.remove_description'),
        confirmLabel: t('packaging.bom.lines.remove'),
    });

    if (confirmed) {
        router.delete(path('packaging.boms.lines.destroy', { bom: props.bom.id, line: row.id }), { preserveScroll: true });
    }
}

async function removeBom() {
    const confirmed = await confirm({
        tone: 'danger',
        title: t('packaging.bom.detail.delete_title'),
        description: t('packaging.bom.detail.delete_description'),
        confirmLabel: t('packaging.bom.detail.delete'),
    });

    if (confirmed) {
        router.delete(path('packaging.boms.destroy', props.bom.id));
    }
}
</script>

<template>
    <Head :title="bom.name" />
    <div class="kit-page is-full">
        <PageHeader :title="bom.name" :description="bom.sku_code ? undefined : t('packaging.bom.description')">
            <template #actions>
                <Link :href="path('packaging.boms.index')" class="type-body text-primary underline-offset-2 hover:underline">{{ t('packaging.bom.back') }}</Link>
                <Button variant="secondary" icon="ph:pencil-simple" data-testid="bom-edit" @click="bomFormOpen = true">{{ t('packaging.bom.detail.edit') }}</Button>
                <Button variant="danger-soft" icon="ph:trash" data-testid="bom-delete" @click="removeBom">{{ t('packaging.bom.detail.delete') }}</Button>
            </template>
        </PageHeader>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
            <div class="flex min-w-0 flex-col gap-6">
                <Card :title="t('packaging.bom.detail.packing')" data-testid="bom-packing">
                    <div class="mb-3 flex flex-wrap items-center gap-2">
                        <Badge v-if="bom.sku_code" tone="neutral" mono data-testid="bom-sku-code-badge">{{ bom.sku_code }}</Badge>
                        <Badge v-if="bom.sanad_product_name" tone="info" icon="ph:link-simple">{{ bom.sanad_product_name }}</Badge>
                    </div>
                    <DescriptionList :items="packing" :columns="2">
                        <template #units_per_carton><Num :value="bom.units_per_carton" :decimals="0" reason-key="packaging.bom.not_recorded" /></template>
                        <template #cartons_per_pallet><Num :value="bom.cartons_per_pallet" :decimals="0" reason-key="packaging.bom.not_recorded" /></template>
                    </DescriptionList>
                    <p v-if="bom.note" class="mt-3 text-label text-ink-3">{{ bom.note }}</p>
                </Card>

                <Card :title="t('packaging.bom.lines.title')">
                    <template #actions>
                        <Button icon="ph:plus" size="sm" data-testid="bom-line-add" @click="addLine">{{ t('packaging.bom.lines.add') }}</Button>
                    </template>
                    <DataTable
                        :columns="columns"
                        :rows="lines"
                        row-key="id"
                        :mobile-columns="['name', 'qty_per_unit', 'price_egp']"
                        :caption="t('packaging.bom.lines.title')"
                    >
                        <template #cell-name="{ row }">
                            <span class="flex flex-col" :data-testid="`bom-line-${row.id}`">
                                <span class="font-semibold text-ink">{{ row.name }}</span>
                                <span class="text-label text-ink-3">
                                    {{ t(`enums.item_kind.${row.item_kind}`) }}<template v-if="row.neck_finish"> · <bdi dir="ltr">{{ row.neck_finish }}</bdi></template>
                                </span>
                                <span v-if="row.source_id" class="mt-1 flex items-center gap-2">
                                    <ConfidenceBadge :confidence="row.confidence" />
                                    <span class="min-w-0 truncate text-label text-ink-3">{{ row.source_title }}</span>
                                </span>
                                <span v-else class="text-label text-ink-3">{{ t('packaging.bom.not_recorded') }}</span>
                            </span>
                        </template>
                        <template #cell-level="{ row }">{{ t(`enums.pack_level.${row.level}`) }}</template>
                        <template #cell-qty_per_unit="{ row }"><Num :value="row.qty_per_unit" reason-key="packaging.bom.not_recorded" /></template>
                        <template #cell-reject_pct="{ row }"><Num :value="row.reject_pct" reason-key="packaging.bom.not_recorded"><template #suffix> %</template></Num></template>
                        <template #cell-price_egp="{ row }"><Num :value="row.price_egp" reason-key="packaging.bom.not_recorded" /></template>
                        <template #row-actions="{ row }">
                            <span class="flex justify-end gap-1">
                                <IconButton icon="ph:pencil-simple" :label="t('packaging.bom.lines.edit')" variant="ghost" size="sm" :data-testid="`bom-line-edit-${row.id}`" @click="editLine(row)" />
                                <IconButton icon="ph:trash" :label="t('packaging.bom.lines.remove')" variant="danger-soft" size="sm" :data-testid="`bom-line-remove-${row.id}`" @click="removeLine(row)" />
                            </span>
                        </template>
                        <template #empty>
                            <EmptyState compact icon="ph:package" :title="t('packaging.bom.lines.empty_title')" :description="componentOptions.length ? t('packaging.bom.lines.empty_description') : t('packaging.bom.lines.no_components')">
                                <template #actions>
                                    <Button v-if="componentOptions.length" icon="ph:plus" @click="addLine">{{ t('packaging.bom.lines.add') }}</Button>
                                    <Button v-else variant="secondary" :href="path('packaging.index')">{{ t('packaging.title') }}</Button>
                                </template>
                            </EmptyState>
                        </template>
                    </DataTable>
                </Card>
            </div>

            <div class="flex min-w-0 flex-col gap-6">
                <BomCostPreview :url="urls.cost" :refresh-key="signature" />
                <BomImagePanel :bom="bom" :limits="limits" />
            </div>
        </div>

        <BomFormModal v-model:open="bomFormOpen" :bom="bom" :sanad-product-options="sanadProductOptions" />
        <BomLineFormModal
            v-model:open="lineFormOpen"
            :bom-id="bom.id"
            :line="editingLine"
            :component-options="componentOptions"
            :level-options="levelOptions"
            :confidence-options="confidenceOptions"
            :source-kind-options="sourceKindOptions"
            :urls="urls"
        />
    </div>
</template>
