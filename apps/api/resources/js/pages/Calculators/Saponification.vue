<script setup>
/**
 * Saponification (F-06): the lye (NaOH or KOH) for a set of oils at a superfat, the lye's water by any of three
 * methods (all three shown), and how far the lye moves between the SAP's min and max. Every SAP value and the lye's
 * purity carry a source and a confidence; a material's own SAP is prefilled through the engine's resolveValue.
 * All arithmetic is the engine's (lib/calculators/soap.js).
 */
import { computed, reactive } from 'vue';
import { FormField, SegmentedControl } from '@kit';
import CalculatorLayout from '../../components/calculators/CalculatorLayout.vue';
import LineList from '../../components/calculators/LineList.vue';
import MaterialPicker from '../../components/calculators/MaterialPicker.vue';
import DecimalField from '../../components/form/DecimalField.vue';
import QuantityInput from '../../components/form/QuantityInput.vue';
import SourcedInput from '../../components/form/SourcedInput.vue';
import { useI18n } from '../../composables/useI18n';
import { BASES, SAP_UNITS, WATER_METHODS, applyMaterialToOil, blankOil, blankSoap, oilNames, runSoap } from '../../lib/calculators/soap';
import { sourcesOf } from '../../lib/calculators/sourced';
import { path } from '../../lib/routes';

defineProps({
    calculator: { type: String, required: true },
    urls: { type: Object, required: true },
    confidenceOptions: { type: Array, required: true },
    sourceKindOptions: { type: Array, required: true },
});

const { t } = useI18n();
let sequence = 0;
const nextKey = () => `oil-${(sequence += 1)}`;
const state = reactive(blankSoap(nextKey()));

const fallbackName = (n) => t('calculators.saponification.oil_n', { n });
const outcome = computed(() => runSoap(state, fallbackName));

const hrefOf = (id) => path('materials.show', { material: id, tab: 'properties' });
const entities = computed(() => {
    const names = oilNames(state.lines, fallbackName);

    return Object.fromEntries(state.lines.flatMap((line, index) => (line.material ? [[names[index], { href: hrefOf(line.material.value) }]] : [])));
});
const sources = computed(() => sourcesOf([state.purity, ...state.lines.map((line) => line.sap)], (id) => hrefOf(id)));

const baseOptions = BASES.map((base) => ({ value: base, label: base }));
const methodOptions = computed(() => WATER_METHODS.map((method) => ({ value: method, label: t(`calculators.saponification.water_methods.${method}`) })));
const waterSuffix = computed(() => ({ ratio: undefined, lyeConcentration: '%', pctOfOils: '%' })[state.water.method]);

const addOil = () => state.lines.push(blankOil(nextKey()));
const removeOil = (key) => { state.lines = state.lines.filter((line) => line.key !== key); };
</script>

<template>
    <CalculatorLayout v-bind="$props" :outcome="outcome" :entities="entities" :sources="sources">
        <FormField :label="t('calculators.saponification.fields.base')">
            <SegmentedControl v-model="state.base" :options="baseOptions" :aria-label="t('calculators.saponification.fields.base')" />
        </FormField>

        <SourcedInput v-model="state.purity" label-key="calculators.saponification.fields.purity" hint-key="calculators.saponification.hints.purity" :units="['%w/w']" />

        <DecimalField v-model="state.superfat" label-key="calculators.saponification.fields.superfat" hint-key="calculators.saponification.hints.superfat" suffix="%" />

        <div class="flex flex-col gap-3">
            <h3 class="type-h6 text-ink">{{ t('calculators.saponification.water_title') }}</h3>
            <FormField :label="t('calculators.saponification.fields.water_method')" :hint="t('calculators.saponification.hints.water')">
                <SegmentedControl v-model="state.water.method" :options="methodOptions" :aria-label="t('calculators.saponification.fields.water_method')" />
            </FormField>
            <DecimalField v-model="state.water.value" label-key="calculators.saponification.fields.water_value" :suffix="waterSuffix" />
        </div>

        <div class="flex flex-col gap-3">
            <h3 class="type-h6 text-ink">{{ t('calculators.saponification.oils_title') }}</h3>
            <LineList :lines="state.lines" add-label-key="calculators.saponification.add_oil" remove-label-key="calculators.saponification.remove_oil" @add="addOil" @remove="removeOil">
                <template #line="{ line, index }">
                    <div class="grid gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                        <MaterialPicker v-model="line.material" label-key="calculators.saponification.fields.oil_n" :label-params="{ n: index + 1 }" hint-key="calculators.saponification.hints.oil" @picked="(option) => applyMaterialToOil(line, option)" />
                        <QuantityInput v-model="line.mass" label-key="calculators.saponification.fields.oil_mass_n" :label-params="{ n: index + 1 }" :units="['g', 'kg']" />
                    </div>
                    <SourcedInput v-model="line.sap" label-key="calculators.saponification.fields.sap_n" :label-params="{ n: index + 1 }" hint-key="calculators.saponification.hints.sap" :units="SAP_UNITS" />
                    <div v-if="line.materialRange" class="text-label text-ink-3" data-testid="material-range">{{ t('calculators.saponification.range_from_material') }}</div>
                    <div v-else class="grid gap-3 sm:grid-cols-2">
                        <DecimalField v-model="line.rangeMin" label-key="calculators.saponification.fields.sap_min_n" :label-params="{ n: index + 1 }" hint-key="calculators.saponification.hints.sap_range" />
                        <DecimalField v-model="line.rangeMax" label-key="calculators.saponification.fields.sap_max_n" :label-params="{ n: index + 1 }" />
                    </div>
                </template>
            </LineList>
        </div>
    </CalculatorLayout>
</template>
