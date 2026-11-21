<script setup>
/**
 * The calculators' material selector: search the library, and the whole option (name plus its registered property rows)
 * becomes the model, so a page can prefill sourced inputs from the material (fillFromMaterial, the engine's resolveValue).
 * Clearing the box clears the model. Material is optional everywhere it is used: a figure can always be typed with its source.
 *
 *   <MaterialPicker v-model="line.material" label-key="calculators.fields.material_n" :label-params="{ n: 1 }" bare @picked="fill" />
 */
import { computed } from 'vue';
import { useCalculatorLookups } from '../../composables/useCalculatorLookups';
import EntitySelect from '../form/EntitySelect.vue';

defineProps({
    labelKey: { type: String, default: 'calculators.fields.material' },
    labelParams: { type: Object, default: undefined },
    hintKey: { type: String, default: null },
    bare: { type: Boolean, default: false },
});

const model = defineModel({ type: Object, default: null });
const emit = defineEmits(['picked']);
const { urls } = useCalculatorLookups();
const selectedId = computed(() => model.value?.value ?? null);

function picked(option) {
    model.value = option;
    emit('picked', option);
}
</script>

<template>
    <EntitySelect
        :model-value="selectedId"
        :label-key="labelKey"
        :label-params="labelParams"
        :hint-key="hintKey"
        placeholder-key="calculators.fields.material_placeholder"
        :search-url="urls.materials"
        :selected-label="model?.label ?? ''"
        :bare="bare"
        @update:model-value="(id) => { if (id === null) model = null; }"
        @select="picked"
    />
</template>
