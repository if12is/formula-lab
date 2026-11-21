<script setup>
/**
 * Percent from weight (F-15): the % w/w of a material in a batch, from weights or volumes. A volume needs its density: the
 * material's for the amount, the measured product density for the batch; a missing one is reported, never defaulted.
 */
import { computed, reactive } from 'vue';
import CalculatorLayout from '../../components/calculators/CalculatorLayout.vue';
import MaterialPicker from '../../components/calculators/MaterialPicker.vue';
import QuantityInput from '../../components/form/QuantityInput.vue';
import SourcedInput from '../../components/form/SourcedInput.vue';
import { AMOUNT_UNITS, BATCH_UNITS, blankPercent, needsMaterialDensity, needsProductDensity, runPercent } from '../../lib/calculators/percent';
import { fillFromMaterial, sourcesOf } from '../../lib/calculators/sourced';
import { path } from '../../lib/routes';

defineProps({
    calculator: { type: String, required: true },
    urls: { type: Object, required: true },
    confidenceOptions: { type: Array, required: true },
    sourceKindOptions: { type: Array, required: true },
});

const state = reactive(blankPercent());
const outcome = computed(() => runPercent(state));

const hrefOf = (id) => path('materials.show', { material: id, tab: 'properties' });
const entities = computed(() => (state.material ? { [state.material.label]: { href: hrefOf(state.material.value) } } : {}));
const sources = computed(() => sourcesOf([state.materialDensity, state.productDensity], hrefOf));

const pickDensity = (option) => fillFromMaterial(state.materialDensity, option, 'density');
</script>

<template>
    <CalculatorLayout v-bind="$props" :outcome="outcome" :entities="entities" :sources="sources">
        <MaterialPicker v-model="state.material" label-key="calculators.percent-from-weight.fields.material" hint-key="calculators.percent-from-weight.hints.material" @picked="pickDensity" />
        <QuantityInput v-model="state.amount" label-key="calculators.percent-from-weight.fields.amount" :units="AMOUNT_UNITS" />
        <SourcedInput v-if="needsMaterialDensity(state)" v-model="state.materialDensity" label-key="calculators.percent-from-weight.fields.material_density" hint-key="calculators.percent-from-weight.hints.density" :units="['g/mL', 'kg/L']" />
        <QuantityInput v-model="state.batch" label-key="calculators.percent-from-weight.fields.batch" :units="BATCH_UNITS" />
        <SourcedInput v-if="needsProductDensity(state)" v-model="state.productDensity" label-key="calculators.percent-from-weight.fields.product_density" hint-key="calculators.percent-from-weight.hints.product_density" :units="['g/mL', 'kg/L']" />
    </CalculatorLayout>
</template>
