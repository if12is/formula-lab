<script setup>
/**
 * Adds the IFRA certificate of a fragrance oil: number, amendment as printed, issuer, issue date, the document it came from
 * (a source, searched or created on the spot) and the confidence in it. The category limits are added afterwards as
 * "IFRA limit" values, each naming this certificate, and take their source and date from it.
 */
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { path } from '../../lib/routes';
import { Button, DatePicker, FormField, Input, Modal, Select } from '@kit';
import { useI18n } from '../../composables/useI18n';
import SourceSelect from './SourceSelect.vue';

const props = defineProps({
    materialId: { type: String, required: true },
    urls: { type: Object, required: true },
    confidenceOptions: { type: Array, required: true },
    sourceKindOptions: { type: Array, required: true },
});

const open = defineModel('open', { type: Boolean, default: false });
const { t } = useI18n();

const blank = () => ({ certificate_number: '', ifra_amendment: '', issuer: '', issued_on: null, source_id: null, confidence: undefined });
const form = useForm(blank());

watch(open, (isOpen) => {
    if (isOpen) {
        form.defaults(blank()).reset();
        form.clearErrors();
    }
});

function submit() {
    form
        .transform((data) => ({ ...data, ifra_amendment: data.ifra_amendment || null, issuer: data.issuer || null, confidence: data.confidence ?? null }))
        .post(path('materials.ifra-certificates.store', props.materialId), { preserveScroll: true, onSuccess: () => { open.value = false; } });
}
</script>

<template>
    <Modal v-model:open="open" :title="t('fragrance.form.title')" :description="t('fragrance.form.description')" size="md">
        <form id="ifra-certificate-form" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="submit">
            <FormField :label="t('fragrance.form.certificate_number')" :error="form.errors.certificate_number" required>
                <Input v-model="form.certificate_number" ltr maxlength="100" />
            </FormField>
            <FormField :label="t('fragrance.form.ifra_amendment')" :hint="t('fragrance.form.ifra_amendment_hint')" :error="form.errors.ifra_amendment">
                <Input v-model="form.ifra_amendment" ltr maxlength="50" />
            </FormField>
            <FormField :label="t('fragrance.form.issuer')" :hint="t('fragrance.form.issuer_hint')" :error="form.errors.issuer">
                <Input v-model="form.issuer" maxlength="150" />
            </FormField>
            <FormField :label="t('fragrance.form.issued_on')" :hint="t('fragrance.form.issued_on_hint')" :error="form.errors.issued_on" required>
                <DatePicker v-model="form.issued_on" />
            </FormField>
            <div class="sm:col-span-2">
                <SourceSelect v-model="form.source_id" :urls="urls" :kind-options="sourceKindOptions" :error="form.errors.source_id" required />
            </div>
            <FormField :label="t('fragrance.form.confidence')" :hint="t('materials.fields.confidence_hint')" :error="form.errors.confidence" required class="sm:col-span-2">
                <Select v-model="form.confidence" :options="confidenceOptions" :placeholder="t('common.select_placeholder')" />
            </FormField>
        </form>
        <template #footer>
            <Button variant="ghost" @click="open = false">{{ t('common.cancel') }}</Button>
            <Button type="submit" form="ifra-certificate-form" :loading="form.processing">{{ t('fragrance.form.submit') }}</Button>
        </template>
    </Modal>
</template>
