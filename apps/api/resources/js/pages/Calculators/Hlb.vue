<script setup>
/**
 * HLB (F-08, M-03, M-08), seven ways in one page: the required HLB of an oil phase, the split of two non-ionic emulsifiers for a target HLB,
 * both in one step (the oil phase decides the target), the two ionic paths without HLB (a cationic emulsifier against its TDS range,
 * an anionic surfactant from its active matter), and Griffin's and Davies' HLB of a molecule. Every rHLB, emulsifier HLB and Davies group number is a sourced input: the engine
 * embeds no table. The values are the engine's; this page only wraps each in a trace for the explain panel. A required HLB
 * the lab already tested on a formula carries a "tested on" tag (O-07), a record only: it changes nothing in the sum.
 */
import { computed, reactive } from 'vue';
import { FormField, Input, SegmentedControl, Select } from '@kit';
import CalculatorLayout from '../../components/calculators/CalculatorLayout.vue';
import LineList from '../../components/calculators/LineList.vue';
import MaterialPicker from '../../components/calculators/MaterialPicker.vue';
import DecimalField from '../../components/form/DecimalField.vue';
import QuantityInput from '../../components/form/QuantityInput.vue';
import SourcedInput from '../../components/form/SourcedInput.vue';
import TestedOnBadge from '../../components/lab/TestedOnBadge.vue';
import { useI18n } from '../../composables/useI18n';
import { IONICITIES, MODES, blankGroup, blankHlb, blankOilLine, runHlb } from '../../lib/calculators/hlb';
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
const outcome = computed(() => runHlb(state, fallbackName, { cationic: t('calculators.hlb.fields.cationic_material'), anionic: t('calculators.hlb.fields.anionic_material') }));

const hrefOf = (id) => path('materials.show', { material: id, tab: 'properties' });
const entities = computed(() => {
    const picked = [
        ...state.required.lines.map((line) => line.material),
        state.blend.lowMaterial,
        state.blend.highMaterial,
        state.cationic.material,
        state.anionic.material,
    ].filter(Boolean);

    return Object.fromEntries(picked.map((material) => [material.label, { href: hrefOf(material.value) }]));
});
const sources = computed(() => sourcesOf([
    ...state.required.lines.map((line) => line.hlb),
    state.blend.low,
    state.blend.high,
    state.cationic.min,
    state.cationic.max,
    state.anionic.active,
    ...state.davies.groups.map((group) => group.h),
], hrefOf));

const modeOptions = computed(() => MODES.map((mode) => ({ value: mode, label: t(`calculators.hlb.modes.${mode}`) })));
const ionicityOptions = computed(() => IONICITIES.map((value) => ({ value, label: t(`enums.ionicity.${value}`) })));
const withOil = computed(() => state.mode === 'required' || state.mode === 'system');
const withBlend = computed(() => state.mode === 'blend' || state.mode === 'system');
const addOil = () => state.required.lines.push(blankOilLine(nextKey()));
const removeOil = (key) => { state.required.lines = state.required.lines.filter((line) => line.key !== key); };
const addGroup = () => state.davies.groups.push(blankGroup(nextKey()));
const removeGroup = (key) => { state.davies.groups = state.davies.groups.filter((group) => group.key !== key); };
const pickRequired = (field, option) => fillFromMaterial(field, option, 'required_hlb');
// A material that states its ionic character brings it along, so an ionic emulsifier is stopped before it is blended by HLB (M-08).
const pickHlb = (field, option, ionicityKey) => {
    if (option.ionicity) {
        state.blend[ionicityKey] = option.ionicity;
    }

    return fillFromMaterial(field, option, 'hlb');
};
const pickActive = (option) => fillFromMaterial(state.anionic.active, option, 'active_matter');
</script>

