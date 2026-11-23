<script setup>
/**
 * Candle and wax melt (L-07, IFRA 12): the fragrance load as a % of the wax weight, the wax mass, the wax's maximum load
 * from its TDS (no built-in limit) and an optional IFRA limit. The model is state.candle of lib/calculators/fragrance.js;
 * its fields are edited in place.
 */
import DecimalField from '../form/DecimalField.vue';
import QuantityInput from '../form/QuantityInput.vue';
import SourcedInput from '../form/SourcedInput.vue';
import { useI18n } from '../../composables/useI18n';

const candle = defineModel({ type: Object, required: true });
const { t } = useI18n();
</script>

<template>
    <div class="flex flex-col gap-4">
        <p class="text-label text-ink-3">{{ t('calculators.fragrance.hints.candle') }}</p>
        <DecimalField v-model="candle.loadPct" label-key="calculators.fragrance.candle.load" hint-key="calculators.fragrance.candle.load_hint" suffix="%" />
        <QuantityInput v-model="candle.wax" label-key="calculators.fragrance.candle.wax" :units="['g', 'kg']" />
        <SourcedInput v-model="candle.maxLoad" label-key="calculators.fragrance.candle.max_load" hint-key="calculators.fragrance.candle.max_load_hint" :units="['%w/w']" />
        <SourcedInput v-model="candle.ifra" label-key="calculators.fragrance.fields.ifra_limit" hint-key="calculators.fragrance.candle.ifra_hint" :units="['%w/w']" />
    </div>
</template>
