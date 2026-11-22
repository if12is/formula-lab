<script setup>
/**
 * The recipe of a fine fragrance, shared by the batch calculator and the perfume cost (one source of truth): oil, fixative
 * and added water as % of the product, the stock alcohol's strength (sourced) and the strength of the carrier it is diluted to.
 * The model is state.fine of lib/calculators/fragrance.js; its fields are edited in place.
 */
import DecimalField from '../form/DecimalField.vue';
import SourcedInput from '../form/SourcedInput.vue';
import { ALCOHOL_UNITS } from '../../lib/calculators/fragrance';

const recipe = defineModel({ type: Object, required: true });
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="grid gap-3 sm:grid-cols-3">
            <DecimalField v-model="recipe.oilPct" label-key="calculators.fragrance.fields.oil_pct" hint-key="calculators.fragrance.hints.oil_pct" suffix="%" />
            <DecimalField v-model="recipe.fixativePct" label-key="calculators.fragrance.fields.fixative_pct" suffix="%" />
            <DecimalField v-model="recipe.addedWaterPct" label-key="calculators.fragrance.fields.added_water_pct" suffix="%" />
        </div>
        <SourcedInput v-model="recipe.alcohol" label-key="calculators.fragrance.fields.alcohol_source" hint-key="calculators.fragrance.hints.alcohol_source" :units="ALCOHOL_UNITS" />
        <DecimalField v-model="recipe.targetPct" label-key="calculators.fragrance.fields.target_vv" hint-key="calculators.fragrance.hints.target_vv" suffix="%v/v" />
    </div>
</template>