<template>
    <CalculatorLayout v-bind="$props" :outcome="outcome" :entities="entities" :sources="sources">
        <div class="overflow-x-auto"><SegmentedControl v-model="state.mode" :options="modeOptions" :aria-label="t('calculators.hlb.mode')" block /></div>

        <template v-if="withOil">
            <p class="text-label text-ink-3">{{ t(state.mode === 'system' ? 'calculators.hlb.hints.system_oil' : 'calculators.hlb.hints.required') }}</p>
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

        <template v-if="withBlend">
            <p class="text-label text-ink-3">{{ t(state.mode === 'system' ? 'calculators.hlb.hints.system_blend' : 'calculators.hlb.hints.blend') }}</p>
            <DecimalField v-if="state.mode === 'blend'" v-model="state.blend.target" label-key="calculators.hlb.fields.target" />
            <MaterialPicker v-model="state.blend.lowMaterial" label-key="calculators.hlb.fields.low_material" @picked="(option) => pickHlb(state.blend.low, option, 'lowIonicity')" />
            <SourcedInput v-model="state.blend.low" label-key="calculators.hlb.fields.low_hlb" unit-key="calculators.units.HLB" />
            <FormField :label="t('calculators.hlb.fields.low_ionicity')" :hint="t('calculators.hlb.hints.ionicity')">
                <Select v-model="state.blend.lowIonicity" :options="ionicityOptions" data-testid="low-ionicity" />
            </FormField>
            <MaterialPicker v-model="state.blend.highMaterial" label-key="calculators.hlb.fields.high_material" @picked="(option) => pickHlb(state.blend.high, option, 'highIonicity')" />
            <SourcedInput v-model="state.blend.high" label-key="calculators.hlb.fields.high_hlb" unit-key="calculators.units.HLB" />
            <FormField :label="t('calculators.hlb.fields.high_ionicity')" :hint="t('calculators.hlb.hints.ionicity')">
                <Select v-model="state.blend.highIonicity" :options="ionicityOptions" data-testid="high-ionicity" />
            </FormField>
            <QuantityInput v-model="state.blend.total" label-key="calculators.hlb.fields.total" :units="['g', 'kg']" />
        </template>

        <template v-if="state.mode === 'cationic'">
            <p class="text-label text-ink-3">{{ t('calculators.hlb.hints.cationic') }}</p>
            <MaterialPicker v-model="state.cationic.material" label-key="calculators.hlb.fields.cationic_material" />
            <DecimalField v-model="state.cationic.dose" label-key="calculators.hlb.fields.cationic_dose" hint-key="calculators.hlb.hints.dose" suffix="%" />
            <SourcedInput v-model="state.cationic.min" label-key="calculators.hlb.fields.tds_min" unit-key="calculators.units.%w/w" />
            <SourcedInput v-model="state.cationic.max" label-key="calculators.hlb.fields.tds_max" unit-key="calculators.units.%w/w" />
        </template>

        <template v-else-if="state.mode === 'anionic'">
            <p class="text-label text-ink-3">{{ t('calculators.hlb.hints.anionic') }}</p>
            <MaterialPicker v-model="state.anionic.material" label-key="calculators.hlb.fields.anionic_material" @picked="pickActive" />
            <DecimalField v-model="state.anionic.mass" label-key="calculators.hlb.fields.anionic_mass" hint-key="calculators.hlb.hints.mass" suffix="g" />
            <SourcedInput v-model="state.anionic.active" label-key="calculators.hlb.fields.anionic_active" unit-key="calculators.units.%w/w" />
            <DecimalField v-model="state.anionic.total" label-key="calculators.hlb.fields.anionic_total" hint-key="calculators.hlb.hints.total" suffix="g" />
        </template>

        <template v-else-if="state.mode === 'griffin'">
            <p class="text-label text-ink-3">{{ t('calculators.hlb.hints.griffin') }}</p>
            <DecimalField v-model="state.griffin.hydrophilic" label-key="calculators.hlb.fields.hydrophilic" hint-key="calculators.hlb.hints.griffin_unit" />
            <DecimalField v-model="state.griffin.total" label-key="calculators.hlb.fields.molar_total" />
        </template>

        <template v-else-if="state.mode === 'davies'">
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
