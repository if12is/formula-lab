<script setup>
/** Records one reading at a check point of the batch's process. The engine reads it against the limits; with no limit the status is "not evaluated". */
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import { Button, FormField, Input, Modal, Textarea } from '@kit';
import { useI18n } from '../../composables/useI18n';

const props = defineProps({
    point: { type: Object, default: null },
    url: { type: String, required: true },
    operator: { type: String, default: '' },
});

const open = defineModel('open', { type: Boolean, default: false });
const { t } = useI18n();
const form = useForm({ step_no: 1, check_no: 1, value: '', measured_by: '', measured_at: '', note: '' });

const nowLocal = () => new Date().toISOString().slice(0, 16);

watch(() => props.point, (point) => {
    if (point) {
        form.defaults({ step_no: Number(point.step), check_no: Number(point.check.check_no), value: '', measured_by: props.operator, measured_at: nowLocal(), note: '' }).reset();
        form.clearErrors();
    }
}, { immediate: true });

function submit() {
    form.post(props.url, { preserveScroll: true, onSuccess: () => { open.value = false; } });
}
</script>

<template>
    <Modal v-model:open="open" :title="t('production.checks.record_title', { property: point ? t(`production.check_properties.${point.check.property}`) : '' })" :description="t('production.checks.record_description')">
        <form class="flex flex-col gap-4" novalidate data-testid="check-form" @submit.prevent="submit">
            <FormField :label="t('production.checks.value')" :error="form.errors.value" required>
                <Input v-model="form.value" ltr inputmode="decimal" :suffix="point?.check.unit" data-testid="check-value" />
            </FormField>
            <FormField :label="t('production.checks.measured_by')" :error="form.errors.measured_by" required>
                <Input v-model="form.measured_by" maxlength="255" />
            </FormField>
            <FormField :label="t('production.checks.measured_at')" :error="form.errors.measured_at" required>
                <Input v-model="form.measured_at" type="datetime-local" ltr />
            </FormField>
            <FormField :label="t('production.checks.note')" :error="form.errors.note">
                <Textarea v-model="form.note" :rows="2" maxlength="1000" />
            </FormField>
            <div class="flex flex-wrap gap-2">
                <Button type="submit" icon="ph:check" :loading="form.processing" data-testid="check-submit">{{ t('production.checks.save') }}</Button>
                <Button variant="ghost" @click="open = false">{{ t('common.cancel') }}</Button>
            </div>
        </form>
    </Modal>
</template>
