<script setup>
/**
 * HLB (F-08), four ways in one page: the required HLB of an oil phase, the split of two emulsifiers for a target HLB, and
 * Griffin's and Davies' HLB of a molecule. Every rHLB, emulsifier HLB and Davies group number is a sourced input: the engine
 * embeds no table. The values are the engine's; this page only wraps each in a trace for the explain panel. A required HLB
 * the lab already tested on a formula carries a "tested on" tag (O-07), a record only: it changes nothing in the sum.
 */
import { computed, reactive } from 'vue';
import { FormField, Input, SegmentedControl } from '@kit';
import CalculatorLayout from '../../components/calculators/CalculatorLayout.vue';
import LineList from '../../components/calculators/LineList.vue';
import MaterialPicker from '../../components/calculators/MaterialPicker.vue';
import DecimalField from '../../components/form/DecimalField.vue';
import QuantityInput from '../../components/form/QuantityInput.vue';
import SourcedInput from '../../components/form/SourcedInput.vue';
import TestedOnBadge from '../../components/lab/TestedOnBadge.vue';
import { useI18n } from '../../composables/useI18n';
import { MODES, blankGroup, blankHlb, blankOilLine, runHlb } from '../../lib/calculators/hlb';
import { fillFromMaterial, sourcesOf } from '../../lib/calculators/sourced';
import { tagsFor } from '../../lib/lab/testedOn';
import { path } from '../../lib/routes';

defineProps({
    calculator: { type: String, required: true },
    urls: { type: Object, required: true },
    confidenceOptions: { type: Array, required: true },
    sourceKindOptions: { type: Array, required: true },
});

const { t } = useI18n();
let sequence = 0;
const nextKey = () => `line-${(sequence += 1)}`;
const state = reactive(blankHlb(nextKey()));

const fallbackName = (n) => t('calculators.hlb.oil_n', { n });
const outcome = computed(() => runHlb(state, fallbackName));

const hrefOf = (id) => path('materials.show', { material: id, tab: 'properties' });
const entities = computed(() => {
    const picked = [
        ...state.required.lines.map((line) => line.material),
        state.blend.lowMaterial,
        state.blend.highMaterial,
    ].filter(Boolean);

    return Object.fromEntries(picked.map((material) => [material.label, { href: hrefOf(material.value) }]));
});
const sources = computed(() => sourcesOf([
    ...state.required.lines.map((line) => line.hlb),
    state.blend.low,
    state.blend.high,
    ...state.davies.groups.map((group) => group.h),
], hrefOf));

const modeOptions = computed(() => MODES.map((mode) => ({ value: mode, label: t(`calculators.hlb.modes.${mode}`) })));
const addOil = () => state.required.lines.push(blankOilLine(nextKey()));
const removeOil = (key) => { state.required.lines = state.required.lines.filter((line) => line.key !== key); };
const addGroup = () => state.davies.groups.push(blankGroup(nextKey()));
const removeGroup = (key) => { state.davies.groups = state.davies.groups.filter((group) => group.key !== key); };
const pickRequired = (field, option) => fillFromMaterial(field, option, 'required_hlb');
const pickHlb = (field, option) => fillFromMaterial(field, option, 'hlb');
</script>

<template>
    <CalculatorLayout v-bind="$props" :outcome="outcome" :entities="entities" :sources="sources">
        <SegmentedControl v-model="state.mode" :options="modeOptions" :aria-label="t('calculators.hlb.mode')" block />

        <template v-if="state.mode === 'required'">
            <p class="text-label text-ink-3">{{ t('calculators.hlb.hints.required') }}</p>
            <LineList :lines="state.required.lines" add-label-key="calculators.hlb.add_oil" remove-label-key="calculators.hlb.remove_oil" @add="addOil" @remove="removeOil">
                <template #line="{ line, index }">
                    <div class="grid gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                        <MaterialPicker v-model="line.material" label-key="calculators.hlb.fields.oil_n" :label-params="{ n: index + 1 }" @picked="(option) => pickRequired(line.hlb, option)" />
                        <DecimalField v-model="line.mass" label-key="calculators.hlb.fields.oil_mass_n" :label-params="{ n: index + 1 }" hint-key="calculators.hlb.hints.mass" suffix="g" />
                    </div>
                    <SourcedInput v-model="line.hlb" label-key="calculators.hlb.fields.required_hlb_n" :label-params="{ n: index + 1 }" hint-key="calculators.hlb.hints.required_hlb" unit-key="calculators.units.HLB" />
                    <TestedOnBadge :tags="tagsFor(line.material, 'required_hlb', line.hlb.value)" />
                </template>
            </LineList>
        </template>

        <template v-else-if="state.mode === 'blend'">
            <p class="text-label text-ink-3">{{ t('calculators.hlb.hints.blend') }}</p>
            <DecimalField v-model="state.blend.target" label-key="calculators.hlb.fields.target" />
            <MaterialPicker v-model="state.blend.lowMaterial" label-key="calculators.hlb.fields.low_material" @picked="(option) => pickHlb(state.blend.low, option)" />
            <SourcedInput v-model="state.blend.low" label-key="calculators.hlb.fields.low_hlb" unit-key="calculators.units.HLB" />
            <MaterialPicker v-model="state.blend.highMaterial" label-key="calculators.hlb.fields.high_material" @picked="(option) => pickHlb(state.blend.high, option)" />
            <SourcedInput v-model="state.blend.high" label-key="calculators.hlb.fields.high_hlb" unit-key="calculators.units.HLB" />
            <QuantityInput v-model="state.blend.total" label-key="calculators.hlb.fields.total" :units="['g', 'kg']" />
        </template>

        <template v-else-if="state.mode === 'griffin'">
            <p class="text-label text-ink-3">{{ t('calculators.hlb.hints.griffin') }}</p>
            <DecimalField v-model="state.griffin.hydrophilic" label-key="calculators.hlb.fields.hydrophilic" hint-key="calculators.hlb.hints.griffin_unit" />
            <DecimalField v-model="state.griffin.total" label-key="calculators.hlb.fields.molar_total" />
        </template>

        <template v-else>
            <p class="text-label text-ink-3">{{ t('calculators.hlb.hints.davies') }}</p>
            <LineList :lines="state.davies.groups" add-label-key="calculators.hlb.add_group" remove-label-key="calculators.hlb.remove_group" @add="addGroup" @remove="removeGroup">
                <template #line="{ line, index }">
                    <div class="grid gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                        <FormField :label="t('calculators.hlb.fields.group_n', { n: index + 1 })" :hint="t('calculators.hlb.hints.group_name')">
                            <Input v-model="line.group" maxlength="100" />
                        </FormField>
                        <DecimalField v-model="line.count" label-key="calculators.hlb.fields.group_count_n" :label-params="{ n: index + 1 }" />
                    </div>
                    <SourcedInput v-model="line.h" label-key="calculators.hlb.fields.group_number_n" :label-params="{ n: index + 1 }" hint-key="calculators.hlb.hints.group_number" unit-key="calculators.units.HLB" />
                </template>
            </LineList>
        </template>
    </CalculatorLayout>
</template>
