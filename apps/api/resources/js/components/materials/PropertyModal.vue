<script setup>
/**
 * Adds a sourced property value to a material: a single value or a min/max range, with its unit, source
 * (searched or created on the spot), confidence level, the date it is valid from and the lot it was measured on.
 * Values are kept as text: the browser never turns a decimal into a float.
 */
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { path } from '../../lib/routes';
import { Alert, Button, DatePicker, FormField, Input, Modal, SegmentedControl, Select } from '@kit';
import EntitySelect from '../form/EntitySelect.vue';
import { useI18n } from '../../composables/useI18n';
import { useProperties } from '../../composables/useProperties';
import { normalizeDecimalText } from '../../lib/engine';
import SourceSelect from './SourceSelect.vue';

const props = defineProps({
    materialId: { type: String, required: true },
    urls: { type: Object, required: true },
    confidenceOptions: { type: Array, required: true },
    sourceKindOptions: { type: Array, required: true },
    /** The IFRA certificates of this oil: an IFRA limit is read from one of them. */
    certificates: { type: Array, default: () => [] },
    /** A certificate to preselect (the add-limit button of its row), and the property it implies. */
    certificateId: { type: String, default: null },
});

const open = defineModel('open', { type: Boolean, default: false });
const { t } = useI18n();
const { options: propertyOptions, unitsOf } = useProperties();

const blank = () => ({ property: '', value: '', min: '', max: '', unit: '', source_id: null, confidence: undefined, valid_from: null, lot_no: '', category: '', ifra_certificate_id: null });
const form = useForm(blank());
const mode = ref('value');
const isIfra = computed(() => form.property === 'ifra_limit');
const certificateOptions = computed(() => props.certificates.map((certificate) => ({ value: certificate.id, label: certificate.certificate_number })));
const modes = computed(() => [
    { value: 'value', label: t('materials.property.mode_value') },
    { value: 'range', label: t('materials.property.mode_range') },
]);

// The registry fixes which units a property may be stored in; one unit is chosen for the person.
const unitOptions = computed(() => unitsOf(form.property).map((unit) => ({ value: unit, label: unit })));
watch(() => form.property, (property) => {
    const units = unitsOf(property);
    form.unit = units.includes(form.unit) ? form.unit : (units[0] ?? '');
});

watch(open, (isOpen) => {
    if (isOpen) {
        form.defaults(blank()).reset();
        form.clearErrors();
        mode.value = 'value';

        if (props.certificateId) {
            form.property = 'ifra_limit';
            form.ifra_certificate_id = props.certificateId;
        }
    }
});

function submit() {
    form
        .transform((data) => ({
            ...data,
            value: mode.value === 'value' ? normalizeDecimalText(data.value) : null,
            min: mode.value === 'range' ? normalizeDecimalText(data.min) : null,
            max: mode.value === 'range' ? normalizeDecimalText(data.max) : null,
            confidence: data.confidence ?? null,
            category: data.property === 'ifra_limit' ? data.category : null,
            ifra_certificate_id: data.property === 'ifra_limit' ? data.ifra_certificate_id : null,
        }))
        .post(path('materials.properties.store', props.materialId), { preserveScroll: true, onSuccess: () => { open.value = false; } });
}
</script>

<template>
    <Modal v-model:open="open" :title="t('materials.property.title')" :description="t('materials.property.description')" size="lg">
        <form id="property-form" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="submit">
            <EntitySelect
                v-model="form.property"
                label-key="materials.property.name"
                hint-key="materials.property.name_hint"
                :options="propertyOptions"
                :error="form.errors.property"
                required
            />
            <FormField :label="t('materials.property.unit')" :hint="t('materials.property.unit_hint')" :error="form.errors.unit" required>
                <Select v-model="form.unit" :options="unitOptions" :placeholder="t('common.select_placeholder')" :disabled="unitOptions.length === 0" />
            </FormField>

            <FormField v-if="isIfra" :label="t('fragrance.limit.certificate')" :hint="t('fragrance.limit.certificate_hint')" :error="form.errors.ifra_certificate_id" required class="sm:col-span-2">
                <Select v-model="form.ifra_certificate_id" :options="certificateOptions" :placeholder="t('common.select_placeholder')" :disabled="certificateOptions.length === 0" />
            </FormField>
            <Alert v-if="isIfra && certificateOptions.length === 0" tone="warning" icon="ph:warning" class="sm:col-span-2">{{ t('fragrance.limit.certificate_none') }}</Alert>
            <FormField v-if="isIfra" :label="t('materials.property.category')" :hint="t('materials.property.category_hint')" :error="form.errors.category" required class="sm:col-span-2">
                <Input v-model="form.category" ltr maxlength="50" />
            </FormField>

            <FormField :label="t('materials.property.kind')" class="sm:col-span-2">
                <SegmentedControl v-model="mode" :options="modes" />
            </FormField>
            <FormField v-if="mode === 'value'" :label="t('materials.property.value')" :error="form.errors.value" required class="sm:col-span-2">
                <Input v-model="form.value" ltr inputmode="decimal" />
            </FormField>
            <template v-else>
                <FormField :label="t('materials.property.min')" :error="form.errors.min">
                    <Input v-model="form.min" ltr inputmode="decimal" />
                </FormField>
                <FormField :label="t('materials.property.max')" :error="form.errors.max">
                    <Input v-model="form.max" ltr inputmode="decimal" />
                </FormField>
            </template>

            <Alert v-if="isIfra" tone="info" icon="ph:info" class="sm:col-span-2">{{ t('fragrance.limit.from_certificate') }}</Alert>
            <template v-else>
                <div class="sm:col-span-2">
                    <SourceSelect v-model="form.source_id" :urls="urls" :kind-options="sourceKindOptions" :error="form.errors.source_id" required />
                </div>
                <FormField :label="t('materials.fields.confidence')" :hint="t('materials.fields.confidence_hint')" :error="form.errors.confidence" required>
                    <Select v-model="form.confidence" :options="confidenceOptions" :placeholder="t('common.select_placeholder')" />
                </FormField>
                <FormField :label="t('materials.property.valid_from')" :hint="t('materials.property.valid_from_hint')" :error="form.errors.valid_from" required>
                    <DatePicker v-model="form.valid_from" />
                </FormField>
            </template>
            <FormField v-if="!isIfra" :label="t('materials.property.lot_no')" :hint="t('materials.property.lot_no_hint')" :error="form.errors.lot_no" class="sm:col-span-2">
                <Input v-model="form.lot_no" maxlength="100" ltr />
            </FormField>
        </form>
        <template #footer>
            <Button variant="ghost" @click="open = false">{{ t('common.cancel') }}</Button>
            <Button type="submit" form="property-form" :loading="form.processing">{{ t('materials.property.submit') }}</Button>
        </template>
    </Modal>
</template>
