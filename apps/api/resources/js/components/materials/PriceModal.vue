<script setup>
/**
 * Adds a dated, sourced price quote (per kg, in EGP by default or any supported currency) to a material. A quote with a
 * better confidence level than an estimate (L7) is what lets the engine cost a formula with it. For a foreign currency the
 * modal asks the server which exchange rate applies on the quote date (the same lookup the engine's inputs come from) and
 * says so, or says there is none and links to add one: the cost needs it.
 */
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { path } from '../../lib/routes';
import { Alert, Button, DatePicker, FormField, Input, Modal, Select } from '@kit';
import { useI18n } from '../../composables/useI18n';
import { normalizeDecimalText } from '../../lib/engine';
import SourceSelect from './SourceSelect.vue';

const props = defineProps({
    materialId: { type: String, required: true },
    urls: { type: Object, required: true },
    currencyOptions: { type: Array, required: true },
    confidenceOptions: { type: Array, required: true },
    sourceKindOptions: { type: Array, required: true },
});

const open = defineModel('open', { type: Boolean, default: false });
const { t } = useI18n();

const blank = () => ({ currency: 'EGP', amount: '', quoted_on: null, source_id: null, confidence: undefined });
const form = useForm(blank());
const estimateNotice = ref(false);
/** What the server says about the rate of the quote date: { rate } | { rate: null } | null while unknown. */
const lookup = ref(null);
const foreign = computed(() => form.currency !== 'EGP');

watch(open, (isOpen) => {
    if (isOpen) {
        form.defaults(blank()).reset();
        form.clearErrors();
    }
});

watch(() => form.confidence, (confidence) => { estimateNotice.value = confidence === 'L7_estimate'; });

// The rate that would convert this quote: asked of the server on every change of currency or date, never worked out here.
watch(() => [open.value, form.currency, form.quoted_on], async ([isOpen, currency, date]) => {
    lookup.value = null;

    if (!isOpen || currency === 'EGP' || !date) {
        return;
    }

    try {
        const response = await fetch(path('settings.exchange-rates.lookup', { currency, date }), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });

        if (response.ok && form.currency === currency && form.quoted_on === date) {
            lookup.value = await response.json();
        }
    } catch {
        lookup.value = null;
    }
});

const addRateHref = computed(() => path('settings.exchange-rates.index', { new_currency: form.currency, new_date: form.quoted_on }));

function submit() {
    form
        .transform((data) => ({ ...data, amount: normalizeDecimalText(data.amount), confidence: data.confidence ?? null }))
        .post(path('materials.prices.store', props.materialId), { preserveScroll: true, onSuccess: () => { open.value = false; } });
}
</script>

<template>
    <Modal v-model:open="open" :title="t('materials.price.title')" :description="t('materials.price.description')" size="md">
        <form id="price-form" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="submit">
            <FormField :label="t('materials.price.currency')" :hint="t('materials.price.currency_hint')" :error="form.errors.currency" required>
                <Select v-model="form.currency" :options="currencyOptions" />
            </FormField>
            <FormField :label="t('materials.price.amount')" :error="form.errors.amount" required>
                <Input v-model="form.amount" ltr inputmode="decimal" :suffix="t('formulas.units.currency_per_kg', { currency: form.currency })" />
            </FormField>
            <FormField :label="t('materials.price.quoted_on')" :hint="t('materials.price.quoted_on_hint')" :error="form.errors.quoted_on" required>
                <DatePicker v-model="form.quoted_on" />
            </FormField>
            <div class="sm:col-span-2">
                <SourceSelect v-model="form.source_id" :urls="urls" :kind-options="sourceKindOptions" :error="form.errors.source_id" required />
            </div>
            <FormField :label="t('materials.fields.confidence')" :hint="t('materials.fields.confidence_hint')" :error="form.errors.confidence" required class="sm:col-span-2">
                <Select v-model="form.confidence" :options="confidenceOptions" :placeholder="t('common.select_placeholder')" />
            </FormField>
            <Alert v-if="foreign && lookup?.rate" tone="info" icon="ph:currency-circle-dollar" class="sm:col-span-2" data-testid="fx-found">
                {{ t('exchange_rates.lookup.found', { rate: lookup.rate.rate, date: lookup.rate.rate_date, source: lookup.rate.source_title }) }}
            </Alert>
            <Alert v-else-if="foreign && lookup && !lookup.rate" tone="warning" icon="ph:warning" class="sm:col-span-2" data-testid="fx-missing">
                {{ t('exchange_rates.lookup.missing', { currency: form.currency, date: form.quoted_on }) }}
                <a :href="addRateHref" class="text-primary-ink underline underline-offset-2">{{ t('exchange_rates.lookup.add') }}</a>
            </Alert>
            <Alert v-if="estimateNotice" tone="warning" class="sm:col-span-2">{{ t('materials.price.estimate_notice') }}</Alert>
        </form>
        <template #footer>
            <Button variant="ghost" @click="open = false">{{ t('common.cancel') }}</Button>
            <Button type="submit" form="price-form" :loading="form.processing">{{ t('materials.price.submit') }}</Button>
        </template>
    </Modal>
</template>
