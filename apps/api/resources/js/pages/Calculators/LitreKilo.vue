<script setup>
/**
 * Litre <-> kilo: the mass of a volume or the volume of a mass, through the density you measured. There is no default
 * density: without one the engine reports it missing instead of guessing. A material's own density is prefilled through
 * resolveValue when it has one. Pick a formula version and the density the lab measured for it is used in preference to the
 * estimated one (the engine's preferMeasuredDensity), with its trial as the source (O-04).
 */
import { computed, reactive } from 'vue';
import { Alert, FormField, SegmentedControl, Select } from '@kit';
import CalculatorLayout from '../../components/calculators/CalculatorLayout.vue';
import MaterialPicker from '../../components/calculators/MaterialPicker.vue';
import EntitySelect from '../../components/form/EntitySelect.vue';
import QuantityInput from '../../components/form/QuantityInput.vue';
import SourcedInput from '../../components/form/SourcedInput.vue';
import { useI18n } from '../../composables/useI18n';
import { DIRECTIONS, UNITS, blankLitreKilo, runLitreKilo } from '../../lib/calculators/litreKilo';
import { fillFromMaterial, sourcesOf } from '../../lib/calculators/sourced';
import { path } from '../../lib/routes';

const props = defineProps({
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
const sources = computed(() => ({
    ...sourcesOf([state.density], hrefOf),
    ...(state.measured ? { [state.measured.sourceId]: { title: state.measured.sourceTitle, href: state.measured.href } } : {}),
}));

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

// A formula version brings the density the lab measured for it (or the news that it has none).
async function pickVersion(option) {
    state.version = option;
    state.measured = null;

    const response = await fetch(path('lab.formula-versions.density', { version: option.value }), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });

    if (response.ok && state.version?.value === option.value) {
        state.measured = (await response.json()).density;
    }
}

function clearVersion(id) {
    if (id === null) {
        state.version = null;
        state.measured = null;
    }
}
</script>

<template>
    <CalculatorLayout v-bind="$props" :outcome="outcome" :entities="entities" :sources="sources">
        <FormField :label="t('calculators.litre-kilo.fields.direction')">
            <SegmentedControl :model-value="state.direction" :options="directionOptions" :aria-label="t('calculators.litre-kilo.fields.direction')" @update:model-value="changeDirection" />
        </FormField>
        <EntitySelect :model-value="state.version?.value ?? null" label-key="calculators.litre-kilo.fields.version" hint-key="calculators.litre-kilo.hints.version" placeholder-key="lab.form.version_placeholder" :search-url="props.urls.formulaVersions" :selected-label="state.version?.label ?? ''" @update:model-value="clearVersion" @select="pickVersion" />
        <Alert v-if="state.version && state.measured" tone="success" icon="ph:flask" data-testid="lab-density-used">
            {{ t('calculators.litre-kilo.hints.lab_density_used') }}
            <bdi dir="ltr" class="tnum font-semibold">{{ state.measured.value }} {{ state.measured.unit }}</bdi>
            · <a :href="state.measured.href" class="underline underline-offset-2">{{ t('lab.density.measured_in') }} <bdi dir="ltr">{{ state.measured.trial }}</bdi></a>
        </Alert>
        <Alert v-else-if="state.version" tone="warning" icon="ph:hourglass" data-testid="lab-density-none">{{ t('calculators.litre-kilo.hints.lab_density_none') }}</Alert>
        <MaterialPicker v-model="state.material" label-key="calculators.litre-kilo.fields.material" hint-key="calculators.litre-kilo.hints.material" @picked="pickDensity" />
        <QuantityInput v-model="state.amount" :label-key="toVolume ? 'calculators.litre-kilo.fields.mass' : 'calculators.litre-kilo.fields.volume'" :units="amountUnits" />
        <SourcedInput v-model="state.density" label-key="calculators.litre-kilo.fields.density" hint-key="calculators.litre-kilo.hints.density" :units="['g/mL', 'kg/L']" />
        <FormField :label="t('calculators.litre-kilo.fields.result_unit')">
            <Select v-model="state.resultUnit" :options="resultUnitOptions" />
        </FormField>
    </CalculatorLayout>
</template>
