<script setup>
/**
 * Fragrance and air care (S-14, L-04 to L-08, L-10), eight ways in one page (aerosol, reed / gel / car blends and candles are the
 * air-care ones, see their field components): a fine-fragrance batch (oil, fixative, ethanol at a
 * target %v/v, water; by mass, or by volume through the measured product density), the full cost of a bottle of that perfume
 * (oil, alcohol, process loss, bottle, sprayer, box; the recipe is the batch's own), a water-based room spray (solubilizer
 * ratio from a TDS or a trial, clarity measured), a dilution of ethanol by the OIML R 22 mass balance with its contraction,
 * and the IFRA check of the oil % against the limit on the fragrance's certificate for its category.
 * Every price, loss, ratio, strength and limit is a sourced figure; the final ethanol %v/v is an indicator and says so.
 */
import { computed, reactive } from 'vue';
import { Alert, FormField, Input, SegmentedControl } from '@kit';
import CalculatorLayout from '../../components/calculators/CalculatorLayout.vue';
import DecimalField from '../../components/form/DecimalField.vue';
import FineRecipeFields from '../../components/calculators/FineRecipeFields.vue';
import PerfumeCostFields from '../../components/calculators/PerfumeCostFields.vue';
import AerosolFields from '../../components/calculators/AerosolFields.vue';
import AirCareBlendFields from '../../components/calculators/AirCareBlendFields.vue';
import CandleFields from '../../components/calculators/CandleFields.vue';
import RoomSprayFields from '../../components/calculators/RoomSprayFields.vue';
import QuantityInput from '../../components/form/QuantityInput.vue';
import SourcedInput from '../../components/form/SourcedInput.vue';
import { useI18n } from '../../composables/useI18n';
import { ALCOHOL_UNITS, BATCH_BY, LOSS_STAGES, MODES, PACKAGING_SLOTS, PRICE_ITEMS, blankFragrance, runFragrance } from '../../lib/calculators/fragrance';
import { sourcesOf } from '../../lib/calculators/sourced';

const props = defineProps({
    calculator: { type: String, required: true },
    urls: { type: Object, required: true },
    confidenceOptions: { type: Array, required: true },
    sourceKindOptions: { type: Array, required: true },
});

const { t } = useI18n();
const state = reactive(blankFragrance());
const outcome = computed(() => runFragrance(state));
const costFields = computed(() => [...PRICE_ITEMS.map((item) => state.cost.prices[item]), ...LOSS_STAGES.map((stage) => state.cost.loss[stage]), state.cost.fill, ...PACKAGING_SLOTS.flatMap((slot) => [state.cost.packaging[slot].price, state.cost.packaging[slot].reject])]);
const sources = computed(() => ({
    ...sourcesOf([state.fine.alcohol, state.fine.productDensity, state.fine.ifra, state.alcohol.source, state.ifra.limit, state.room.ratio, state.room.clarity, state.room.ifra, state.aerosol.ratio, state.aerosol.maxFill, state.aerosol.ifra, state.blend.dose, state.blend.ifra, state.candle.maxLoad, state.candle.ifra, ...costFields.value], () => null),
    // A loss filled from a lab trial links to that trial.
    ...Object.fromEntries(LOSS_STAGES.filter((stage) => state.cost.loss[stage].href).map((stage) => [state.cost.loss[stage].sourceId, { title: state.cost.loss[stage].sourceTitle, href: state.cost.loss[stage].href }])),
}));

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
            <FineRecipeFields v-model="state.fine" />
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

        <template v-else-if="state.mode === 'cost'">
            <p class="text-label text-ink-3">{{ t('calculators.fragrance.hints.cost') }}</p>
            <FineRecipeFields v-model="state.fine" />
            <SourcedInput v-model="state.fine.productDensity" label-key="calculators.fragrance.fields.product_density" hint-key="calculators.fragrance.cost.density_hint" :units="['g/mL', 'kg/L']" />
            <SourcedInput v-model="state.fine.ifra" label-key="calculators.fragrance.fields.ifra_limit" hint-key="calculators.fragrance.hints.ifra_limit" :units="['%w/w']" />
            <PerfumeCostFields v-model="state.cost" :urls="props.urls" />
        </template>

        <RoomSprayFields v-else-if="state.mode === 'room_spray'" v-model="state.room" />

        <AerosolFields v-else-if="state.mode === 'aerosol'" v-model="state.aerosol" />

        <AirCareBlendFields v-else-if="state.mode === 'blend'" v-model="state.blend" />

        <CandleFields v-else-if="state.mode === 'candle'" v-model="state.candle" />

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
            <Alert v-else-if="state.mode === 'aerosol'" tone="warning" icon="ph:warning" :title="t('calculators.fragrance.aerosol.filling_line_title')" data-testid="filling-line-note">
                {{ t('calculators.fragrance.aerosol.filling_line_note') }}
            </Alert>
        </template>
    </CalculatorLayout>
</template>
