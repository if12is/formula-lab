<script setup>
/**
 * Adds a product to a price list, or changes how a line is priced. The price is never typed as a result: a line is priced by
 * a margin rule of the pricing settings, or by an agreed price that carries its source and confidence. Before saving, the
 * engine shows the margin against the product's unit cost (and warns when the price is below it).
 */
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Alert, Button, FormField, Modal, SegmentedControl, Select } from '@kit';
import { useI18n } from '../../composables/useI18n';
import { path } from '../../lib/routes';
import { previewInput, priceSources } from '../../lib/priceList';
import DecimalField from '../form/DecimalField.vue';
import EntitySelect from '../form/EntitySelect.vue';
import SourceSelect from '../materials/SourceSelect.vue';
import LinePreview from './LinePreview.vue';

const props = defineProps({
    priceListId: { type: String, required: true },
    /** The line being edited (a row of PriceListDetailService::itemRow), or null to add a product. */
    row: { type: Object, default: null },
    rules: { type: Array, required: true },
    modeOptions: { type: Array, required: true },
    confidenceOptions: { type: Array, required: true },
    sourceKindOptions: { type: Array, required: true },
    urls: { type: Object, required: true },
});

const open = defineModel('open', { type: Boolean, default: false });
const { t } = useI18n();

const editing = computed(() => props.row !== null);
const blank = () => ({
    formula_id: props.row?.formula_id ?? null,
    mode: props.row?.mode ?? 'rule',
    rule_key: props.row?.rule_key ?? null,
    override_price: props.row?.override?.price ?? '',
    override_source_id: props.row?.override?.source_id ?? null,
    override_confidence: props.row?.override?.confidence ?? undefined,
});
const form = useForm(blank());
const sourceLabel = ref('');
const source = ref(null);
const productCost = ref(null);
const productLabel = ref('');
const costSource = ref(null);

watch(open, (isOpen) => {
    if (isOpen) {
        form.defaults(blank()).reset();
        form.clearErrors();
        sourceLabel.value = props.row?.override?.source_title ?? '';
        source.value = props.row?.override ? { id: props.row.override.source_id, retrievedAt: props.row.engine?.spec?.price?.validFrom ?? null } : null;
        productCost.value = props.row?.engine?.unitCost ?? null;
        costSource.value = props.row?.unit_cost ?? null;
        productLabel.value = props.row ? `${props.row.code} · ${props.row.name}` : '';
    }
});

const ruleOptions = computed(() => props.rules.map((rule) => ({ value: rule.key, label: rule.label })));
const chosenRule = computed(() => props.rules.find((rule) => rule.key === form.rule_key) ?? null);
const preview = computed(() => previewInput({
    unitCost: productCost.value ?? undefined,
    rules: props.rules,
    form,
    source: source.value,
}));
const showPreview = computed(() => form.formula_id !== null);
const sources = computed(() => priceSources([{ unit_cost: costSource.value, override: { source_id: form.override_source_id, source_title: sourceLabel.value } }], props.rules));

function productPicked(option) {
    productCost.value = option.unit_cost ?? null;
    costSource.value = option.unit_cost ? { source_id: option.unit_cost.sourceId, source_title: option.unit_cost_source_title } : null;
}

function sourcePicked(option) {
    sourceLabel.value = option.label;
    source.value = { id: option.value, retrievedAt: option.retrieved_at ?? null };
}

function submit() {
    const options = { preserveScroll: true, onSuccess: () => { open.value = false; } };
    const send = form.transform((data) => ({
        ...data,
        rule_key: data.mode === 'rule' ? data.rule_key : null,
        override_price: data.mode === 'override' ? data.override_price : null,
        override_source_id: data.mode === 'override' ? data.override_source_id : null,
        override_confidence: data.mode === 'override' ? (data.override_confidence ?? null) : null,
        formula_id: editing.value ? undefined : data.formula_id,
    }));

    if (editing.value) {
        send.put(path('price-lists.items.update', { priceList: props.priceListId, item: props.row.id }), options);
    } else {
        send.post(path('price-lists.items.store', props.priceListId), options);
    }
}
</script>

