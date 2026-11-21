<script setup>
/**
 * Sulfonic acid neutralisation (F-05): the base to weigh for an acid of a given acid value (from the lot's COA), as a solid
 * of its purity or a solution of its concentration, with the water the solution carries. The answer is a theoretical
 * starting point; the final pH is measured, and a warning that says so is always on screen.
 */
import { computed, reactive } from 'vue';
import { Alert, FormField, SegmentedControl } from '@kit';
import CalculatorLayout from '../../components/calculators/CalculatorLayout.vue';
import MaterialPicker from '../../components/calculators/MaterialPicker.vue';
import QuantityInput from '../../components/form/QuantityInput.vue';
import SourcedInput from '../../components/form/SourcedInput.vue';
import { useI18n } from '../../composables/useI18n';
import { BASES, FORMS, blankNeutralize, runNeutralize } from '../../lib/calculators/neutralize';
import { blankSourced, fillFromMaterial, sourcesOf } from '../../lib/calculators/sourced';
import { path } from '../../lib/routes';

defineProps({
    calculator: { type: String, required: true },
    urls: { type: Object, required: true },
    confidenceOptions: { type: Array, required: true },
    sourceKindOptions: { type: Array, required: true },
});

const { t } = useI18n();
const state = reactive(blankNeutralize());
const outcome = computed(() => runNeutralize(state));

const hrefOf = (id) => path('materials.show', { material: id, tab: 'properties' });
const entities = computed(() => (state.acidMaterial ? { [state.acidMaterial.label]: { href: hrefOf(state.acidMaterial.value) } } : {}));
const sources = computed(() => sourcesOf([state.acidValue, state.strength], hrefOf));

const baseOptions = BASES.map((base) => ({ value: base, label: base }));
const formOptions = computed(() => FORMS.map((form) => ({ value: form, label: t(`calculators.neutralization.forms.${form}`) })));
const strengthKey = computed(() => `calculators.neutralization.fields.strength_${state.form}`);

// A purity (solid) and a concentration (solution) are different figures: switching starts the strength afresh.
function changeForm(form) {
    state.form = form;
    state.strength = blankSourced('%w/w');
}

const pickAcid = (option) => fillFromMaterial(state.acidValue, option, 'acid_value');
</script>

<template>
    <CalculatorLayout v-bind="$props" :outcome="outcome" :entities="entities" :sources="sources">
        <MaterialPicker v-model="state.acidMaterial" label-key="calculators.neutralization.fields.acid_material" hint-key="calculators.neutralization.hints.acid_material" @picked="pickAcid" />
        <QuantityInput v-model="state.acidMass" label-key="calculators.neutralization.fields.acid_mass" :units="['g', 'kg']" />
        <SourcedInput v-model="state.acidValue" label-key="calculators.neutralization.fields.acid_value" hint-key="calculators.neutralization.hints.acid_value" :units="['mgKOH/g']" />

        <FormField :label="t('calculators.neutralization.fields.base')">
            <SegmentedControl v-model="state.base" :options="baseOptions" :aria-label="t('calculators.neutralization.fields.base')" />
        </FormField>
        <FormField :label="t('calculators.neutralization.fields.form')">
            <SegmentedControl :model-value="state.form" :options="formOptions" :aria-label="t('calculators.neutralization.fields.form')" @update:model-value="changeForm" />
        </FormField>
        <SourcedInput v-model="state.strength" :label-key="strengthKey" hint-key="calculators.neutralization.hints.strength" :units="['%w/w']" />

        <template #notes>
            <Alert tone="warning" icon="ph:warning" :title="t('calculators.neutralization.ph_title')" data-testid="ph-warning">
                {{ t('calculators.neutralization.ph_warning') }}
            </Alert>
        </template>
    </CalculatorLayout>
</template>
