<script setup>
/**
 * Opens a lab trial: the formula version that was made (searched on the server; the formula editor links here with the
 * version already chosen), the batch size and unit, the date and who made it. Measurements are added on the trial page.
 */
import { Head, useForm } from '@inertiajs/vue3';
import { Button, Card, DatePicker, FormField, Input, PageHeader, Select, Textarea } from '@kit';
import DecimalField from '../../../components/form/DecimalField.vue';
import EntitySelect from '../../../components/form/EntitySelect.vue';
import { useI18n } from '../../../composables/useI18n';

const props = defineProps({
    /** The preselected version option ({ value, label, description }) or null. */
    version: { type: Object, default: null },
    batchUnits: { type: Array, required: true },
    today: { type: String, required: true },
    urls: { type: Object, required: true },
});

const { t } = useI18n();
const form = useForm({
    formula_version_id: props.version?.value ?? null,
    batch_size: '',
    batch_unit: props.batchUnits[0].value,
    trial_date: props.today,
    operator: '',
    notes: '',
});

function submit() {
    form.post(props.urls.store);
}
</script>

<template>
    <Head :title="t('lab.form.title')" />
    <div class="kit-page is-narrow">
        <PageHeader :title="t('lab.form.title')" :description="t('lab.form.description')" />

        <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
            <Card :title="t('lab.form.section')">
                <div class="grid gap-4 sm:grid-cols-2">
                    <EntitySelect
                        v-model="form.formula_version_id"
                        label-key="lab.form.version"
                        hint-key="lab.form.version_hint"
                        placeholder-key="lab.form.version_placeholder"
                        :search-url="urls.versions"
                        :selected-label="version?.label ?? ''"
                        :error="form.errors.formula_version_id"
                        required
                        class="sm:col-span-2"
                    />
                    <DecimalField v-model="form.batch_size" label-key="lab.form.batch_size" :error="form.errors.batch_size" required />
                    <FormField :label="t('lab.form.batch_unit')" :error="form.errors.batch_unit" required>
                        <Select v-model="form.batch_unit" :options="batchUnits" />
                    </FormField>
                    <FormField :label="t('lab.form.trial_date')" :error="form.errors.trial_date" required>
                        <DatePicker v-model="form.trial_date" />
                    </FormField>
                    <FormField :label="t('lab.form.operator')" :hint="t('lab.form.operator_hint')" :error="form.errors.operator" required>
                        <Input v-model="form.operator" maxlength="255" />
                    </FormField>
                    <FormField :label="t('lab.form.notes')" :hint="t('lab.form.notes_hint')" :error="form.errors.notes" class="sm:col-span-2">
                        <Textarea v-model="form.notes" :rows="3" maxlength="1000" />
                    </FormField>
                </div>
            </Card>
            <div class="flex flex-wrap gap-2">
                <Button type="submit" icon="ph:test-tube" :loading="form.processing" data-testid="trial-submit">{{ t('lab.form.submit') }}</Button>
                <Button variant="ghost" :href="urls.index">{{ t('common.cancel') }}</Button>
            </div>
        </form>
    </div>
</template>
