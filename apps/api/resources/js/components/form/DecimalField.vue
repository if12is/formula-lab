<script setup>
/**
 * One field for a decimal typed by a person (a percentage, a mass, a target): left-to-right, a numeric keyboard on
 * a phone, an optional suffix ("%", "g") and the usual label, hint and error. The text stays text: Arabic-Indic
 * digits are turned into plain ones as they are typed (normalizeDecimalText) and the browser never parses it.
 *
 *   <DecimalField v-model="state.superfat" label-key="calculators.soap.fields.superfat" suffix="%" :error="..." />
 *
 * `bare` drops the FormField for a control inside a repeated line (the label then names the control for screen readers).
 */
import { FormField, Input } from '@kit';
import { useI18n } from '../../composables/useI18n';
import { normalizeDecimalText } from '../../lib/engine';

defineProps({
    labelKey: { type: String, required: true },
    labelParams: { type: Object, default: undefined },
    hintKey: { type: String, default: null },
    error: { type: String, default: null },
    suffix: { type: String, default: null },
    required: { type: Boolean, default: false },
    bare: { type: Boolean, default: false },
    placeholderKey: { type: String, default: null },
});

const model = defineModel({ type: String, default: '' });
const { t } = useI18n();
</script>

<template>
    <component
        :is="bare ? 'div' : FormField"
        v-bind="bare ? {} : { label: t(labelKey, labelParams), hint: hintKey ? t(hintKey) : undefined, error: error ?? undefined, required }"
    >
        <Input
            :model-value="model"
            ltr
            inputmode="decimal"
            :suffix="suffix ?? undefined"
            :placeholder="placeholderKey ? t(placeholderKey) : undefined"
            :aria-label="bare ? t(labelKey, labelParams) : undefined"
            :invalid="Boolean(error)"
            @update:model-value="(value) => { model = normalizeDecimalText(value); }"
        />
    </component>
</template>
