<script setup>
/**
 * The tax of a product (owner decision 2026-11-21): switched off, or one of the allowed rates. It is saved on the formula and
 * feeds the price waterfall and what is published to Sanad. A disabled product has NO tax line, which is not a 0% rate (that one
 * shows a line whose value is zero); a product with no choice yet says so, and its price is incomplete. Rates, regimes, sources
 * and confidence come from the server (config/tax.php, tax_regimes); the check and the tax line are the engine's (lib/tax.js).
 */
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { Alert, Badge, Button, Card, DescriptionList, FormField, Select } from '@kit';
import { useI18n } from '../../composables/useI18n';
import { previewTax } from '../../lib/tax';
import DecimalField from '../form/DecimalField.vue';
import ExplainableNum from '../explain/ExplainableNum.vue';
import ConfidenceBadge from '../materials/ConfidenceBadge.vue';
import Num from '../Num.vue';

const props = defineProps({
    formulaId: { type: String, required: true },
    /** { setting, options, taxType, asOf, regimes, regimeInfo, urls } from App\Services\Tax\TaxRegimeService::editorProps. */
    tax: { type: Object, required: true },
    errors: { type: Object, default: () => ({}) },
});

const { t } = useI18n();
const choice = ref(props.tax.setting);
const price = ref('');
const saving = ref(false);

watch(() => props.tax.setting, (value) => { choice.value = value; });

const dirty = computed(() => (choice.value ?? null) !== (props.tax.setting ?? null));
const preview = computed(() => previewTax({
    setting: choice.value,
    regimes: props.tax.regimes,
    familyId: props.formulaId,
    taxType: props.tax.taxType,
    asOf: props.tax.asOf,
    price: price.value,
}));
const info = computed(() => (preview.value.state === 'applied' ? props.tax.regimeInfo.find((row) => row.ratePct === preview.value.ratePct && row.validFrom <= props.tax.asOf && (!row.validTo || row.validTo >= props.tax.asOf)) ?? null : null));
const sources = computed(() => Object.fromEntries(props.tax.regimeInfo.map((row) => [row.sourceId, { title: row.sourceTitle, href: null }])));

const stateTone = computed(() => ({ not_set: 'warning', missing: 'danger', disabled: 'neutral', applied: 'success' })[preview.value.state]);
const stateText = computed(() => {
    const { state, ratePct } = preview.value;

    if (state === 'applied') {
        return `${t('tax.state.applied')} ${ratePct}%`;
    }

    return t(`tax.state.${state === 'missing' ? 'not_set' : state}`);
});
const note = computed(() => {
    const { state, ratePct } = preview.value;

    if (state === 'not_set') {
        return t('tax.notes.not_set');
    }
    if (state === 'disabled') {
        return t('tax.notes.disabled');
    }

    return state === 'applied' && Number(ratePct) === 0 ? t('tax.notes.zero') : null;
});
const facts = computed(() => (info.value
    ? [
        { term: t('tax.regime.rate'), value: `${info.value.ratePct}%`, mono: true },
        { term: t('tax.regime.valid_from'), value: info.value.validFrom, mono: true },
        { term: t('tax.regime.valid_to'), value: info.value.validTo ?? t('tax.regime.open_ended'), mono: Boolean(info.value.validTo) },
        { term: t('tax.regime.source'), value: `${info.value.sourceTitle} (${info.value.sourceKind})` },
    ]
    : []));

function save() {
    router.put(props.tax.urls.update, { tax_setting: choice.value }, {
        preserveScroll: true,
        preserveState: true,
        onStart: () => { saving.value = true; },
        onFinish: () => { saving.value = false; },
    });
}
</script>

