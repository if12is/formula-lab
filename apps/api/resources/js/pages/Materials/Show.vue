<script setup>
/**
 * One trade material: its facts, and three tabs.
 *   Properties  sourced values (single or range) per property, with the one the engine's resolveValue picks, why the
 *               others lost, and conflicts highlighted
 *   Prices      dated price quotes with source and confidence; the one the engine would cost with is marked
 *   Audit       every change to the material, its values and its prices, from audit_log
 * Edit and the add forms are modals; the page keeps the tab in its address.
 */
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { path } from '../../lib/routes';
import { Alert, Badge, Button, Card, DataTable, DescriptionList, EmptyState, IconButton, PageHeader, TabPanel, Tabs, useConfirm } from '@kit';
import ConfidenceBadge from '../../components/materials/ConfidenceBadge.vue';
import MaterialFormModal from '../../components/materials/MaterialFormModal.vue';
import PriceModal from '../../components/materials/PriceModal.vue';
import PropertyModal from '../../components/materials/PropertyModal.vue';
import PropertyValueText from '../../components/materials/PropertyValueText.vue';
import Num from '../../components/Num.vue';
import StatusBadge from '../../components/StatusBadge.vue';
import { useI18n } from '../../composables/useI18n';
import { pickPrice, resolveProperties } from '../../lib/engine';

const props = defineProps({
    material: { type: Object, required: true },
    properties: { type: Array, required: true },
    prices: { type: Array, required: true },
    audit: { type: Array, required: true },
    propertyNames: { type: Array, required: true },
    tab: { type: String, required: true },
    statusOptions: { type: Array, required: true },
    confidenceOptions: { type: Array, required: true },
    sourceKindOptions: { type: Array, required: true },
    urls: { type: Object, required: true },
});

const { t, has, locale } = useI18n();
const { confirm } = useConfirm();

const tab = ref(props.tab);
const editing = ref(false);
const addingProperty = ref(false);
const addingPrice = ref(false);

function selectTab(value) {
    tab.value = value;
    const url = new URL(window.location.href);
    url.searchParams.set('tab', value);
    // Only the address changes; Inertia's own history state stays as it is.
    window.history.replaceState(window.history.state, '', url);
}

const tabs = computed(() => [
    { value: 'properties', label: t('materials.tabs.properties'), count: props.properties.length },
    { value: 'prices', label: t('materials.tabs.prices'), count: props.prices.length },
    { value: 'audit', label: t('materials.tabs.audit'), count: props.audit.length },
]);

const facts = computed(() => [
    { term: t('materials.fields.substance'), key: 'substance' },
    { term: t('materials.fields.function'), value: props.material.function ?? t('common.no_data') },
    { term: t('materials.fields.grade'), value: props.material.grade ?? t('common.no_data') },
    { term: t('materials.fields.worst_price_confidence'), key: 'confidence' },
    { term: t('materials.fields.updated_at'), value: props.material.updated_at ?? t('common.no_data'), mono: true },
]);

const groups = computed(() => resolveProperties(props.properties));
const costingPrice = computed(() => pickPrice(props.prices.map((price) => ({ ...price })), props.material.id));
const estimateOnly = computed(() => costingPrice.value?.confidence === 'L7_estimate');

const sourceLabel = (row) => (row.source_kind === 'unverified_entry' ? t('materials.unverified_source') : row.source_title);

const propertyColumns = computed(() => [
    { key: 'picked', label: t('materials.property.columns.engine') },
    { key: 'value', label: t('materials.property.columns.value') },
    { key: 'unit', label: t('materials.property.columns.unit'), hideBelow: 'sm' },
    { key: 'source', label: t('materials.property.columns.source'), hideBelow: 'md' },
    { key: 'confidence', label: t('materials.property.columns.confidence') },
    { key: 'valid_from', label: t('materials.property.columns.valid_from'), hideBelow: 'lg' },
    { key: 'lot_no', label: t('materials.property.columns.lot_no'), hideBelow: 'lg' },
    { key: 'actions', label: t('materials.property.columns.actions'), align: 'end' },
]);

