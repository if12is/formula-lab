<script setup>
/**
 * Adds or edits one component of a material's composition: its CAS number and/or INCI name, its exact % w/w of the
 * material, and where that comes from (source, confidence level, the date it is valid from). A component with no source is
 * never stored: the compliance engine only uses what it can trace. The percentage stays text, never a float.
 */
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { path } from '../../lib/routes';
import { Button, DatePicker, FormField, Input, Modal, Select } from '@kit';
import { useI18n } from '../../composables/useI18n';
import DecimalField from '../form/DecimalField.vue';
import SourceSelect from './SourceSelect.vue';

const props = defineProps({
    materialId: { type: String, required: true },
    component: { type: Object, default: null },
    urls: { type: Object, required: true },
    confidenceOptions: { type: Array, required: true },
    sourceKindOptions: { type: Array, required: true },
});

const open = defineModel('open', { type: Boolean, default: false });
const { t } = useI18n();
const editing = computed(() => props.component !== null);

const blank = () => ({
    cas: props.component?.cas ?? '',
    inci: props.component?.inci ?? '',
    pct_ww: props.component?.pct_ww ?? '',
    source_id: props.component?.source_id ?? null,
    confidence: props.component?.confidence ?? undefined,
    valid_from: props.component?.valid_from ?? null,
});
const form = useForm(blank());

watch(open, (isOpen) => {
    if (isOpen) {
        form.defaults(blank()).reset();
        form.clearErrors();
    }
});

function submit() {
    const options = { preserveScroll: true, onSuccess: () => { open.value = false; } };
    form.transform((data) => ({ ...data, confidence: data.confidence ?? null }));

    if (editing.value) {
        form.put(path('materials.composition.update', [props.materialId, props.component.id]), options);
    } else {
        form.post(path('materials.composition.store', props.materialId), options);
    }
}
</script>

<template>
    <Modal v-model:open="open" :title="editing ? t('materials.composition.title_edit') : t('materials.composition.title_new')" :description="t('materials.composition.description')" size="lg">
        <form id="composition-form" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="submit">
            <FormField :label="t('materials.composition.cas')" :hint="t('materials.composition.cas_hint')" :error="form.errors.cas">
                <Input v-model="form.cas" ltr maxlength="50" />
            </FormField>
            <FormField :label="t('materials.composition.inci')" :hint="t('materials.composition.inci_hint')" :error="form.errors.inci">
                <Input v-model="form.inci" ltr maxlength="255" />
            </FormField>
            <DecimalField v-model="form.pct_ww" label-key="materials.composition.pct" hint-key="materials.composition.pct_hint" suffix="%" :error="form.errors.pct_ww" required class="sm:col-span-2" />
            <div class="sm:col-span-2">
                <SourceSelect v-model="form.source_id" :urls="urls" :kind-options="sourceKindOptions" :selected-label="component?.source_title ?? ''" :error="form.errors.source_id" required />
            </div>
            <FormField :label="t('materials.fields.confidence')" :hint="t('materials.fields.confidence_hint')" :error="form.errors.confidence" required>
                <Select v-model="form.confidence" :options="confidenceOptions" :placeholder="t('common.select_placeholder')" />
            </FormField>
            <FormField :label="t('materials.property.valid_from')" :hint="t('materials.property.valid_from_hint')" :error="form.errors.valid_from" required>
                <DatePicker v-model="form.valid_from" />
            </FormField>
        </form>
        <template #footer>
            <Button variant="ghost" @click="open = false">{{ t('common.cancel') }}</Button>
            <Button type="submit" form="composition-form" :loading="form.processing" icon="ph:floppy-disk">{{ editing ? t('materials.composition.submit_edit') : t('materials.composition.submit_new') }}</Button>
        </template>
    </Modal>
</template>
