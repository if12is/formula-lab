<script setup>
/**
 * Creates a substance (INCI, CAS, names, molar mass) without leaving the page that needs it. The server answers
 * with the new option ({ value, label, description }) and the modal hands it up through `created`.
 */
import { reactive, ref, watch } from 'vue';
import { Button, FormField, Input, Modal, toast } from '@kit';
import { useI18n } from '../../composables/useI18n';
import { normalizeDecimalText } from '../../lib/engine';
import { postJson } from '../../lib/http';

const props = defineProps({
    url: { type: String, required: true },
});

const open = defineModel('open', { type: Boolean, default: false });
const emit = defineEmits(['created']);
const { t } = useI18n();

const blank = () => ({ inci: '', cas: '', name_en: '', name_ar: '', mw: '', formula: '' });
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
        const result = await postJson(props.url, { ...form, mw: normalizeDecimalText(form.mw) });

        if (!result.ok) {
            errors.value = result.errors;

            return;
        }

        toast.success(t('materials.substance_created'));
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
    <Modal v-model:open="open" :title="t('materials.substance.title')" :description="t('materials.substance.description')" size="md">
        <form id="substance-form" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="submit">
            <FormField :label="t('materials.substance.inci')" :hint="t('materials.substance.inci_hint')" :error="errors.inci" class="sm:col-span-2">
                <Input v-model="form.inci" ltr />
            </FormField>
            <FormField :label="t('materials.substance.cas')" :hint="t('materials.substance.cas_hint')" :error="errors.cas">
                <Input v-model="form.cas" ltr inputmode="numeric" />
            </FormField>
            <FormField :label="t('materials.substance.mw')" :hint="t('materials.substance.mw_hint')" :error="errors.mw">
                <Input v-model="form.mw" ltr inputmode="decimal" />
            </FormField>
            <FormField :label="t('materials.substance.name_en')" :error="errors.name_en">
                <Input v-model="form.name_en" ltr />
            </FormField>
            <FormField :label="t('materials.substance.name_ar')" :error="errors.name_ar">
                <Input v-model="form.name_ar" />
            </FormField>
            <FormField :label="t('materials.substance.formula')" :error="errors.formula" class="sm:col-span-2">
                <Input v-model="form.formula" ltr />
            </FormField>
        </form>
        <template #footer>
            <Button variant="ghost" @click="open = false">{{ t('common.cancel') }}</Button>
            <Button type="submit" form="substance-form" :loading="busy">{{ t('materials.substance.submit') }}</Button>
        </template>
    </Modal>
</template>
