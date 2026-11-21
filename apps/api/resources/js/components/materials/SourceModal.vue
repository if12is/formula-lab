<script setup>
/**
 * Creates a data source (a certificate, a data sheet, a standard...) without leaving the page that needs it.
 * The server answers with the new option ({ value, label, description }) and the modal hands it up through `created`.
 */
import { reactive, ref, watch } from 'vue';
import { Button, DatePicker, FormField, Input, Modal, Select, toast } from '@kit';
import { useI18n } from '../../composables/useI18n';
import { postJson } from '../../lib/http';

const props = defineProps({
    url: { type: String, required: true },
    kindOptions: { type: Array, required: true },
});

const open = defineModel('open', { type: Boolean, default: false });
const emit = defineEmits(['created']);
const { t } = useI18n();

const blank = () => ({ kind: undefined, title: '', url: '', license: '', retrieved_at: null });
const form = reactive(blank());
const errors = ref({});
const busy = ref(false);

watch(open, (isOpen) => {
    if (isOpen) {
        Object.assign(form, blank());
        errors.value = {};
    }
});

async function submit() {
    busy.value = true;

    try {
        const result = await postJson(props.url, { ...form, kind: form.kind ?? null });

        if (!result.ok) {
            errors.value = result.errors;

            return;
        }

        toast.success(t('materials.source_created'));
        emit('created', result.data);
        open.value = false;
    } catch {
        toast.error(t('common.request_failed'));
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Modal v-model:open="open" :title="t('materials.source.title')" :description="t('materials.source.description')" size="md">
        <form id="source-form" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="submit">
            <FormField :label="t('materials.source.kind')" :error="errors.kind" required>
                <Select v-model="form.kind" :options="kindOptions" :placeholder="t('common.select_placeholder')" />
            </FormField>
            <FormField :label="t('materials.source.retrieved_at')" :hint="t('materials.source.retrieved_at_hint')" :error="errors.retrieved_at" required>
                <DatePicker v-model="form.retrieved_at" />
            </FormField>
            <FormField :label="t('materials.source.title_field')" :hint="t('materials.source.title_hint')" :error="errors.title" required class="sm:col-span-2">
                <Input v-model="form.title" />
            </FormField>
            <FormField :label="t('materials.source.url')" :error="errors.url">
                <Input v-model="form.url" ltr inputmode="url" />
            </FormField>
            <FormField :label="t('materials.source.license')" :error="errors.license">
                <Input v-model="form.license" />
            </FormField>
        </form>
        <template #footer>
            <Button variant="ghost" @click="open = false">{{ t('common.cancel') }}</Button>
            <Button type="submit" form="source-form" :loading="busy">{{ t('materials.source.submit') }}</Button>
        </template>
    </Modal>
</template>
