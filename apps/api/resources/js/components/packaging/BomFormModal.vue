<script setup>
/**
 * The one form for the packaging BOM of a SKU (S-12), for adding and for editing: name, SKU code, its finished good in Sanad, the carton and
 * pallet packing and a note. A packing figure left empty stays "not recorded": a carton or pallet line then shows as missing in the cost.
 *
 *   <BomFormModal v-model:open="open" :bom="bom" :sanad-product-options="..." />
 */
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Button, FormField, Input, Modal, Textarea } from '@kit';
import { useI18n } from '../../composables/useI18n';
import { path } from '../../lib/routes';
import DecimalField from '../form/DecimalField.vue';
import EntitySelect from '../form/EntitySelect.vue';

const props = defineProps({
    bom: { type: Object, default: null },
    sanadProductOptions: { type: Array, default: () => [] },
});

const open = defineModel('open', { type: Boolean, default: false });
const { t } = useI18n();
const editing = computed(() => props.bom !== null);

const initial = () => ({
    name: props.bom?.name ?? '',
    sku_code: props.bom?.sku_code ?? '',
    sanad_product_uuid: props.bom?.sanad_product_uuid ?? '',
    units_per_carton: props.bom?.units_per_carton === null || props.bom?.units_per_carton === undefined ? '' : String(props.bom.units_per_carton),
    cartons_per_pallet: props.bom?.cartons_per_pallet === null || props.bom?.cartons_per_pallet === undefined ? '' : String(props.bom.cartons_per_pallet),
    note: props.bom?.note ?? '',
});

const form = useForm(initial());
const sanadOptions = computed(() => [{ value: '', label: t('packaging.bom.form.sanad_product_placeholder') }, ...props.sanadProductOptions]);

watch(open, (isOpen) => {
    if (isOpen) {
        form.defaults(initial()).reset();
        form.clearErrors();
    }
});

function submit() {
    const options = { preserveScroll: true, onSuccess: () => { open.value = false; } };

    if (editing.value) {
        form.put(path('packaging.boms.update', props.bom.id), options);
    } else {
        form.post(path('packaging.boms.store'), options);
    }
}
</script>

<template>
    <Modal v-model:open="open" :title="t(editing ? 'packaging.bom.form.title_edit' : 'packaging.bom.form.title_create')" :description="t('packaging.bom.form.description')" size="md">
        <form id="bom-form" class="grid gap-4 sm:grid-cols-2" novalidate data-testid="bom-form" @submit.prevent="submit">
            <FormField :label="t('packaging.bom.form.name')" :error="form.errors.name" required class="sm:col-span-2">
                <Input v-model="form.name" maxlength="255" data-testid="bom-name" />
            </FormField>
            <FormField :label="t('packaging.bom.form.sku_code')" :error="form.errors.sku_code">
                <Input v-model="form.sku_code" ltr maxlength="100" data-testid="bom-sku-code" />
            </FormField>
            <EntitySelect v-model="form.sanad_product_uuid" label-key="packaging.bom.form.sanad_product" :options="sanadOptions" :error="form.errors.sanad_product_uuid" />
            <DecimalField v-model="form.units_per_carton" label-key="packaging.bom.form.units_per_carton" hint-key="packaging.bom.form.packing_hint" :error="form.errors.units_per_carton" />
            <DecimalField v-model="form.cartons_per_pallet" label-key="packaging.bom.form.cartons_per_pallet" :error="form.errors.cartons_per_pallet" />
            <FormField :label="t('packaging.bom.form.note')" :error="form.errors.note" class="sm:col-span-2">
                <Textarea v-model="form.note" :rows="3" maxlength="1000" />
            </FormField>
        </form>
        <template #footer>
            <Button variant="ghost" @click="open = false">{{ t('common.cancel') }}</Button>
            <Button type="submit" form="bom-form" :loading="form.processing" data-testid="bom-submit">{{ t(editing ? 'packaging.bom.form.submit_edit' : 'packaging.bom.form.submit_create') }}</Button>
        </template>
    </Modal>
</template>
