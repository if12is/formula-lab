<script setup>
/**
 * The one form for a packaging component (H-01), for adding and for editing. The spec (material, capacities, weight, neck
 * finish) is one sourced fact: any value typed needs its source and confidence, and a value left empty stays "not recorded",
 * never zero.
 *
 *   <PackagingFormModal v-model:open="open" :item="row" :kind-options="..." :material-options="..." ... />
 */
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { path } from '../../lib/routes';
import { Alert, Button, FormField, Input, Modal, Select } from '@kit';
import { useI18n } from '../../composables/useI18n';
import { normalizeDecimalText } from '../../lib/engine';
import DecimalField from '../form/DecimalField.vue';
import SourceSelect from '../materials/SourceSelect.vue';

const props = defineProps({
    item: { type: Object, default: null },
    kindOptions: { type: Array, required: true },
    materialOptions: { type: Array, required: true },
    confidenceOptions: { type: Array, required: true },
    sourceKindOptions: { type: Array, required: true },
    supplierOptions: { type: Array, default: () => [] },
    urls: { type: Object, required: true },
});

const open = defineModel('open', { type: Boolean, default: false });
const { t } = useI18n();
const editing = computed(() => props.item !== null);
const NUMBERS = ['nominal_capacity_ml', 'brimful_capacity_ml', 'unit_weight_g'];

const initial = () => ({
    trade_name: props.item?.trade_name ?? '',
    item_kind: props.item?.item_kind ?? 'container',
    packaging_material: props.item?.packaging_material ?? '',
    nominal_capacity_ml: props.item?.nominal_capacity_ml ?? '',
    brimful_capacity_ml: props.item?.brimful_capacity_ml ?? '',
    unit_weight_g: props.item?.unit_weight_g ?? '',
    neck_finish: props.item?.neck_finish ?? '',
    sanad_supplier_uuid: props.item?.sanad_supplier_uuid ?? '',
    packaging_source_id: props.item?.packaging_source_id ?? null,
    packaging_confidence: props.item?.packaging_confidence ?? undefined,
});

const form = useForm(initial());
const materials = computed(() => [{ value: '', label: t('packaging.form.material_placeholder') }, ...props.materialOptions]);
const suppliers = computed(() => [{ value: '', label: t('packaging.form.supplier_placeholder') }, ...props.supplierOptions]);

watch(open, (isOpen) => {
    if (isOpen) {
        form.defaults(initial()).reset();
        form.clearErrors();
    }
});

function submit() {
    const send = form.transform((data) => ({
        ...data,
        ...Object.fromEntries(NUMBERS.map((key) => [key, normalizeDecimalText(data[key])])),
        packaging_confidence: data.packaging_confidence ?? null,
    }));
    const options = { preserveScroll: true, onSuccess: () => { open.value = false; } };

    if (editing.value) {
        send.put(path('packaging.update', props.item.id), options);
    } else {
        send.post(path('packaging.store'), options);
    }
}
</script>

<template>
    <Modal v-model:open="open" :title="t(editing ? 'packaging.form.title_edit' : 'packaging.form.title_create')" :description="t('packaging.form.description')" size="md">
        <form id="packaging-form" class="grid gap-4 sm:grid-cols-2" novalidate data-testid="packaging-form" @submit.prevent="submit">
            <FormField :label="t('packaging.fields.trade_name')" :error="form.errors.trade_name" required class="sm:col-span-2">
                <Input v-model="form.trade_name" maxlength="255" />
            </FormField>
            <FormField :label="t('packaging.fields.item_kind')" :error="form.errors.item_kind" required>
                <Select v-model="form.item_kind" :options="kindOptions" />
            </FormField>
            <FormField :label="t('packaging.fields.supplier')" :error="form.errors.sanad_supplier_uuid">
                <Select v-model="form.sanad_supplier_uuid" :options="suppliers" />
            </FormField>

            <h3 class="type-h6 text-ink sm:col-span-2">{{ t('packaging.form.spec_title') }}</h3>
            <FormField :label="t('packaging.fields.packaging_material')" :error="form.errors.packaging_material">
                <Select v-model="form.packaging_material" :options="materials" />
            </FormField>
            <FormField :label="t('packaging.fields.neck_finish')" :hint="t('packaging.form.neck_finish_hint')" :error="form.errors.neck_finish">
                <Input v-model="form.neck_finish" ltr maxlength="100" />
            </FormField>
            <DecimalField v-model="form.nominal_capacity_ml" label-key="packaging.fields.nominal_capacity_ml" :suffix="t('packaging.units.ml')" :error="form.errors.nominal_capacity_ml" />
            <DecimalField v-model="form.brimful_capacity_ml" label-key="packaging.fields.brimful_capacity_ml" hint-key="packaging.form.brimful_hint" :suffix="t('packaging.units.ml')" :error="form.errors.brimful_capacity_ml" />
            <DecimalField v-model="form.unit_weight_g" label-key="packaging.fields.unit_weight_g" :suffix="t('packaging.units.g')" :error="form.errors.unit_weight_g" />

            <div class="sm:col-span-2">
                <SourceSelect v-model="form.packaging_source_id" :urls="urls" :kind-options="sourceKindOptions" :error="form.errors.packaging_source_id" label-key="packaging.fields.source" :selected-label="item?.source_title ?? ''" />
            </div>
            <FormField :label="t('packaging.fields.confidence')" :error="form.errors.packaging_confidence" class="sm:col-span-2">
                <Select v-model="form.packaging_confidence" :options="confidenceOptions" :placeholder="t('common.select_placeholder')" />
            </FormField>
            <Alert tone="info" class="sm:col-span-2">{{ t('packaging.form.source_notice') }}</Alert>
        </form>
        <template #footer>
            <Button variant="ghost" @click="open = false">{{ t('common.cancel') }}</Button>
            <Button type="submit" form="packaging-form" :loading="form.processing" data-testid="packaging-submit">{{ t(editing ? 'packaging.form.submit_edit' : 'packaging.form.submit_create') }}</Button>
        </template>
    </Modal>
</template>
