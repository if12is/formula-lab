<script setup>
/**
 * Dilution and active matter (F-04), three ways in one page: the active % of a blend of grades, the raw grade and
 * diluent to weigh for a target active %, and the water to add to a batch you already have. The active % of every grade
 * is a sourced figure (a material's own is prefilled through resolveValue). All arithmetic is the engine's.
 */
import { computed, reactive } from 'vue';
import { SegmentedControl, Switch } from '@kit';
import CalculatorLayout from '../../components/calculators/CalculatorLayout.vue';
import LineList from '../../components/calculators/LineList.vue';
import MaterialPicker from '../../components/calculators/MaterialPicker.vue';
import DecimalField from '../../components/form/DecimalField.vue';
import QuantityInput from '../../components/form/QuantityInput.vue';
import SourcedInput from '../../components/form/SourcedInput.vue';
import { useI18n } from '../../composables/useI18n';
import { MODES, blankBlendLine, blankDilution, lineNames, runDilution } from '../../lib/calculators/dilution';
import { fillFromMaterial, sourcesOf } from '../../lib/calculators/sourced';
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
const state = reactive(blankDilution(nextKey()));

const fallbackName = (n) => t('calculators.dilution.component_n', { n });
const outcome = computed(() => runDilution(state, fallbackName));

const hrefOf = (id) => path('materials.show', { material: id, tab: 'properties' });
const entities = computed(() => {
    const names = lineNames(state.blend.lines, fallbackName);
    const blend = state.blend.lines.flatMap((line, index) => (line.material ? [[names[index], { href: hrefOf(line.material.value) }]] : []));
    const raw = state.target.rawMaterial ? [[state.target.rawMaterial.label, { href: hrefOf(state.target.rawMaterial.value) }]] : [];

    return Object.fromEntries([...blend, ...raw]);
});
const sources = computed(() => sourcesOf([...state.blend.lines.map((line) => line.active), state.target.raw, state.water.current], hrefOf));

const modeOptions = computed(() => MODES.map((mode) => ({ value: mode, label: t(`calculators.dilution.modes.${mode}`) })));
const addLine = () => state.blend.lines.push(blankBlendLine(nextKey()));
const removeLine = (key) => { state.blend.lines = state.blend.lines.filter((line) => line.key !== key); };
const pickActive = (field, option) => fillFromMaterial(field, option, 'active_matter');
</script>

<template>
    <CalculatorLayout v-bind="$props" :outcome="outcome" :entities="entities" :sources="sources">
        <SegmentedControl v-model="state.mode" :options="modeOptions" :aria-label="t('calculators.dilution.mode')" block />

        <template v-if="state.mode === 'blend'">
            <p class="text-label text-ink-3">{{ t('calculators.dilution.hints.blend') }}</p>
            <LineList :lines="state.blend.lines" add-label-key="calculators.dilution.add_component" remove-label-key="calculators.dilution.remove_component" @add="addLine" @remove="removeLine">
                <template #line="{ line, index }">
                    <div class="grid gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                        <MaterialPicker v-model="line.material" label-key="calculators.dilution.fields.component_n" :label-params="{ n: index + 1 }" @picked="(option) => pickActive(line.active, option)" />
                        <QuantityInput v-model="line.mass" label-key="calculators.dilution.fields.component_mass_n" :label-params="{ n: index + 1 }" :units="['g', 'kg']" />
                    </div>
                    <Switch v-model="line.contributes" :label="t('calculators.dilution.fields.contributes_n', { n: index + 1 })" :description="line.contributes ? undefined : t('calculators.dilution.hints.inactive')" />
                    <SourcedInput v-model="line.active" label-key="calculators.dilution.fields.active_n" :label-params="{ n: index + 1 }" hint-key="calculators.dilution.hints.active" :units="['%w/w']" />
                </template>
            </LineList>
        </template>

        <template v-else-if="state.mode === 'target'">
            <p class="text-label text-ink-3">{{ t('calculators.dilution.hints.target') }}</p>
            <MaterialPicker v-model="state.target.rawMaterial" label-key="calculators.dilution.fields.raw_material" @picked="(option) => pickActive(state.target.raw, option)" />
            <SourcedInput v-model="state.target.raw" label-key="calculators.dilution.fields.raw_active" hint-key="calculators.dilution.hints.active" :units="['%w/w']" />
            <DecimalField v-model="state.target.targetPct" label-key="calculators.dilution.fields.target_active" suffix="%" />
            <QuantityInput v-model="state.target.batch" label-key="calculators.dilution.fields.batch_mass" :units="['g', 'kg']" />
        </template>

        <template v-else>
            <p class="text-label text-ink-3">{{ t('calculators.dilution.hints.water') }}</p>
            <QuantityInput v-model="state.water.existing" label-key="calculators.dilution.fields.existing_mass" :units="['g', 'kg']" />
            <SourcedInput v-model="state.water.current" label-key="calculators.dilution.fields.current_active" hint-key="calculators.dilution.hints.current" :units="['%w/w']" />
            <DecimalField v-model="state.water.targetPct" label-key="calculators.dilution.fields.target_active" suffix="%" />
        </template>
    </CalculatorLayout>
</template>
