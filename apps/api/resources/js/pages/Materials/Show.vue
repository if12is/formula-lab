<script setup>
/**
 * One trade material: its facts, and three tabs.
 *   Properties  sourced values (single or range) per property, with the one the engine's resolveValue picks, why the
 *               others lost, and conflicts highlighted
 *   Prices      dated price quotes with source and confidence; the one the engine would cost with is marked
 *   Composition the components (CAS / INCI at an exact % w/w, each with its source) the compliance engine reads
 *   Audit       every change to the material, its values and its prices, from audit_log
 * Edit and the add forms are modals; the page keeps the tab in its address.
 */
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { path } from '../../lib/routes';
import { Alert, Badge, Button, Card, DataTable, DescriptionList, EmptyState, IconButton, PageHeader, Switch, TabPanel, Tabs, useConfirm } from '@kit';
import CompositionModal from '../../components/materials/CompositionModal.vue';
import ConfidenceBadge from '../../components/materials/ConfidenceBadge.vue';
import MaterialFormModal from '../../components/materials/MaterialFormModal.vue';
import PriceModal from '../../components/materials/PriceModal.vue';
import PropertyModal from '../../components/materials/PropertyModal.vue';
import PropertyValueText from '../../components/materials/PropertyValueText.vue';
import Num from '../../components/Num.vue';
import StatusBadge from '../../components/StatusBadge.vue';
import { useI18n } from '../../composables/useI18n';
import { useProperties } from '../../composables/useProperties';
import { compositionSummary } from '../../lib/compliance';
import { pickPrice, priceInEgp, resolveProperties } from '../../lib/engine';

const props = defineProps({
    material: { type: Object, required: true },
    properties: { type: Array, required: true },
    prices: { type: Array, required: true },
    composition: { type: Array, required: true },
    audit: { type: Array, required: true },
    tab: { type: String, required: true },
    statusOptions: { type: Array, required: true },
    confidenceOptions: { type: Array, required: true },
    sourceKindOptions: { type: Array, required: true },
    currencyOptions: { type: Array, required: true },
    urls: { type: Object, required: true },
});

const { t, has, locale } = useI18n();
const { confirm } = useConfirm();
const { label: propertyLabel } = useProperties();

const tab = ref(props.tab);
const editing = ref(false);
const addingProperty = ref(false);
const addingPrice = ref(false);
const editedComponent = ref(null);
const componentModal = ref(false);

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
    { value: 'composition', label: t('materials.tabs.composition'), count: props.composition.length },
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
/** What each quote is in EGP, by the engine (convertMoney) with the rate the server found for the quote date. */
const equivalents = computed(() => Object.fromEntries(props.prices.map((price) => [price.id, priceInEgp(price)])));
const inEgp = (row) => equivalents.value[row.id];
const addRateHref = (row) => path('settings.exchange-rates.index', { new_currency: row.currency, new_date: row.quoted_on });
const estimateOnly = computed(() => costingPrice.value?.confidence === 'L7_estimate');

const total = computed(() => (props.composition.length ? compositionSummary(props.composition) : null));
// Listed as the whole material but not adding up to 100: the engine would trust a list that leaves part of the material out.
const incompleteTotal = computed(() => total.value !== null && !total.value.withinTolerance && props.material.composition_complete);

const compositionColumns = computed(() => [
    { key: 'cas', label: t('materials.composition.columns.cas') },
    { key: 'inci', label: t('materials.composition.columns.inci'), hideBelow: 'sm' },
    { key: 'pct_ww', label: t('materials.composition.columns.pct'), align: 'end' },
    { key: 'source', label: t('materials.composition.columns.source'), hideBelow: 'md' },
    { key: 'confidence', label: t('materials.composition.columns.confidence') },
    { key: 'valid_from', label: t('materials.composition.columns.valid_from'), hideBelow: 'lg' },
    { key: 'actions', label: t('materials.composition.columns.actions'), align: 'end' },
]);

function addComponent() {
    editedComponent.value = null;
    componentModal.value = true;
}

function editComponent(row) {
    editedComponent.value = row;
    componentModal.value = true;
}

function setFlag(flag, value) {
    router.put(path('materials.composition.flags', props.material.id), { [flag]: value }, { preserveScroll: true });
}

