<script setup>
/**
 * The unit cost of a product (EGP per selling unit) the price lists are checked against, with its source and confidence.
 * It belongs to the product, so every list that holds the product sees the same cost. Nothing is pre-filled: a product with no
 * cost says so, and its lines cannot be priced or published until one is recorded.
 */
import { ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Button, FormField, Modal, Select } from '@kit';
import { useI18n } from '../../composables/useI18n';
import { path } from '../../lib/routes';
import DecimalField from '../form/DecimalField.vue';
import SourceSelect from '../materials/SourceSelect.vue';

const props = defineProps({
    /** The line whose product is being costed ({ formula_id, code, name, unit_cost }). */
    row: { type: Object, default: null },
    confidenceOptions: { type: Array, required: true },
    sourceKindOptions: { type: Array, required: true },
    urls: { type: Object, required: true },
});

const open = defineModel('open', { type: Boolean, default: false });
const { t } = useI18n();

const blank = () => ({
    unit_cost: props.row?.unit_cost?.value ?? '',
    source_id: props.row?.unit_cost?.source_id ?? null,
    confidence: props.row?.unit_cost?.confidence || undefined,
});
const form = useForm(blank());
const sourceLabel = ref('');

watch(open, (isOpen) => {
    if (isOpen) {
        form.defaults(blank()).reset();
        form.clearErrors();
        sourceLabel.value = props.row?.unit_cost?.source_title ?? '';
    }
});

function submit() {
    form
        .transform((data) => ({ ...data, confidence: data.confidence ?? null }))
        .put(path('formulas.unit-cost.update', props.row.formula_id), { preserveScroll: true, onSuccess: () => { open.value = false; } });
}
</script>

<template>
    <Modal v-model:open="open" :title="t('price_lists.cost.title')" :description="t('price_lists.cost.description')" size="md">
        <form id="unit-cost-form" class="flex flex-col gap-4" novalidate data-testid="cost-form" @submit.prevent="submit">
            <p v-if="row" class="text-ink"><bdi dir="ltr" class="font-semibold">{{ row.code }}</bdi> · {{ row.name }}</p>
            <DecimalField v-model="form.unit_cost" label-key="price_lists.cost.value" hint-key="price_lists.cost.value_hint" :suffix="t('price_lists.unit_egp')" :error="form.errors.unit_cost" required />
            <div class="grid gap-4 sm:grid-cols-2">
                <SourceSelect v-model="form.source_id" :urls="urls" :kind-options="sourceKindOptions" :selected-label="sourceLabel" :error="form.errors.source_id" label-key="price_lists.cost.source" required />
                <FormField :label="t('price_lists.cost.confidence')" :error="form.errors.confidence" required>
                    <Select v-model="form.confidence" :options="confidenceOptions" :placeholder="t('common.select_placeholder')" />
                </FormField>
            </div>
        </form>
        <template #footer>
            <Button variant="ghost" @click="open = false">{{ t('common.cancel') }}</Button>
            <Button type="submit" form="unit-cost-form" :loading="form.processing" data-testid="cost-submit">{{ t('price_lists.cost.submit') }}</Button>
        </template>
    </Modal>
</template>
