<script setup>
/**
 * An amount with its unit: a decimal typed as text and a Select of the units this field allows (g / kg, mL / L...).
 * The model is { value, unit }; the engine takes it through toQty(), which keeps every unit conversion in the engine.
 *
 *   <QuantityInput v-model="state.batch" label-key="calculators.dilution.fields.batch" :units="['g', 'kg']" />
 *
 * `bare` drops the FormField for a control inside a repeated line.
 */
import { FormField, Input, Select } from '@kit';
import { computed } from 'vue';
import { useI18n } from '../../composables/useI18n';
import { normalizeDecimalText } from '../../lib/engine';

const props = defineProps({
    labelKey: { type: String, required: true },
    labelParams: { type: Object, default: undefined },
    hintKey: { type: String, default: null },
    error: { type: String, default: null },
    units: { type: Array, required: true },
    required: { type: Boolean, default: false },
    bare: { type: Boolean, default: false },
});

const model = defineModel({ type: Object, required: true });
const { t } = useI18n();
const unitOptions = computed(() => props.units.map((unit) => ({ value: unit, label: t(`calculators.units.${unit}`) })));
</script>

<template>
    <component
        :is="bare ? 'div' : FormField"
        v-bind="bare ? {} : { label: t(labelKey, labelParams), hint: hintKey ? t(hintKey) : undefined, error: error ?? undefined, required }"
    >
        <Input
            :model-value="model.value"
            ltr
            inputmode="decimal"
            :aria-label="bare ? t(labelKey, labelParams) : undefined"
            :invalid="Boolean(error)"
            @update:model-value="(value) => { model.value = normalizeDecimalText(value); }"
        >
            <template #suffix>
                <Select v-model="model.unit" :options="unitOptions" :aria-label="t('calculators.fields.unit_of', { name: t(labelKey, labelParams) })" />
            </template>
        </Input>
    </component>
</template>