const priceColumns = computed(() => [
    { key: 'used', label: t('materials.price.columns.used') },
    { key: 'amount', label: t('materials.price.columns.amount'), align: 'end' },
    { key: 'quoted_on', label: t('materials.price.columns.quoted_on') },
    { key: 'source', label: t('materials.price.columns.source'), hideBelow: 'md' },
    { key: 'confidence', label: t('materials.price.columns.confidence') },
    { key: 'actions', label: t('materials.price.columns.actions'), align: 'end' },
]);

const auditColumns = computed(() => [
    { key: 'at', label: t('materials.audit.columns.at') },
    { key: 'action', label: t('materials.audit.columns.action') },
    { key: 'subject', label: t('materials.audit.columns.subject'), hideBelow: 'sm' },
    { key: 'changes', label: t('materials.audit.columns.changes') },
    { key: 'actor', label: t('materials.audit.columns.actor'), hideBelow: 'lg' },
]);

const auditRows = computed(() => props.audit.map((entry) => ({ ...entry, id: String(entry.id) })));
const AUDIT_TONES = { create: 'success', update: 'info', delete: 'danger' };
const when = (iso) => new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short', numberingSystem: 'latn' }).format(new Date(iso));
const fieldLabel = (field) => (has(`materials.audit.fields.${field}`) ? t(`materials.audit.fields.${field}`) : field);
const auditValue = (field, value) => {
    if (value === null) {
        return t('common.no_data');
    }

    if (field === 'confidence' && has(`enums.confidence.${value}`)) {
        return t(`enums.confidence.${value}`);
    }

    return field === 'status' && has(`enums.trade_material_status.${value}`) ? t(`enums.trade_material_status.${value}`) : value;
};

async function removeProperty(row) {
    const confirmed = await confirm({
        tone: 'danger',
        title: t('materials.property.remove_title'),
        description: t('materials.property.remove_description', { property: row.property }),
        confirmLabel: t('materials.remove'),
    });

    if (confirmed) {
        router.delete(path('materials.properties.destroy', [props.material.id, row.id]), { preserveScroll: true });
    }
}

async function removePrice(row) {
    const confirmed = await confirm({
        tone: 'danger',
        title: t('materials.price.remove_title'),
        description: t('materials.price.remove_description'),
        confirmLabel: t('materials.remove'),
    });

    if (confirmed) {
        router.delete(path('materials.prices.destroy', [props.material.id, row.id]), { preserveScroll: true });
    }
}
</script>