<template>
    <Modal v-model:open="open" :title="editing ? t('price_lists.line.title_edit') : t('price_lists.line.title_add')" :description="t('price_lists.line.description')" size="lg">
        <form id="price-line-form" class="flex flex-col gap-4" novalidate data-testid="line-form" @submit.prevent="submit">
            <p v-if="editing" class="text-ink" data-testid="line-product"><bdi dir="ltr" class="font-semibold">{{ row.code }}</bdi> · {{ row.name }}</p>
            <EntitySelect
                v-else
                v-model="form.formula_id"
                label-key="price_lists.line.product"
                hint-key="price_lists.line.product_hint"
                :search-url="urls.products"
                :error="form.errors.formula_id"
                required
                @select="productPicked"
            />

            <FormField :label="t('price_lists.line.mode')" :hint="t('price_lists.line.mode_hint')" :error="form.errors.mode" required>
                <SegmentedControl v-model="form.mode" :options="modeOptions" :aria-label="t('price_lists.line.mode')" />
            </FormField>

            <template v-if="form.mode === 'rule'">
                <FormField :label="t('price_lists.line.rule')" :error="form.errors.rule_key" required>
                    <Select v-model="form.rule_key" :options="ruleOptions" :placeholder="t('common.select_placeholder')" />
                </FormField>
                <Alert v-if="rules.length === 0" tone="warning" icon="ph:warning" data-testid="no-rules">{{ t('price_lists.line.no_rules') }}</Alert>
                <dl v-else-if="chosenRule" class="grid gap-2 rounded-lg border border-line p-3 text-label sm:grid-cols-2" data-testid="rule-card">
                    <div>
                        <dt class="text-ink-3">{{ t('price_lists.rule.basis') }}</dt>
                        <dd class="text-ink">{{ chosenRule.basis ? t(`price_lists.rule.bases.${chosenRule.basis}`) : t('common.no_data') }}</dd>
                    </div>
                    <div>
                        <dt class="text-ink-3">{{ t('price_lists.rule.margin') }}</dt>
                        <dd class="text-ink tnum"><bdi dir="ltr">{{ chosenRule.margin_pct ?? t('common.no_data') }}</bdi>%</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-ink-3">{{ t('price_lists.rule.source') }}</dt>
                        <dd class="text-ink">{{ chosenRule.source ?? t('common.no_data') }}</dd>
                    </div>
                </dl>
            </template>

            <template v-else>
                <DecimalField v-model="form.override_price" label-key="price_lists.line.override_price" hint-key="price_lists.line.override_hint" :suffix="t('price_lists.unit_egp')" :error="form.errors.override_price" required />
                <div class="grid gap-4 sm:grid-cols-2">
                    <SourceSelect v-model="form.override_source_id" :urls="urls" :kind-options="sourceKindOptions" :selected-label="sourceLabel" :error="form.errors.override_source_id" label-key="price_lists.line.override_source" required @select="sourcePicked" />
                    <FormField :label="t('price_lists.line.override_confidence')" :error="form.errors.override_confidence" required>
                        <Select v-model="form.override_confidence" :options="confidenceOptions" :placeholder="t('common.select_placeholder')" />
                    </FormField>
                </div>
            </template>

            <template v-if="showPreview">
                <p v-if="productLabel" class="sr-only">{{ productLabel }}</p>
                <LinePreview :engine="preview" :sources="sources" />
            </template>
        </form>
        <template #footer>
            <Button variant="ghost" @click="open = false">{{ t('common.cancel') }}</Button>
            <Button type="submit" form="price-line-form" :loading="form.processing" data-testid="line-submit">{{ editing ? t('price_lists.line.submit_edit') : t('price_lists.line.submit_add') }}</Button>
        </template>
    </Modal>
</template>
