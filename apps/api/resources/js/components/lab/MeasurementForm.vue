<script setup>
/**
 * Records one measurement on an open trial. The value is typed as text and sent as text, and an empty value is refused:
 * a reading nobody took is simply not recorded, it is never stored as zero. The unit follows the property, a protocol
 * template can stand in for the method (its instrument shows as a reminder), and a measurement is always a local lab
 * result (L1), stated on the form. A viscosity reading may state the salt % added: it is then a point of the formula's salt curve.
 */
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Alert, Button, Card, DateTimeInput, FormField, Input, Select, Textarea } from '@kit';
import { useI18n } from '../../composables/useI18n';
import DecimalField from '../form/DecimalField.vue';

const props = defineProps({
    /** { propertyOptions, units, protocols, now } from App\Services\Lab\LabTrialDetailService. */
    choices: { type: Object, required: true },
    url: { type: String, required: true },
});

const { t } = useI18n();
const NO_PROTOCOL = 'none';

const blank = () => ({
    property: props.choices.propertyOptions[0].value,
    value: '',
    unit: props.choices.units[props.choices.propertyOptions[0].value][0].value,
    salt_pct: '',
    protocol: NO_PROTOCOL,
    method: '',
    instrument: '',
    measured_at: props.choices.now,
    note: '',
});
const form = useForm(blank());

const propertyOptions = computed(() => props.choices.propertyOptions);
const unitOptions = computed(() => props.choices.units[form.property] ?? []);
const protocolOptions = computed(() => [
    { value: NO_PROTOCOL, label: t('lab.measurement.protocol_none') },
    ...props.choices.protocols.filter((protocol) => protocol.property === form.property).map(({ value, label }) => ({ value, label })),
]);
const isViscosity = computed(() => form.property === props.choices.saltProperty);
const protocol = computed(() => props.choices.protocols.find((entry) => entry.value === form.protocol) ?? null);

// A new property brings its own units and protocols: keep nothing that no longer fits.
watch(() => form.property, () => {
    form.unit = unitOptions.value[0]?.value ?? '';
    form.protocol = NO_PROTOCOL;
    form.salt_pct = '';
    form.clearErrors('salt_pct');
});
// A protocol names its unit: follow it so the reading and its template agree.
watch(protocol, (selected) => {
    if (selected && unitOptions.value.some((unit) => unit.value === selected.unit)) {
        form.unit = selected.unit;
    }
});

function submit() {
    form
        .transform((data) => ({ ...data, lab_protocol_id: data.protocol === NO_PROTOCOL ? null : data.protocol }))
        .post(props.url, {
            preserveScroll: true,
            onSuccess: () => form.reset('value', 'note', 'method', 'salt_pct'),
        });
}
</script>

<template>
    <Card :title="t('lab.measurement.title')" :description="t('lab.measurement.description')" data-testid="measurement-form">
        <form class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="submit">
            <FormField :label="t('lab.measurement.property')" :error="form.errors.property" required>
                <Select v-model="form.property" :options="propertyOptions" data-testid="measurement-property" />
            </FormField>
            <div class="grid grid-cols-[minmax(0,1fr)_8rem] gap-3">
                <DecimalField v-model="form.value" label-key="lab.measurement.value" hint-key="lab.measurement.value_hint" :error="form.errors.value" required data-testid="measurement-value" />
                <FormField :label="t('lab.measurement.unit')" :error="form.errors.unit" required>
                    <Select v-model="form.unit" :options="unitOptions" data-testid="measurement-unit" />
                </FormField>
            </div>
            <DecimalField v-if="isViscosity" v-model="form.salt_pct" label-key="lab.measurement.salt_pct" hint-key="lab.measurement.salt_pct_hint" :error="form.errors.salt_pct" suffix="%" data-testid="measurement-salt" />
            <FormField :label="t('lab.measurement.protocol')" :hint="t('lab.measurement.protocol_hint')" :error="form.errors.lab_protocol_id">
                <Select v-model="form.protocol" :options="protocolOptions" data-testid="measurement-protocol" />
            </FormField>
            <FormField :label="t('lab.measurement.method')" :hint="t('lab.measurement.method_hint')" :error="form.errors.method">
                <Input v-model="form.method" maxlength="255" data-testid="measurement-method" />
            </FormField>
            <Alert v-if="protocol" tone="info" icon="ph:info" class="sm:col-span-2" data-testid="protocol-hint">
                {{ t('lab.measurement.instrument_needed', { instrument: protocol.instrument }) }}
            </Alert>
            <FormField :label="t('lab.measurement.instrument')" :hint="t('lab.measurement.instrument_hint')" :error="form.errors.instrument" required>
                <Input v-model="form.instrument" maxlength="255" data-testid="measurement-instrument" />
            </FormField>
            <FormField :label="t('lab.measurement.measured_at')" :error="form.errors.measured_at" required>
                <DateTimeInput v-model="form.measured_at" />
            </FormField>
            <FormField :label="t('lab.measurement.note')" :error="form.errors.note" class="sm:col-span-2">
                <Textarea v-model="form.note" :rows="2" maxlength="1000" />
            </FormField>
            <div class="sm:col-span-2">
                <Button type="submit" icon="ph:floppy-disk" :loading="form.processing" data-testid="measurement-submit">{{ t('lab.measurement.submit') }}</Button>
            </div>
        </form>
    </Card>
</template>