<template>
    <Head :title="material.trade_name" />
    <div class="kit-page">
        <PageHeader :title="material.trade_name" :back="path('materials.index')" :back-label="t('materials.title')">
            <template #meta>
                <span class="flex flex-wrap items-center gap-2">
                    <StatusBadge kind="trade_material_status" :status="material.status" />
                    <Badge v-if="material.is_mixture" tone="neutral">{{ t('materials.mixture') }}</Badge>
                    <Badge v-if="material.sanad_linked" tone="info" icon="ph:link-simple">{{ t('materials.sanad_linked') }}</Badge>
                </span>
            </template>
            <template #actions>
                <Button variant="secondary" icon="ph:pencil-simple" @click="editing = true">{{ t('materials.edit') }}</Button>
            </template>
        </PageHeader>

        <Card class="mb-6">
            <DescriptionList :items="facts" :columns="3">
                <template #substance>
                    <span v-if="material.substance" class="flex flex-col">
                        <span>{{ material.substance.label }}</span>
                        <bdi v-if="material.substance.description" dir="ltr" class="text-label text-ink-3">{{ material.substance.description }}</bdi>
                    </span>
                    <span v-else class="text-ink-3">{{ material.is_mixture ? t('materials.mixture_no_substance') : t('common.no_data') }}</span>
                </template>
                <template #confidence>
                    <ConfidenceBadge v-if="material.worst_price_confidence" :confidence="material.worst_price_confidence" />
                    <span v-else class="text-ink-3">{{ t('materials.no_price') }}</span>
                </template>
            </DescriptionList>
        </Card>

        <Tabs :model-value="tab" :items="tabs" :aria-label="material.trade_name" @update:model-value="selectTab">
            <TabPanel value="properties">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                    <p class="max-w-prose text-label text-ink-3">{{ t('materials.property.intro') }}</p>
                    <Button icon="ph:plus" @click="addingProperty = true">{{ t('materials.property.add') }}</Button>
                </div>

                <EmptyState
                    v-if="groups.length === 0"
                    variant="dashed"
                    icon="ph:flask"
                    :title="t('materials.property.empty_title')"
                    :description="t('materials.property.empty_description')"
                >
                    <template #actions><Button icon="ph:plus" @click="addingProperty = true">{{ t('materials.property.add') }}</Button></template>
                </EmptyState>

                <div v-else class="flex flex-col gap-4">
                    <Card v-for="group in groups" :key="group.property" :data-property="group.property" :title="group.property">
                        <template #actions>
                            <span v-if="group.resolution?.chosenBy === 'rule'" class="flex flex-wrap items-center gap-2 text-label text-ink-2">
                                {{ t('materials.resolve.picked') }}
                                <span class="font-semibold text-ink"><PropertyValueText :value="group.resolution.chosen.value ?? null" :min="group.resolution.chosen.min ?? null" :max="group.resolution.chosen.max ?? null" /> <bdi dir="ltr">{{ group.resolution.chosen.unit }}</bdi></span>
                                <ConfidenceBadge :confidence="group.resolution.chosen.confidence" />
                            </span>
                        </template>

                        <Alert v-if="group.resolution?.conflict" tone="warning" icon="ph:warning" class="mb-3" :title="t('materials.resolve.conflict_title')">
                            {{ t('materials.resolve.conflict_description', { confidence: t(`enums.confidence.${group.resolution.conflict.confidence}`) }) }}
                        </Alert>

                        <DataTable
                            :columns="propertyColumns"
                            :rows="group.rows"
                            :row-key="(entry) => entry.row.id"
                            :row-tone="(entry) => (entry.conflicted ? 'warning' : null)"
                            :mobile-columns="['picked', 'value', 'confidence']"
                            :caption="group.property"
                        >
                            <template #cell-picked="{ row: entry }">
                                <Badge v-if="entry.picked" tone="success" icon="ph:check-circle">{{ t('materials.resolve.chosen') }}</Badge>
                                <span v-else class="text-label text-ink-3">{{ entry.reasonKey ? t(entry.reasonKey) : t('common.no_data') }}</span>
                            </template>
                            <template #cell-value="{ row: entry }">
                                <span class="flex flex-col">
                                    <PropertyValueText :value="entry.row.value" :min="entry.row.min" :max="entry.row.max" />
                                    <span v-if="entry.row.origin === 'substance'" class="text-label text-ink-3">{{ t('materials.property.from_substance') }}</span>
                                </span>
                            </template>
                            <template #cell-unit="{ row: entry }"><bdi dir="ltr">{{ entry.row.unit }}</bdi></template>
                            <template #cell-source="{ row: entry }">{{ sourceLabel(entry.row) }}</template>
                            <template #cell-confidence="{ row: entry }"><ConfidenceBadge :confidence="entry.row.confidence" /></template>
                            <template #cell-valid_from="{ row: entry }"><bdi dir="ltr" class="tnum">{{ entry.row.valid_from }}</bdi></template>
                            <template #cell-lot_no="{ row: entry }">{{ entry.row.lot_no ?? t('common.no_data') }}</template>
                            <template #cell-actions="{ row: entry }">
                                <IconButton
                                    v-if="entry.row.origin === 'material'"
                                    icon="ph:trash"
                                    variant="danger-soft"
                                    size="sm"
                                    :label="t('materials.property.remove_label', { property: group.property })"
                                    @click="removeProperty(entry.row)"
                                />
                            </template>
                        </DataTable>
                    </Card>
                </div>
            </TabPanel>

            <TabPanel value="prices">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                    <p class="max-w-prose text-label text-ink-3">{{ t('materials.price.intro') }}</p>
                    <Button icon="ph:plus" @click="addingPrice = true">{{ t('materials.price.add') }}</Button>
                </div>

                <Alert v-if="estimateOnly" tone="warning" icon="ph:warning" class="mb-4" :title="t('materials.price.estimate_only_title')">
                    {{ t('materials.price.estimate_only_description') }}
                </Alert>

                <EmptyState
                    v-if="prices.length === 0"
                    variant="dashed"
                    icon="ph:currency-circle-dollar"
                    :title="t('materials.price.empty_title')"
                    :description="t('materials.price.empty_description')"
                >
                    <template #actions><Button icon="ph:plus" @click="addingPrice = true">{{ t('materials.price.add') }}</Button></template>
                </EmptyState>

                <DataTable
                    v-else
                    :columns="priceColumns"
                    :rows="prices"
                    row-key="id"
                    :mobile-columns="['used', 'amount', 'quoted_on']"
                    :caption="t('materials.tabs.prices')"
                >
                    <template #cell-used="{ row }">
                        <Badge v-if="costingPrice && costingPrice._id === row.id" tone="success" icon="ph:check-circle">{{ t('materials.price.used') }}</Badge>
                        <span v-else class="text-ink-3">{{ t('common.no_data') }}</span>
                    </template>
                    <template #cell-amount="{ row }"><Num :value="row.amount" :decimals="2" /> <span class="text-label text-ink-3">{{ t('formulas.units.egp_per_kg') }}</span></template>
                    <template #cell-quoted_on="{ row }"><bdi dir="ltr" class="tnum">{{ row.quoted_on }}</bdi></template>
                    <template #cell-source="{ row }">{{ sourceLabel(row) }}</template>
                    <template #cell-confidence="{ row }"><ConfidenceBadge :confidence="row.confidence" /></template>
                    <template #cell-actions="{ row }">
                        <IconButton icon="ph:trash" variant="danger-soft" size="sm" :label="t('materials.price.remove_label')" @click="removePrice(row)" />
                    </template>
                </DataTable>
            </TabPanel>

            <TabPanel value="audit">
                <p class="mb-4 max-w-prose text-label text-ink-3">{{ t('materials.audit.intro') }}</p>

                <EmptyState v-if="audit.length === 0" variant="dashed" icon="ph:clock-counter-clockwise" :title="t('materials.audit.empty_title')" :description="t('materials.audit.empty_description')" />

                <DataTable v-else :columns="auditColumns" :rows="auditRows" row-key="id" :mobile-columns="['at', 'action', 'changes']" :caption="t('materials.tabs.audit')">
                    <template #cell-at="{ row }"><span class="whitespace-nowrap tnum">{{ when(row.at) }}</span></template>
                    <template #cell-action="{ row }"><Badge :tone="AUDIT_TONES[row.action]">{{ t(`enums.audit_action.${row.action}`) }}</Badge></template>
                    <template #cell-subject="{ row }">{{ t(`materials.audit.tables.${row.table}`) }}</template>
                    <template #cell-changes="{ row }">
                        <ul class="flex flex-col gap-0.5 text-label">
                            <li v-for="change in row.changes" :key="change.field">
                                <span class="text-ink-3">{{ fieldLabel(change.field) }}:</span>
                                <template v-if="row.action === 'update'"> <bdi dir="ltr">{{ auditValue(change.field, change.before) }}</bdi> → <bdi dir="ltr">{{ auditValue(change.field, change.after) }}</bdi></template>
                                <bdi v-else dir="ltr"> {{ auditValue(change.field, change.after ?? change.before) }}</bdi>
                            </li>
                            <li v-if="row.reason" class="text-ink-3">{{ row.reason }}</li>
                        </ul>
                    </template>
                    <template #cell-actor="{ row }">{{ row.actor_id ?? t('materials.audit.system') }}</template>
                </DataTable>
            </TabPanel>
        </Tabs>

        <MaterialFormModal v-model:open="editing" mode="edit" :material="material" :urls="urls" :status-options="statusOptions" />
        <PropertyModal
            v-model:open="addingProperty"
            :material-id="material.id"
            :urls="urls"
            :confidence-options="confidenceOptions"
            :source-kind-options="sourceKindOptions"
            :property-suggestions="propertyNames"
        />
        <PriceModal v-model:open="addingPrice" :material-id="material.id" :urls="urls" :confidence-options="confidenceOptions" :source-kind-options="sourceKindOptions" />
    </div>
</template>