<template>
    <Card :title="t('tax.title')" :description="t('tax.description')" data-testid="tax-panel">
        <div class="flex flex-col gap-4">
            <div class="flex flex-wrap items-end gap-2">
                <FormField :label="t('tax.setting')" :hint="t('tax.setting_hint')" :error="errors.tax_setting" class="w-full sm:max-w-sm">
                    <Select v-model="choice" :options="tax.options" :placeholder="t('tax.select_placeholder')" data-testid="tax-setting" />
                </FormField>
                <Button variant="secondary" icon="ph:floppy-disk" :disabled="!dirty || !choice" :loading="saving" data-testid="tax-save" @click="save">{{ t('tax.save') }}</Button>
            </div>

            <div class="flex flex-wrap items-center gap-2" data-testid="tax-state" :data-state="preview.state">
                <span class="text-label text-ink-3">{{ t('tax.state.label') }}</span>
                <Badge :tone="stateTone" size="sm">{{ stateText }}</Badge>
            </div>
            <Alert v-if="note" :tone="preview.state === 'not_set' ? 'warning' : 'info'" :icon="preview.state === 'not_set' ? 'ph:warning' : 'ph:info'" data-testid="tax-note">{{ note }}</Alert>
            <Alert v-if="preview.state === 'missing'" tone="danger" icon="ph:warning" data-testid="tax-missing">
                <ul class="list-disc ps-5"><li v-for="gap in preview.missing" :key="gap.reason">{{ t(`engine.${gap.reason}`) }}</li></ul>
            </Alert>

            <div v-if="info" class="flex flex-col gap-3" data-testid="tax-regime">
                <div class="flex flex-wrap items-center gap-2">
                    <h3 class="type-h6 text-ink">{{ t('tax.regime.title') }}</h3>
                    <ConfidenceBadge :confidence="info.confidence" />
                    <Badge :tone="info.verification === 'pending' ? 'warning' : 'success'" size="sm" :icon="info.verification === 'pending' ? 'ph:hourglass' : 'ph:seal-check'" data-testid="tax-verification">{{ t(`tax.regime.verification_status.${info.verification}`) }}</Badge>
                </div>
                <DescriptionList :items="facts" :columns="2" />
                <Alert v-if="info.verification === 'pending'" tone="warning" icon="ph:warning" data-testid="tax-pending">{{ t('tax.regime.pending_alert') }}</Alert>
            </div>

            <div class="flex flex-col gap-3 border-t border-line pt-4" data-testid="tax-preview">
                <h3 class="type-h6 text-ink">{{ t('tax.preview.title') }}</h3>
                <DecimalField v-model="price" label-key="tax.preview.price" hint-key="tax.preview.hint" class="max-w-xs" data-testid="tax-price" />
                <dl v-if="preview.priceWithTax" class="grid gap-2 sm:grid-cols-3">
                    <div class="flex flex-col gap-1 rounded-lg border border-line p-3">
                        <dt class="text-label text-ink-3">{{ t('tax.preview.price') }}</dt>
                        <dd class="text-ink" data-testid="tax-price-before"><Num :value="price" :decimals="2" /> {{ t('tax.preview.egp') }}</dd>
                    </div>
                    <div class="flex flex-col gap-1 rounded-lg border border-line p-3" data-testid="tax-line" :data-has-line="preview.taxAmount ? 'true' : 'false'">
                        <dt class="text-label text-ink-3">{{ t('tax.preview.tax_amount') }}</dt>
                        <dd v-if="preview.taxAmount" class="text-ink" data-testid="tax-amount"><ExplainableNum :traced="preview.taxAmount" :decimals="2" :label="t('tax.preview.tax_amount')" :sources="sources" /> {{ t('tax.preview.egp') }}</dd>
                        <dd v-else class="text-ink-3" data-testid="tax-no-line">{{ t('tax.preview.no_tax_line') }}</dd>
                    </div>
                    <div class="flex flex-col gap-1 rounded-lg border border-line p-3">
                        <dt class="text-label text-ink-3">{{ preview.taxAmount ? t('tax.preview.price_with_tax') : t('tax.preview.final_price') }}</dt>
                        <dd class="text-ink" data-testid="tax-price-after"><ExplainableNum :traced="preview.priceWithTax" :decimals="2" :label="t('tax.preview.price_with_tax')" :sources="sources" /> {{ t('tax.preview.egp') }}</dd>
                    </div>
                </dl>
                <p class="text-label text-ink-3">{{ t('tax.sanad.note') }}</p>
            </div>
        </div>
    </Card>
</template>
