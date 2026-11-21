<script setup>
/**
 * Adds a dated, sourced price quote (EGP per kg) to a material. A quote with a better confidence level than an
 * estimate (L7) is what lets the engine cost a formula with it.
 */
import { ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { path } from '../../lib/routes';
import { Alert, Button, DatePicker, FormField, Input, Modal, Select } from '@kit';
import { useI18n } from '../../composables/useI18n';
import { normalizeDecimalText } from '../../lib/engine';
import SourceSelect from './SourceSelect.vue';

const props = defineProps({
    materialId: { type: String, required: true },
    urls: { type: Object, required: true },
    confidenceOptions: { type: Array, required: true },
    sourceKindOptions: { type: Array, required: true },
});

const open = defineModel('open', { type: Boolean, default: false });
const { t } = useI18n();

const blank = () => ({ amount: '', quoted_on: null, source_id: null, confidence: undefined });
const form = useForm(blank());
const estimateNotice = ref(false);

watch(open, (isOpen) => {
    if (isOpen) {
        form.defaults(blank()).reset();
        form.clearErrors();
    }
});

watch(() => form.confidence, (confidence) => { estimateNotice.value = confidence === 'L7_estimate'; });

function submit() {
    form
        .transform((data) => ({ ...data, amount: normalizeDecimalText(data.amount), confidence: data.confidence ?? null }))
        .post(path('materials.prices.store', props.materialId), { preserveScroll: true, onSuccess: () => { open.value = false; } });
}
</script>

<template>
    <Modal v-model:open="open" :title="t('materials.price.title')" :description="t('materials.price.description')" size="md">
        <form id="price-form" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="submit">
            <FormField :label="t('materials.price.amount')" :error="form.errors.amount" required>
                <Input v-model="form.amount" ltr inputmode="decimal" :suffix="t('formulas.units.egp_per_kg')" />
            </FormField>
            <FormField :label="t('materials.price.quoted_on')" :hint="t('materials.price.quoted_on_hint')" :error="form.errors.quoted_on" required>
                <DatePicker v-model="form.quoted_on" />
            </FormField>
            <div class="sm:col-span-2">
                <SourceSelect v-model="form.source_id" :urls="urls" :kind-options="sourceKindOptions" :error="form.errors.source_id" required />
            </div>
            <FormField :label="t('materials.fields.confidence')" :hint="t('materials.fields.confidence_hint')" :error="form.errors.confidence" required class="sm:col-span-2">
                <Select v-model="form.confidence" :options="confidenceOptions" :placeholder="t('common.select_placeholder')" />
            </FormField>
            <Alert v-if="estimateNotice" tone="warning" class="sm:col-span-2">{{ t('materials.price.estimate_notice') }}</Alert>
        </form>
        <template #footer>
            <Button variant="ghost" @click="open = false">{{ t('common.cancel') }}</Button>
            <Button type="submit" form="price-form" :loading="form.processing">{{ t('materials.price.submit') }}</Button>
        </template>
    </Modal>
</template>
