<script setup>
/**
 * Starts a draft formula from a template. The person names the formula, picks a material for each line they want and types the
 * shares the template does not carry; the server hands everything to the engine, which balances the q.s. line, checks the
 * sum and runs the family's compliance rules before anything is created. Every problem the engine reports comes back on its own
 * line (a missing material or share) or on the form (a failing rule, a sum that is not 100).
 *
 *   <UseTemplateModal v-model:open="open" :template="template" :tier-options="tierOptions" :urls="urls" />
 */
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Alert, Button, FormField, Input, Modal, Select } from '@kit';
import { useI18n } from '../../composables/useI18n';
import DecimalField from '../form/DecimalField.vue';
import EntitySelect from '../form/EntitySelect.vue';

const props = defineProps({
    template: { type: Object, required: true },
    tierOptions: { type: Array, required: true },
    urls: { type: Object, required: true },
});

const open = defineModel('open', { type: Boolean, default: false });
const { t } = useI18n();

const blankFill = () => Object.fromEntries(props.template.lines.map((line) => [line.key, { material_id: null, percent_ww: '' }]));
const form = useForm({ code: '', name: '', tier: 'none', fill: blankFill() });

watch(open, (isOpen) => {
    if (isOpen) {
        form.reset();
        form.clearErrors();
        form.fill = blankFill();
    }
});

const tierChoices = computed(() => [{ value: 'none', label: t('templates.use.tier_none') }, ...props.tierOptions]);
const roleOf = (line) => t(`families.roles.${line.role}`);

function submit() {
    form.transform((data) => ({
        code: data.code,
        name: data.name,
        tier: data.tier === 'none' ? null : data.tier,
        // A line with no material and no share is left out: the engine drops an optional line and reports a required one.
        fill: Object.fromEntries(Object.entries(data.fill)
            .map(([key, line]) => [key, { ...(line.material_id ? { material_id: line.material_id } : {}), ...(line.percent_ww !== '' ? { percent_ww: line.percent_ww } : {}) }])
            .filter(([, line]) => Object.keys(line).length > 0)),
    })).post(props.urls.use, { preserveScroll: true });
}
</script>

<template>
    <Modal v-model:open="open" :title="t('templates.use.title')" :description="t('templates.use.description')" size="lg">
        <form id="use-template-form" class="flex flex-col gap-4" novalidate data-testid="use-template-form" @submit.prevent="submit">
            <Alert v-if="form.errors.template" tone="danger" icon="ph:warning" :title="t('templates.use.form_error')" data-testid="use-template-error">{{ form.errors.template }}</Alert>

            <div class="grid gap-4 sm:grid-cols-2">
                <FormField :label="t('templates.use.code')" :error="form.errors.code" required>
                    <Input v-model="form.code" ltr maxlength="50" />
                </FormField>
                <FormField :label="t('templates.use.name')" :error="form.errors.name" required>
                    <Input v-model="form.name" maxlength="255" />
                </FormField>
                <FormField :label="t('templates.use.tier')" :error="form.errors.tier">
                    <Select v-model="form.tier" :options="tierChoices" />
                </FormField>
            </div>

            <ul class="flex flex-col gap-3">
                <li v-for="line in template.lines" :key="line.key" class="rounded-md border border-line p-3" :data-testid="`use-line-${line.key}`">
                    <div class="grid gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                        <EntitySelect
                            v-model="form.fill[line.key].material_id"
                            label-key="templates.use.material"
                            :label-params="{ role: roleOf(line) }"
                            :hint-key="line.optional ? 'templates.use.optional_hint' : null"
                            :search-url="urls.materials"
                            :error="form.errors[`fill.${line.key}.material_id`]"
                            :required="!line.optional"
                        />
                        <DecimalField
                            v-if="!line.qs"
                            v-model="form.fill[line.key].percent_ww"
                            label-key="templates.use.share"
                            :label-params="{ role: roleOf(line) }"
                            suffix="%"
                            :hint-key="line.percent === null ? null : undefined"
                            :error="form.errors[`fill.${line.key}.percent_ww`]"
                            :required="line.percent === null && !line.optional"
                        />
                        <p v-else class="self-end pb-2 text-label text-ink-3">{{ t('templates.use.qs_hint') }}</p>
                    </div>
                    <p v-if="!line.qs && line.percent !== null" class="mt-2 text-label text-ink-3">{{ t('templates.use.share_from_template', { percent: line.percent }) }}</p>
                </li>
            </ul>
        </form>
        <template #footer>
            <Button variant="ghost" @click="open = false">{{ t('templates.use.cancel') }}</Button>
            <Button type="submit" form="use-template-form" :loading="form.processing" icon="ph:flask" data-testid="use-template-submit">{{ t('templates.use.submit') }}</Button>
        </template>
    </Modal>
</template>
