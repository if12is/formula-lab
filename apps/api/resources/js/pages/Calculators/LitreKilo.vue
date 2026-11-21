<script setup>
/**
 * Litre <-> kilo: the mass of a volume or the volume of a mass, through the density you measured. There is no default
 * density: without one the engine reports it missing instead of guessing. A material's own density is prefilled through
 * resolveValue when it has one.
 */
import { computed, reactive } from 'vue';
import { FormField, SegmentedControl, Select } from '@kit';
import CalculatorLayout from '../../components/calculators/CalculatorLayout.vue';
import MaterialPicker from '../../components/calculators/MaterialPicker.vue';
import QuantityInput from '../../components/form/QuantityInput.vue';
import SourcedInput from '../../components/form/SourcedInput.vue';
import { useI18n } from '../../composables/useI18n';
import { DIRECTIONS, UNITS, blankLitreKilo, runLitreKilo } from '../../lib/calculators/litreKilo';
import { fillFromMaterial, sourcesOf } from '../../lib/calculators/sourced';
import { path } from '../../lib/routes';

defineProps({
    calculator: { type: String, required: true },
    urls: { type: Object, required: true },
    confidenceOptions: { type: Array, required: true },
    sourceKindOptions: { type: Array, required: true },
});

const { t } = useI18n();
const state = reactive(blankLitreKilo());
const outcome = computed(() => runLitreKilo(state));

const hrefOf = (id) => path('materials.show', { material: id, tab: 'properties' });
const entities = computed(() => (state.material ? { [state.material.label]: { href: hrefOf(state.material.value) } } : {}));
const sources = computed(() => sourcesOf([state.density], hrefOf));

const toVolume = computed(() => state.direction === DIRECTIONS[0]);
const directionOptions = computed(() => DIRECTIONS.map((direction) => ({ value: direction, label: t(`calculators.litre-kilo.directions.${direction}`) })));
const amountUnits = computed(() => (toVolume.value ? UNITS.mass : UNITS.volume));
const resultUnits = computed(() => (toVolume.value ? UNITS.volume : UNITS.mass));
const resultUnitOptions = computed(() => resultUnits.value.map((unit) => ({ value: unit, label: t(`calculators.units.${unit}`) })));

// Switching direction swaps what is typed and what is asked for: a mass in kg becomes a volume in L, and back.
function changeDirection(direction) {
    state.direction = direction;
    const volumeToMass = direction === DIRECTIONS[1];
    state.amount = { value: '', unit: volumeToMass ? 'L' : 'kg' };
    state.resultUnit = volumeToMass ? 'kg' : 'L';
}

const pickDensity = (option) => fillFromMaterial(state.density, option, 'density');
</script>

<template>
    <CalculatorLayout v-bind="$props" :outcome="outcome" :entities="entities" :sources="sources">
        <FormField :label="t('calculators.litre-kilo.fields.direction')">
            <SegmentedControl :model-value="state.direction" :options="directionOptions" :aria-label="t('calculators.litre-kilo.fields.direction')" @update:model-value="changeDirection" />
        </FormField>
        <MaterialPicker v-model="state.material" label-key="calculators.litre-kilo.fields.material" hint-key="calculators.litre-kilo.hints.material" @picked="pickDensity" />
        <QuantityInput v-model="state.amount" :label-key="toVolume ? 'calculators.litre-kilo.fields.mass' : 'calculators.litre-kilo.fields.volume'" :units="amountUnits" />
        <SourcedInput v-model="state.density" label-key="calculators.litre-kilo.fields.density" hint-key="calculators.litre-kilo.hints.density" :units="['g/mL', 'kg/L']" />
        <FormField :label="t('calculators.litre-kilo.fields.result_unit')">
            <Select v-model="state.resultUnit" :options="resultUnitOptions" />
        </FormField>
    </CalculatorLayout>
</template>
