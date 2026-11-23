<script setup>
/**
 * The one form for a line of a packaging BOM (S-12), for adding and for editing: the component from the packaging library, its level
 * (unit, carton or pallet), and the quantity, reject % and piece price. A figure left empty shows in the cost as missing (never zero);
 * any figure typed needs the source and confidence it came from.
 *
 *   <BomLineFormModal v-model:open="open" :bom-id="bom.id" :line="line" :component-options="..." ... />
 */
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Alert, Button, FormField, Modal, Select } from '@kit';
import { useI18n } from '../../composables/useI18n';
import { normalizeDecimalText } from '../../lib/engine';
import { path } from '../../lib/routes';
import DecimalField from '../form/DecimalField.vue';
import EntitySelect from '../form/EntitySelect.vue';
import SourceSelect from '../materials/SourceSelect.vue';

const props = defineProps({
    bomId: { type: String, required: true },
    line: { type: Object, default: null },
    componentOptions: { type: Array, required: true },
    levelOptions: { type: Array, required: true },
    confidenceOptions: { type: Array, required: true },
    sourceKindOptions: { type: Array, required: true },
    urls: { type: Object, required: true },
});

const open = defineModel('open', { type: Boolean, default: false });
const { t } = useI18n();
const editing = computed(() => props.line !== null);
const FIGURES = ['qty_per_unit', 'reject_pct', 'price_egp'];

const initial = () => ({
    trade_material_id: props.line?.trade_material_id ?? null,
    level: props.line?.level ?? 'unit',
    qty_per_unit: props.line?.qty_per_unit ?? '',
    reject_pct: props.line?.reject_pct ?? '',
    price_egp: props.line?.price_egp ?? '',
    source_id: props.line?.source_id ?? null,
    confidence: props.line?.confidence ?? undefined,
});

const form = useForm(initial());

watch(open, (isOpen) => {
    if (isOpen) {
        form.defaults(initial()).reset();
        form.clearErrors();
    }
});

function submit() {
    const send = form.transform((data) => ({
        ...data,
        ...Object.fromEntries(FIGURES.map((key) => [key, normalizeDecimalText(data[key])])),
        confidence: data.confidence ?? null,
    }));
    const options = { preserveScroll: true, onSuccess: () => { open.value = false; } };

    if (editing.value) {
        send.put(path('packaging.boms.lines.update', { bom: props.bomId, line: props.line.id }), options);
    } else {
        send.post(path('packaging.boms.lines.store', props.bomId), options);
    }
}
</script>

<template>
    <Modal v-model:open="open" :title="t(editing ? 'packaging.bom.line_form.title_edit' : 'packaging.bom.line_form.title_add')" :description="t('packaging.bom.line_form.description')" size="md">
        <form id="bom-line-form" class="grid gap-4 sm:grid-cols-2" novalidate data-testid="bom-line-form" @submit.prevent="submit">
            <div class="sm:col-span-2" data-testid="bom-line-component">
                <EntitySelect v-model="form.trade_material_id" label-key="packaging.bom.line_form.component" :options="componentOptions" :error="form.errors.trade_material_id" required />
            </div>
            <FormField :label="t('packaging.bom.line_form.level')" :hint="t('packaging.bom.line_form.level_hint')" :error="form.errors.level" required class="sm:col-span-2">
                <Select v-model="form.level" :options="levelOptions" data-testid="bom-line-level" />
            </FormField>
            <DecimalField v-model="form.qty_per_unit" label-key="packaging.bom.line_form.qty_per_unit" hint-key="packaging.bom.line_form.qty_hint" :error="form.errors.qty_per_unit" />
            <DecimalField v-model="form.reject_pct" label-key="packaging.bom.line_form.reject_pct" hint-key="packaging.bom.line_form.reject_hint" suffix="%" :error="form.errors.reject_pct" />
            <DecimalField v-model="form.price_egp" label-key="packaging.bom.line_form.price_egp" hint-key="packaging.bom.line_form.price_hint" :error="form.errors.price_egp" class="sm:col-span-2" />

            <div class="sm:col-span-2">
                <SourceSelect v-model="form.source_id" :urls="urls" :kind-options="sourceKindOptions" :error="form.errors.source_id" label-key="packaging.bom.line_form.source" :selected-label="line?.source_title ?? ''" />
            </div>
            <FormField :label="t('packaging.bom.line_form.confidence')" :error="form.errors.confidence" class="sm:col-span-2">
                <Select v-model="form.confidence" :options="confidenceOptions" :placeholder="t('common.select_placeholder')" />
            </FormField>
            <Alert tone="info" class="sm:col-span-2">{{ t('packaging.bom.line_form.source_notice') }}</Alert>
        </form>
        <template #footer>
            <Button variant="ghost" @click="open = false">{{ t('common.cancel') }}</Button>
            <Button type="submit" form="bom-line-form" :loading="form.processing" data-testid="bom-line-submit">{{ t(editing ? 'packaging.bom.line_form.submit_edit' : 'packaging.bom.line_form.submit_add') }}</Button>
        </template>
    </Modal>
</template>
