<script setup>
/**
 * Creates a draft rule set, or edits the name, notes, version and source of one: the rules themselves are added on the
 * rule set's page. A published rule set is never edited (a new version of it is made instead).
 */
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { path } from '../../lib/routes';
import { Button, FormField, Input, Modal, Textarea } from '@kit';
import { useI18n } from '../../composables/useI18n';
import SourceSelect from '../materials/SourceSelect.vue';

const props = defineProps({
    ruleSet: { type: Object, default: null },
    urls: { type: Object, required: true },
    sourceKindOptions: { type: Array, required: true },
});

const open = defineModel('open', { type: Boolean, default: false });
const { t } = useI18n();
const editing = computed(() => props.ruleSet !== null);

const blank = () => ({
    code: props.ruleSet?.code ?? '',
    version: props.ruleSet?.version ?? '',
    name: props.ruleSet?.name ?? '',
    scope_notes: props.ruleSet?.scope_notes ?? '',
    source_id: props.ruleSet?.source?.id ?? null,
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

    if (editing.value) {
        form.put(path('compliance.update', props.ruleSet.id), options);
    } else {
        form.post(path('compliance.store'), options);
    }
}
</script>

<template>
    <Modal v-model:open="open" :title="editing ? t('compliance.form.title_edit') : t('compliance.form.title_new')" :description="t('compliance.form.description')" size="md">
        <form id="rule-set-form" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="submit">
            <FormField :label="t('compliance.form.code')" :hint="t('compliance.form.code_hint')" :error="form.errors.code" required>
                <Input v-model="form.code" ltr maxlength="100" :disabled="editing && ruleSet.status !== 'draft'" />
            </FormField>
            <FormField :label="t('compliance.form.version')" :hint="t('compliance.form.version_hint')" :error="form.errors.version" required>
                <Input v-model="form.version" ltr maxlength="50" />
            </FormField>
            <FormField :label="t('compliance.form.name')" :error="form.errors.name" required class="sm:col-span-2">
                <Input v-model="form.name" maxlength="255" />
            </FormField>
            <FormField :label="t('compliance.form.scope_notes')" :hint="t('compliance.form.scope_notes_hint')" :error="form.errors.scope_notes" class="sm:col-span-2">
                <Textarea v-model="form.scope_notes" :rows="3" maxlength="2000" />
            </FormField>
            <div class="sm:col-span-2">
                <SourceSelect
                    v-model="form.source_id"
                    :urls="urls"
                    :kind-options="sourceKindOptions"
                    label-key="compliance.form.source"
                    :selected-label="ruleSet?.source?.title ?? ''"
                    :error="form.errors.source_id"
                />
            </div>
        </form>
        <template #footer>
            <Button variant="ghost" @click="open = false">{{ t('common.cancel') }}</Button>
            <Button type="submit" form="rule-set-form" :loading="form.processing" icon="ph:floppy-disk">{{ editing ? t('compliance.form.submit_edit') : t('compliance.form.submit_new') }}</Button>
        </template>
    </Modal>
</template>
