<script setup>
/**
 * Fragrance and air care (S-14), three ways in one page: a fine-fragrance batch (oil, fixative, ethanol at a target %v/v,
 * water; by mass, or by volume through the measured product density), a dilution of ethanol by the OIML R 22 mass balance
 * with its contraction, and the IFRA check of the oil % against the limit on the fragrance's certificate for its category.
 * The stock ethanol's strength and the IFRA limit are sourced figures; the final ethanol %v/v is an indicator and says so.
 */
import { computed, reactive } from 'vue';
import { Alert, FormField, Input, SegmentedControl } from '@kit';
import CalculatorLayout from '../../components/calculators/CalculatorLayout.vue';
import DecimalField from '../../components/form/DecimalField.vue';
import QuantityInput from '../../components/form/QuantityInput.vue';
import SourcedInput from '../../components/form/SourcedInput.vue';
import { useI18n } from '../../composables/useI18n';
import { ALCOHOL_UNITS, BATCH_BY, MODES, blankFragrance, runFragrance } from '../../lib/calculators/fragrance';
import { sourcesOf } from '../../lib/calculators/sourced';

defineProps({
    calculator: { type: String, required: true },
    urls: { type: Object, required: true },
    confidenceOptions: { type: Array, required: true },
    sourceKindOptions: { type: Array, required: true },
});

const { t } = useI18n();
const state = reactive(blankFragrance());
const outcome = computed(() => runFragrance(state));
const sources = computed(() => sourcesOf([state.fine.alcohol, state.fine.productDensity, state.fine.ifra, state.alcohol.source, state.ifra.limit], () => null));

const modeOptions = computed(() => MODES.map((mode) => ({ value: mode, label: t(`calculators.fragrance.modes.${mode}`) })));
const batchByOptions = computed(() => BATCH_BY.map((by) => ({ value: by, label: t(`calculators.fragrance.batch_by.${by}`) })));
const batchUnits = (by) => (by === 'mass' ? ['g', 'kg'] : ['mL', 'L']);

// Switching between a mass and a volume batch swaps the unit with it: a volume cannot stay in grams.
function changeBatchBy(section, by) {
    section.batchBy = by;
    section.batch = { value: '', unit: by === 'mass' ? 'g' : 'mL' };
}
</script>

<template>
    <CalculatorLayout v-bind="$props" :outcome="outcome" :sources="sources">
        <SegmentedControl v-model="state.mode" :options="modeOptions" :aria-label="t('calculators.fragrance.mode')" block />

        <template v-if="state.mode === 'fine'">
            <p class="text-label text-ink-3">{{ t('calculators.fragrance.hints.fine') }}</p>
            <div class="grid gap-3 sm:grid-cols-3">
                <DecimalField v-model="state.fine.oilPct" label-key="calculators.fragrance.fields.oil_pct" hint-key="calculators.fragrance.hints.oil_pct" suffix="%" />
                <DecimalField v-model="state.fine.fixativePct" label-key="calculators.fragrance.fields.fixative_pct" suffix="%" />
                <DecimalField v-model="state.fine.addedWaterPct" label-key="calculators.fragrance.fields.added_water_pct" suffix="%" />
            </div>
            <SourcedInput v-model="state.fine.alcohol" label-key="calculators.fragrance.fields.alcohol_source" hint-key="calculators.fragrance.hints.alcohol_source" :units="ALCOHOL_UNITS" />
            <DecimalField v-model="state.fine.targetPct" label-key="calculators.fragrance.fields.target_vv" hint-key="calculators.fragrance.hints.target_vv" suffix="%v/v" />
            <FormField :label="t('calculators.fragrance.fields.batch_by')">
                <SegmentedControl :model-value="state.fine.batchBy" :options="batchByOptions" :aria-label="t('calculators.fragrance.fields.batch_by')" @update:model-value="(by) => changeBatchBy(state.fine, by)" />
            </FormField>
            <QuantityInput v-model="state.fine.batch" label-key="calculators.fragrance.fields.batch" :units="batchUnits(state.fine.batchBy)" />
            <SourcedInput v-model="state.fine.productDensity" label-key="calculators.fragrance.fields.product_density" hint-key="calculators.fragrance.hints.product_density" :units="['g/mL', 'kg/L']" />
            <SourcedInput v-model="state.fine.ifra" label-key="calculators.fragrance.fields.ifra_limit" hint-key="calculators.fragrance.hints.ifra_limit" :units="['%w/w']" />
            <FormField :label="t('calculators.fragrance.fields.ifra_category')" :hint="t('calculators.fragrance.hints.ifra_category')">
                <Input v-model="state.fine.category" maxlength="100" />
            </FormField>
        </template>

        <template v-else-if="state.mode === 'alcohol'">
            <p class="text-label text-ink-3">{{ t('calculators.fragrance.hints.alcohol') }}</p>
            <SourcedInput v-model="state.alcohol.source" label-key="calculators.fragrance.fields.alcohol_source" hint-key="calculators.fragrance.hints.alcohol_source" :units="ALCOHOL_UNITS" />
            <DecimalField v-model="state.alcohol.targetPct" label-key="calculators.fragrance.fields.target_vv" hint-key="calculators.fragrance.hints.target_vv" suffix="%v/v" />
            <FormField :label="t('calculators.fragrance.fields.batch_by')">
                <SegmentedControl :model-value="state.alcohol.batchBy" :options="batchByOptions" :aria-label="t('calculators.fragrance.fields.batch_by')" @update:model-value="(by) => changeBatchBy(state.alcohol, by)" />
            </FormField>
            <QuantityInput v-model="state.alcohol.batch" label-key="calculators.fragrance.fields.batch" :units="batchUnits(state.alcohol.batchBy)" />
        </template>

        <template v-else>
            <p class="text-label text-ink-3">{{ t('calculators.fragrance.hints.ifra') }}</p>
            <DecimalField v-model="state.ifra.oilPct" label-key="calculators.fragrance.fields.oil_pct" suffix="%" />
            <FormField :label="t('calculators.fragrance.fields.ifra_category')" :hint="t('calculators.fragrance.hints.ifra_category')">
                <Input v-model="state.ifra.category" maxlength="100" />
            </FormField>
            <SourcedInput v-model="state.ifra.limit" label-key="calculators.fragrance.fields.ifra_limit" hint-key="calculators.fragrance.hints.ifra_limit" :units="['%w/w']" />
        </template>

        <template #notes>
            <Alert v-if="state.mode === 'fine'" tone="info" icon="ph:info" :title="t('calculators.fragrance.indicator_title')" data-testid="indicator-note">
                {{ t('calculators.fragrance.indicator_note') }}
            </Alert>
        </template>
    </CalculatorLayout>
</template>
