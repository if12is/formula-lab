<script setup>
/**
 * Records a deviation: what it concerns, the planned and actual numbers when there are any (the engine judges them against the configured
 * threshold), why it happened and who records it. Above the threshold it waits for an approval; with no threshold set every difference does.
 */
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import { Alert, Button, FormField, Input, Modal, Select, Textarea } from '@kit';
import DecimalField from '../form/DecimalField.vue';
import { useI18n } from '../../composables/useI18n';

const props = defineProps({
    preset: { type: Object, default: null },
    choices: { type: Object, required: true },
    threshold: { type: String, default: null },
    url: { type: String, required: true },
    operator: { type: String, default: '' },
});

const open = defineModel('open', { type: Boolean, default: false });
const { t } = useI18n();
const form = useForm({ kind: 'weight', subject: '', planned: '', actual: '', unit: 'g', reason: '', recorded_by_name: '' });

watch(() => [props.preset, open.value], () => {
    if (open.value) {
        form.defaults({ kind: 'other', subject: '', planned: '', actual: '', unit: 'g', reason: '', recorded_by_name: props.operator, ...(props.preset ?? {}) }).reset();
        form.clearErrors();
    }
}, { immediate: true });

function submit() {
    form.post(props.url, { preserveScroll: true, onSuccess: () => { open.value = false; } });
}
</script>

<template>
    <Modal v-model:open="open" :title="t('production.deviations.record_title')" :description="t('production.deviations.record_description')">
        <form class="flex flex-col gap-4" novalidate data-testid="deviation-form" @submit.prevent="submit">
            <Alert v-if="threshold === null" tone="warning" icon="ph:warning">{{ t('production.deviations.threshold_not_set') }}</Alert>
            <Alert v-else tone="info" icon="ph:info">{{ t('production.deviations.threshold_is', { threshold }) }}</Alert>
            <div class="grid gap-4 sm:grid-cols-2">
                <FormField :label="t('production.deviations.kind')" :error="form.errors.kind" required>
                    <Select v-model="form.kind" :options="choices.kinds" data-testid="deviation-kind" />
                </FormField>
                <FormField :label="t('production.deviations.subject')" :hint="t('production.deviations.subject_hint')" :error="form.errors.subject" required>
                    <Input v-model="form.subject" maxlength="255" data-testid="deviation-subject" />
                </FormField>
                <DecimalField v-model="form.planned" label-key="production.deviations.planned" :error="form.errors.planned" />
                <DecimalField v-model="form.actual" label-key="production.deviations.actual" :error="form.errors.actual" />
                <FormField :label="t('production.deviations.unit')" :error="form.errors.unit">
                    <Select v-model="form.unit" :options="choices.units" />
                </FormField>
                <FormField :label="t('production.deviations.recorded_by')" :error="form.errors.recorded_by_name" required>
                    <Input v-model="form.recorded_by_name" maxlength="255" data-testid="deviation-by" />
                </FormField>
            </div>
            <FormField :label="t('production.deviations.reason')" :hint="t('production.deviations.reason_hint')" :error="form.errors.reason" required>
                <Textarea v-model="form.reason" :rows="3" maxlength="1000" data-testid="deviation-reason" />
            </FormField>
            <div class="flex flex-wrap gap-2">
                <Button type="submit" icon="ph:check" :loading="form.processing" data-testid="deviation-submit">{{ t('production.deviations.save') }}</Button>
                <Button variant="ghost" @click="open = false">{{ t('common.cancel') }}</Button>
            </div>
        </form>
    </Modal>
</template>
