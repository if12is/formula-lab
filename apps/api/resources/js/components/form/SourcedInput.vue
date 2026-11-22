<script setup>
/**
 * A figure that needs a source (CLAUDE.md rule 2), in one compact row: the value (with its unit), the source it comes
 * from (searched, or created on the spot) and the confidence level. The date the value is valid from is the source's own,
 * so it is never typed: it is shown once a source is chosen. Until value, source and confidence are all there the
 * engine is handed nothing (toSourced in lib/calculators/sourced.js) and reports the figure as missing.
 *
 *   <SourcedInput v-model="state.purity" label-key="calculators.soap.fields.purity" :units="['%w/w']" />
 *
 * Source and confidence lookups come from CalculatorLayout (useCalculatorLookups); pass `urls`, `source-kind-options`
 * and `confidence-options` only to use it outside a calculator page.
 *
 * The model is a blankSourced() object; its fields are edited in place. `units` with more than one entry shows a unit
 * Select under the value (the row is three columns in a card, about 150px for the value: a Select inside the suffix leaves the
 * input no width even with the kit's 1.10 addon sizing, so this is a layout choice and not a kit workaround); one unit is printed as the suffix; none (a dimensionless HLB) prints `unit-key`'s text if given.
 * `fromMaterial` ({ id, label }) says the value was prefilled from a material's property (resolveValue) and clears
 * as soon as the person edits value or source.
 */
import { computed, watch } from 'vue';
import { Badge, FormField, Input, Select } from '@kit';
import { useI18n } from '../../composables/useI18n';
import { useCalculatorLookups } from '../../composables/useCalculatorLookups';
import { normalizeDecimalText } from '../../lib/engine';
import SourceSelect from '../materials/SourceSelect.vue';

const props = defineProps({
    labelKey: { type: String, required: true },
    hintKey: { type: String, default: null },
    units: { type: Array, default: () => [] },
    unitKey: { type: String, default: null },
    /** Lookup addresses and option lists; by default the ones CalculatorLayout provides. */
    urls: { type: Object, default: null },
    sourceKindOptions: { type: Array, default: null },
    confidenceOptions: { type: Array, default: null },
    labelParams: { type: Object, default: () => ({}) },
    /** The figure is a finding (a clarity observation) and not a number: only its source, confidence and date are asked for. */
    valueless: { type: Boolean, default: false },
});

const field = defineModel({ type: Object, required: true });
const { t } = useI18n();
const provided = useCalculatorLookups();
const lookups = computed(() => ({
    urls: props.urls ?? provided.urls,
    sourceKindOptions: props.sourceKindOptions ?? provided.sourceKindOptions,
    confidenceOptions: props.confidenceOptions ?? provided.confidenceOptions,
}));

const name = computed(() => t(props.labelKey, props.labelParams));
const unitOptions = computed(() => props.units.map((unit) => ({ value: unit, label: t(`calculators.units.${unit}`) })));
const suffix = computed(() => (props.units.length === 1 ? t(`calculators.units.${props.units[0]}`) : props.unitKey ? t(props.unitKey) : undefined));
const sourceMissing = computed(() => !props.valueless && field.value.value !== '' && !field.value.sourceId);

// A figure typed or sourced afresh is no longer the material's own.
function typedValue(value) {
    field.value.value = normalizeDecimalText(value);
    field.value.fromMaterial = null;
}

function picked(option) {
    field.value.sourceId = option.value;
    field.value.sourceTitle = option.label;
    field.value.validFrom = option.retrieved_at ?? null;
    field.value.fromMaterial = null;
}

// Clearing the source box clears the choice everywhere, so a stale id is never sent to the engine.
watch(() => field.value.sourceId, (id) => {
    if (id === null) {
        field.value.sourceTitle = '';
        field.value.validFrom = null;
    }
});
</script>

<template>
    <fieldset class="min-w-0" :data-sourced="labelKey">
        <legend class="mb-2 flex flex-wrap items-center gap-2 text-sm font-semibold text-ink">
            {{ name }}
            <Badge v-if="field.fromMaterial" tone="info" size="sm" icon="ph:cube">{{ t('calculators.sourced.from_material', { material: field.fromMaterial.label }) }}</Badge>
        </legend>
        <p v-if="hintKey" class="-mt-1 mb-2 text-label text-ink-3">{{ t(hintKey) }}</p>
        <div class="grid gap-3" :class="valueless ? 'sm:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)]' : 'sm:grid-cols-[minmax(0,1fr)_minmax(0,1.5fr)_minmax(0,1fr)]'">
            <div v-if="!valueless" class="flex min-w-0 flex-col gap-3">
                <FormField :label="t('calculators.sourced.value_of', { name })">
                    <Input :model-value="field.value" ltr inputmode="decimal" :suffix="suffix" @update:model-value="typedValue" />
                </FormField>
                <FormField v-if="units.length > 1" :label="t('calculators.fields.unit_of', { name })">
                    <Select v-model="field.unit" :options="unitOptions" />
                </FormField>
            </div>
            <SourceSelect
                v-model="field.sourceId"
                bare
                label-key="calculators.sourced.source_of"
                :label-params="{ name }"
                :selected-label="field.sourceTitle"
                :urls="lookups.urls"
                :kind-options="lookups.sourceKindOptions"
                @select="picked"
            />
            <FormField :label="t('calculators.sourced.confidence_of', { name })">
                <Select v-model="field.confidence" :options="lookups.confidenceOptions" :placeholder="t('common.select_placeholder')" />
            </FormField>
        </div>
        <p v-if="sourceMissing" class="mt-1 text-label text-warning-ink" data-testid="source-required">{{ t('calculators.sourced.source_required') }}</p>
        <p v-else-if="field.validFrom" class="mt-1 text-label text-ink-3">
            {{ t('calculators.sourced.dated') }} <bdi dir="ltr" class="tnum">{{ field.validFrom }}</bdi>
        </p>
    </fieldset>
</template>
