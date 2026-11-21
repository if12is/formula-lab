<script setup>
/**
 * Adds or edits a dated exchange rate into EGP (1 unit of the currency = `rate` EGP, on a date, from a source with a
 * confidence). Editing passes the row; adding may pass a pre-filled currency and date (a link from a price that has no
 * rate for its date). The server refuses the base currency, a rate of zero and a duplicate (currency + date + source).
 */
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { path } from '../../lib/routes';
import { Button, DatePicker, FormField, Input, Modal, Select } from '@kit';
import { useI18n } from '../../composables/useI18n';
import { normalizeDecimalText } from '../../lib/engine';
import SourceSelect from '../materials/SourceSelect.vue';

const props = defineProps({
    /** The rate being edited ({ id, currency, rate, rate_date, source_id, source_title, confidence }), or null to add one. */
    row: { type: Object, default: null },
    /** Pre-fill for a new rate: { currency, rate_date }. */
    prefill: { type: Object, default: null },
    currencyOptions: { type: Array, required: true },
    confidenceOptions: { type: Array, required: true },
    sourceKindOptions: { type: Array, required: true },
    urls: { type: Object, required: true },
});

const open = defineModel('open', { type: Boolean, default: false });
const { t } = useI18n();

const editing = computed(() => props.row !== null);
const blank = () => ({
    from_currency: props.row?.currency ?? props.prefill?.currency ?? undefined,
    rate: props.row?.rate ?? '',
    rate_date: props.row?.rate_date ?? props.prefill?.rate_date ?? null,
    source_id: props.row?.source_id ?? null,
    confidence: props.row?.confidence ?? undefined,
});
const form = useForm(blank());
const sourceLabel = ref('');

watch(open, (isOpen) => {
    if (isOpen) {
        form.defaults(blank()).reset();
        form.clearErrors();
        sourceLabel.value = props.row?.source_title ?? '';
    }
});

const unitPrefix = computed(() => (form.from_currency ? t('exchange_rates.unit', { currency: form.from_currency }) : undefined));

function submit() {
    const options = { preserveScroll: true, onSuccess: () => { open.value = false; } };
    const send = form.transform((data) => ({ ...data, rate: normalizeDecimalText(data.rate), from_currency: data.from_currency ?? null, confidence: data.confidence ?? null }));

    if (editing.value) {
        send.put(path('settings.exchange-rates.update', props.row.id), options);
    } else {
        send.post(props.urls.store, options);
    }
}
</script>

<template>
    <Modal v-model:open="open" :title="editing ? t('exchange_rates.form.title_edit') : t('exchange_rates.form.title_add')" :description="t('exchange_rates.form.description')" size="md">
        <form id="exchange-rate-form" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="submit">
            <FormField :label="t('exchange_rates.form.currency')" :error="form.errors.from_currency" required>
                <Select v-model="form.from_currency" :options="currencyOptions" :placeholder="t('common.select_placeholder')" />
            </FormField>
            <FormField :label="t('exchange_rates.form.rate')" :hint="t('exchange_rates.form.rate_hint')" :error="form.errors.rate" required>
                <Input v-model="form.rate" ltr inputmode="decimal" :prefix="unitPrefix" :suffix="t('exchange_rates.form.rate_suffix')" />
            </FormField>
            <FormField :label="t('exchange_rates.form.rate_date')" :hint="t('exchange_rates.form.rate_date_hint')" :error="form.errors.rate_date" required>
                <DatePicker v-model="form.rate_date" />
            </FormField>
            <FormField :label="t('exchange_rates.form.confidence')" :hint="t('exchange_rates.form.confidence_hint')" :error="form.errors.confidence" required>
                <Select v-model="form.confidence" :options="confidenceOptions" :placeholder="t('common.select_placeholder')" />
            </FormField>
            <div class="sm:col-span-2">
                <SourceSelect v-model="form.source_id" :urls="urls" :kind-options="sourceKindOptions" :selected-label="sourceLabel" :error="form.errors.source_id" required />
            </div>
        </form>
        <template #footer>
            <Button variant="ghost" @click="open = false">{{ t('common.cancel') }}</Button>
            <Button type="submit" form="exchange-rate-form" :loading="form.processing">{{ editing ? t('exchange_rates.form.submit_edit') : t('exchange_rates.form.submit_add') }}</Button>
        </template>
    </Modal>
</template>
