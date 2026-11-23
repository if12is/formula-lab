<script setup>
/** The approval or rejection of a deviation above the threshold: a note saying why is required, and the decision is final. */
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import { Button, FormField, Modal, Textarea } from '@kit';
import { useI18n } from '../../composables/useI18n';

const props = defineProps({
    deviation: { type: Object, default: null },
    decision: { type: String, default: 'approve' },
});

const open = defineModel('open', { type: Boolean, default: false });
const { t } = useI18n();
const form = useForm({ decision: 'approve', note: '' });

watch(() => [props.deviation, props.decision, open.value], () => {
    if (open.value) {
        form.defaults({ decision: props.decision, note: '' }).reset();
        form.clearErrors();
    }
}, { immediate: true });

function submit() {
    form.post(props.deviation.decide_url, { preserveScroll: true, onSuccess: () => { open.value = false; } });
}
</script>

<template>
    <Modal v-model:open="open" :title="t(`production.deviations.${decision}_title`)" :description="t('production.deviations.decision_description')">
        <form class="flex flex-col gap-4" novalidate data-testid="decision-form" @submit.prevent="submit">
            <FormField :label="t('production.deviations.decision_note')" :error="form.errors.note" required>
                <Textarea v-model="form.note" :rows="3" maxlength="1000" data-testid="decision-note" />
            </FormField>
            <div class="flex flex-wrap gap-2">
                <Button type="submit" :variant="decision === 'reject' ? 'danger' : 'primary'" :icon="decision === 'reject' ? 'ph:x' : 'ph:check'" :loading="form.processing" data-testid="decision-submit">{{ t(`production.deviations.${decision}`) }}</Button>
                <Button variant="ghost" @click="open = false">{{ t('common.cancel') }}</Button>
            </div>
        </form>
    </Modal>
</template>