async function removeComponent(row) {
    const confirmed = await confirm({
        tone: 'danger',
        title: t('materials.composition.remove_title'),
        description: t('materials.composition.remove_description', { component: row.cas ?? row.inci }),
        confirmLabel: t('materials.remove'),
    });

    if (confirmed) {
        router.delete(path('materials.composition.destroy', [props.material.id, row.id]), { preserveScroll: true });
    }
}

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
    { key: 'egp', label: t('materials.price.columns.egp'), align: 'end' },
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
        description: t('materials.property.remove_description', { property: propertyLabel(row.property) }),
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
                    <Card v-for="group in groups" :key="group.key" :data-property="group.property" :title="group.category ? t('materials.property.with_category', { property: propertyLabel(group.property), category: group.category }) : propertyLabel(group.property)">
                        <template #actions>
                            <span v-if="group.resolution?.chosenBy === 'rule'" class="flex flex-wrap items-center gap-2 text-label text-ink-2">
                                {{ t('materials.resolve.picked') }}
                                <span class="font-semibold text-ink"><PropertyValueText :value="group.resolution.chosen.value ?? null" :min="group.resolution.chosen.min ?? null" :max="group.resolution.chosen.max ?? null" :unit="group.resolution.chosen.unit" /></span>
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
                            :caption="propertyLabel(group.property)"
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
                                    :label="t('materials.property.remove_label', { property: propertyLabel(group.property) })"
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
                    :mobile-columns="['used', 'amount', 'egp', 'quoted_on']"
                    :caption="t('materials.tabs.prices')"
                >
                    <template #cell-used="{ row }">
                        <Badge v-if="costingPrice && costingPrice._id === row.id" tone="success" icon="ph:check-circle">{{ t('materials.price.used') }}</Badge>
                        <span v-else class="text-ink-3">{{ t('common.no_data') }}</span>
                    </template>
                    <template #cell-amount="{ row }">
                        <span class="whitespace-nowrap" :data-price-currency="row.currency">
                            <Num v-if="row.currency === 'EGP'" :value="row.amount" :decimals="2" />
                            <Num v-else :value="row.amount" :currency="row.currency" :decimals="2" />
                            <span class="text-label text-ink-3"> {{ row.currency === 'EGP' ? t('formulas.units.egp_per_kg') : t('formulas.units.per_kg') }}</span>
                        </span>
                    </template>
                    <template #cell-egp="{ row }">
                        <span v-if="row.currency !== 'EGP'" class="flex flex-col items-end gap-0.5" data-testid="price-egp">
                            <span class="whitespace-nowrap">
                                <Num :value="inEgp(row).egp" :decimals="2" reason-key="materials.price.egp_missing_reason" /> <span v-if="inEgp(row).egp !== null" class="text-label text-ink-3">{{ t('formulas.units.egp_per_kg') }}</span>
                            </span>
                            <span v-if="inEgp(row).fx" class="text-label text-ink-3" data-testid="price-fx">
                                {{ t('exchange_rates.display.rate_prefix') }} <Num :value="inEgp(row).fx.rate" /> · <bdi dir="ltr" class="tnum">{{ inEgp(row).fx.date }}</bdi> · {{ inEgp(row).fx.source }}
                            </span>
                            <a v-else-if="inEgp(row).missing" :href="addRateHref(row)" class="text-label text-primary-ink underline underline-offset-2" data-testid="price-add-rate">
                                {{ t('materials.price.add_rate', { currency: row.currency, date: row.quoted_on }) }}
                            </a>
                        </span>
                    </template>
                    <template #cell-quoted_on="{ row }"><bdi dir="ltr" class="tnum">{{ row.quoted_on }}</bdi></template>
                    <template #cell-source="{ row }">{{ sourceLabel(row) }}</template>
                    <template #cell-confidence="{ row }"><ConfidenceBadge :confidence="row.confidence" /></template>
                    <template #cell-actions="{ row }">
                        <IconButton icon="ph:trash" variant="danger-soft" size="sm" :label="t('materials.price.remove_label')" @click="removePrice(row)" />
                    </template>
                </DataTable>
            </TabPanel>

            <TabPanel value="composition">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                    <p class="max-w-prose text-label text-ink-3">{{ t('materials.composition.intro') }}</p>
                    <Button icon="ph:plus" data-testid="add-component" @click="addComponent">{{ t('materials.composition.add') }}</Button>
                </div>

                <Card class="mb-4" :title="t('materials.composition.flags_title')">
                    <div class="flex flex-col gap-3">
                        <Switch
                            :model-value="material.composition_complete"
                            :label="t('materials.composition.complete')"
                            :description="t('materials.composition.complete_hint')"
                            data-testid="composition-complete"
                            @update:model-value="(value) => setFlag('composition_complete', value)"
                        />
                        <Switch
                            :model-value="material.is_fragrance"
                            :label="t('materials.composition.is_fragrance')"
                            :description="t('materials.composition.is_fragrance_hint')"
                            data-testid="is-fragrance"
                            @update:model-value="(value) => setFlag('is_fragrance', value)"
                        />
                    </div>
                </Card>

                <Alert v-if="composition.length === 0" tone="warning" icon="ph:warning" class="mb-4" :title="t('materials.composition.unknown_title')" data-testid="composition-unknown">
                    {{ t('materials.composition.unknown_description') }}
                </Alert>
                <Alert v-else-if="total.over" tone="danger" icon="ph:warning" class="mb-4" :title="t('materials.composition.over_title')" data-testid="composition-over">
                    {{ t('materials.composition.over_description') }}
                </Alert>
                <Alert v-else-if="incompleteTotal" tone="warning" icon="ph:warning" class="mb-4" :title="t('materials.composition.sum_title')">
                    {{ t('materials.composition.sum_description') }}
                </Alert>

                <EmptyState
                    v-if="composition.length === 0"
                    variant="dashed"
                    icon="ph:flask"
                    :title="t('materials.composition.empty_title')"
                    :description="t('materials.composition.empty_description')"
                >
                    <template #actions><Button icon="ph:plus" @click="addComponent">{{ t('materials.composition.add') }}</Button></template>
                </EmptyState>

                <template v-else>
                    <DataTable
                        :columns="compositionColumns"
                        :rows="composition"
                        row-key="id"
                        :mobile-columns="['cas', 'pct_ww', 'confidence']"
                        :caption="t('materials.tabs.composition')"
                    >
                        <template #cell-cas="{ row }"><bdi v-if="row.cas" dir="ltr" class="font-mono text-label">{{ row.cas }}</bdi><span v-else class="text-ink-3">{{ t('common.no_data') }}</span></template>
                        <template #cell-inci="{ row }"><bdi v-if="row.inci" dir="ltr">{{ row.inci }}</bdi><span v-else class="text-ink-3">{{ t('common.no_data') }}</span></template>
                        <template #cell-pct_ww="{ row }"><span class="whitespace-nowrap"><Num :value="row.pct_ww" /> <span class="text-label text-ink-3">%</span></span></template>
                        <template #cell-source="{ row }">{{ row.source_title }}</template>
                        <template #cell-confidence="{ row }"><ConfidenceBadge :confidence="row.confidence" /></template>
                        <template #cell-valid_from="{ row }"><bdi dir="ltr" class="tnum">{{ row.valid_from }}</bdi></template>
                        <template #cell-actions="{ row }">
                            <span class="flex justify-end gap-1">
                                <IconButton icon="ph:pencil-simple" size="sm" variant="secondary" :label="t('materials.composition.edit_label', { component: row.cas ?? row.inci })" @click="editComponent(row)" />
                                <IconButton icon="ph:trash" size="sm" variant="danger-soft" :label="t('materials.composition.remove_label', { component: row.cas ?? row.inci })" @click="removeComponent(row)" />
                            </span>
                        </template>
                    </DataTable>
                    <p class="mt-3 flex flex-wrap items-baseline gap-2 text-label text-ink-2" data-testid="composition-total">
                        {{ t('materials.composition.total') }}
                        <span class="font-semibold text-ink"><Num :value="total.sum" /> %</span>
                    </p>
                </template>
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
        />
        <CompositionModal v-model:open="componentModal" :material-id="material.id" :component="editedComponent" :urls="urls" :confidence-options="confidenceOptions" :source-kind-options="sourceKindOptions" />
        <PriceModal v-model:open="addingPrice" :material-id="material.id" :urls="urls" :currency-options="currencyOptions" :confidence-options="confidenceOptions" :source-kind-options="sourceKindOptions" />
    </div>
</template>
