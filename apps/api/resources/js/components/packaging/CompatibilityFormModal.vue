<script setup>
/**
 * Declares one compatible neck finish pair (H-02): the finish of a container and the finish of a closure or pump that fits it. A
 * pair is a statement from a drawing or datasheet, so it needs its source and confidence; nothing is assumed from two equal codes.
 *
 *   <CompatibilityFormModal v-model:open="open" :confidence-options="..." :source-kind-options="..." :urls="..." />
 */
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Alert, Button, FormField, Input, Modal, Select, Textarea } from '@kit';
import { useI18n } from '../../composables/useI18n';
import { path } from '../../lib/routes';
import SourceSelect from '../materials/SourceSelect.vue';

defineProps({
    confidenceOptions: { type: Array, required: true },
    sourceKindOptions: { type: Array, required: true },
    urls: { type: Object, required: true },
});

const open = defineModel('open', { type: Boolean, default: false });
const { t } = useI18n();
const blank = () => ({ container_neck_finish: '', closure_neck_finish: '', source_id: null, confidence: undefined, note: '' });
const form = useForm(blank());

watch(open, (isOpen) => {
    if (isOpen) {
        form.defaults(blank()).reset();
        form.clearErrors();
    }
});

function submit() {
    form
        .transform((data) => ({ ...data, confidence: data.confidence ?? null }))
        .post(path('packaging.compatibility.store'), { preserveScroll: true, onSuccess: () => { open.value = false; } });
}

</script>

<template>
    <Modal v-model:open="open" :title="t('packaging.compat.form.title')" :description="t('packaging.compat.form.description')" size="md">
        <form id="compat-form" class="grid gap-4 sm:grid-cols-2" novalidate data-testid="compat-form" @submit.prevent="submit">
            <FormField :label="t('packaging.compat.form.container_neck_finish')" :hint="t('packaging.compat.form.finish_hint')" :error="form.errors.container_neck_finish" required>
                <Input v-model="form.container_neck_finish" ltr maxlength="100" data-testid="compat-container-neck" />
            </FormField>
            <FormField :label="t('packaging.compat.form.closure_neck_finish')" :hint="t('packaging.compat.form.finish_hint')" :error="form.errors.closure_neck_finish" required>
                <Input v-model="form.closure_neck_finish" ltr maxlength="100" data-testid="compat-closure-neck" />
            </FormField>
            <div class="sm:col-span-2">
                <SourceSelect v-model="form.source_id" :urls="urls" :kind-options="sourceKindOptions" :error="form.errors.source_id" required label-key="packaging.compat.form.source" />
            </div>
            <FormField :label="t('packaging.compat.form.confidence')" :error="form.errors.confidence" required class="sm:col-span-2">
                <Select v-model="form.confidence" :options="confidenceOptions" :placeholder="t('common.select_placeholder')" data-testid="compat-confidence" />
            </FormField>
            <FormField :label="t('packaging.compat.form.note')" :error="form.errors.note" class="sm:col-span-2">
                <Textarea v-model="form.note" :rows="2" maxlength="1000" />
            </FormField>
            <Alert tone="info" class="sm:col-span-2">{{ t('packaging.compat.form.estimate_notice') }}</Alert>
        </form>
        <template #footer>
            <Button variant="ghost" @click="open = false">{{ t('common.cancel') }}</Button>
            <Button type="submit" form="compat-form" :loading="form.processing" data-testid="compat-submit">{{ t('packaging.compat.form.submit') }}</Button>
        </template>
    </Modal>
</template>
