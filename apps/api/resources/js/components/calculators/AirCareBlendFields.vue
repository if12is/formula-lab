<script setup>
/**
 * Reed diffuser, fragrance oil, gel and car air fresheners (L-07, L-08): oil %, other additives, the carrier as the
 * remainder, a gel agent dose from its TDS or a trial (only for a gel), and an optional IFRA limit. The model is
 * state.blend of lib/calculators/fragrance.js; its fields are edited in place.
 */
import { computed } from 'vue';
import { FormField, Select } from '@kit';
import DecimalField from '../form/DecimalField.vue';
import QuantityInput from '../form/QuantityInput.vue';
import SourcedInput from '../form/SourcedInput.vue';
import { useI18n } from '../../composables/useI18n';
import { BLEND_TYPES } from '../../lib/calculators/fragrance';

const blend = defineModel({ type: Object, required: true });
const { t } = useI18n();

const typeOptions = computed(() => Object.keys(BLEND_TYPES).map((type) => ({ value: type, label: t(`calculators.fragrance.blend.types.${type}`) })));
const hasDose = computed(() => BLEND_TYPES[blend.value.type].dose !== null);
</script>

<template>
    <div class="flex flex-col gap-4">
        <p class="text-label text-ink-3">{{ t('calculators.fragrance.hints.blend') }}</p>
        <FormField :label="t('calculators.fragrance.blend.type')" :hint="t('calculators.fragrance.blend.type_hint')">
            <Select v-model="blend.type" :options="typeOptions" />
        </FormField>
        <div class="grid gap-3 sm:grid-cols-2">
            <DecimalField v-model="blend.oilPct" label-key="calculators.fragrance.fields.oil_pct" hint-key="calculators.fragrance.hints.oil_pct" suffix="%" />
            <DecimalField v-model="blend.otherPct" label-key="calculators.fragrance.blend.other_pct" hint-key="calculators.fragrance.blend.other_pct_hint" suffix="%" />
        </div>
        <SourcedInput v-if="hasDose" v-model="blend.dose" label-key="calculators.fragrance.blend.gel_agent" hint-key="calculators.fragrance.blend.gel_agent_hint" :units="['%w/w']" />
        <QuantityInput v-model="blend.batch" label-key="calculators.fragrance.fields.batch" :units="['g', 'kg']" />
        <SourcedInput v-model="blend.ifra" label-key="calculators.fragrance.fields.ifra_limit" hint-key="calculators.fragrance.hints.ifra_limit" :units="['%w/w']" />
    </div>
</template>
