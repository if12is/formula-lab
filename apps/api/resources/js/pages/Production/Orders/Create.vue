<script setup>
/**
 * Opens a production order: the APPROVED formula version to make (searched on the server; the formula editor links here with the
 * version already chosen), the batch size and unit, the balance resolution the weights are rounded to, the units to make, the planned
 * and expiry dates and the packaging used. The weighing sheet and the Sanad message are worked out from this by the engine.
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
    resolutions: { type: Array, required: true },
    defaultResolution: { type: String, required: true },
    today: { type: String, required: true },
    urls: { type: Object, required: true },
});

const { t } = useI18n();
const form = useForm({
    formula_version_id: props.version?.value ?? null,
    batch_size: '',
    batch_unit: props.batchUnits[0].value,
    scale_resolution_g: props.defaultResolution,
    units_count: '',
    planned_on: props.today,
    expiry_date: null,
    branch_code: '',
    notes: '',
    packaging: [],
});

function addPackaging() {
    form.packaging.push({ trade_material_id: null, quantity: '' });
}

function removePackaging(index) {
    form.packaging.splice(index, 1);
}

const errorOf = (key) => form.errors[key];

function submit() {
    form.post(props.urls.store);
}
</script>

<template>
    <Head :title="t('production.form.title')" />
    <div class="kit-page is-narrow">
        <PageHeader :title="t('production.form.title')" :description="t('production.form.description')" />

        <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
            <Card :title="t('production.form.section_batch')">
                <div class="grid gap-4 sm:grid-cols-2">
                    <EntitySelect
                        v-model="form.formula_version_id"
                        label-key="production.form.version"
                        hint-key="production.form.version_hint"
                        placeholder-key="production.form.version_placeholder"
                        :search-url="urls.versions"
                        :selected-label="version?.label ?? ''"
                        :error="form.errors.formula_version_id"
                        required
                        class="sm:col-span-2"
                    />
                    <DecimalField v-model="form.batch_size" label-key="production.form.batch_size" :error="form.errors.batch_size" required />
                    <FormField :label="t('production.form.batch_unit')" :error="form.errors.batch_unit" required>
                        <Select v-model="form.batch_unit" :options="batchUnits" />
                    </FormField>
                    <FormField :label="t('production.form.resolution')" :hint="t('production.form.resolution_hint')" :error="form.errors.scale_resolution_g" required>
                        <Select v-model="form.scale_resolution_g" :options="resolutions" data-testid="order-resolution" />
                    </FormField>
                    <DecimalField v-model="form.units_count" label-key="production.form.units_count" hint-key="production.form.units_hint" :error="form.errors.units_count" />
                    <FormField :label="t('production.form.planned_on')" :error="form.errors.planned_on" required>
                        <DatePicker v-model="form.planned_on" />
                    </FormField>
                    <FormField :label="t('production.form.expiry_date')" :hint="t('production.form.expiry_hint')" :error="form.errors.expiry_date">
                        <DatePicker v-model="form.expiry_date" />
                    </FormField>
                    <FormField :label="t('production.form.branch_code')" :hint="t('production.form.branch_hint')" :error="form.errors.branch_code">
                        <Input v-model="form.branch_code" ltr maxlength="100" />
                    </FormField>
                    <FormField :label="t('production.form.notes')" :error="form.errors.notes" class="sm:col-span-2">
                        <Textarea v-model="form.notes" :rows="2" maxlength="1000" />
                    </FormField>
                </div>
            </Card>

            <Card :title="t('production.form.section_packaging')" :description="t('production.form.packaging_hint')">
                <div class="flex flex-col gap-3">
                    <div v-for="(line, index) in form.packaging" :key="index" class="grid items-start gap-3 sm:grid-cols-[1fr_10rem_auto]" :data-testid="`packaging-row-${index + 1}`">
                        <EntitySelect
                            v-model="line.trade_material_id"
                            label-key="production.form.packaging_item"
                            :label-params="{ line: index + 1 }"
                            :search-url="urls.packaging"
                            :error="errorOf(`packaging.${index}.trade_material_id`)"
                            bare
                        />
                        <DecimalField v-model="line.quantity" label-key="production.form.packaging_quantity" :label-params="{ line: index + 1 }" :error="errorOf(`packaging.${index}.quantity`)" bare />
                        <Button variant="ghost" icon="ph:trash" :aria-label="t('production.form.remove_packaging', { line: index + 1 })" @click="removePackaging(index)" />
                    </div>
                    <div>
                        <Button variant="secondary" icon="ph:plus" data-testid="add-packaging" @click="addPackaging">{{ t('production.form.add_packaging') }}</Button>
                    </div>
                </div>
            </Card>

            <div class="flex flex-wrap gap-2">
                <Button type="submit" icon="ph:factory" :loading="form.processing" data-testid="order-submit">{{ t('production.form.submit') }}</Button>
                <Button variant="ghost" :href="urls.index">{{ t('common.cancel') }}</Button>
            </div>
        </form>
    </div>
</template>
