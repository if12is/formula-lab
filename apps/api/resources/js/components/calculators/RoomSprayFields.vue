<script setup>
/**
 * Water-based room spray (L-05): oil %, the solubilizer to fragrance ratio from the solubilizer's TDS or a documented trial
 * (there is no built-in ratio), the clarity measured on a trial, and an optional IFRA limit. The model is state.room of
 * lib/calculators/fragrance.js; its fields are edited in place.
 */
import { computed } from 'vue';
import { FormField, SegmentedControl, Select } from '@kit';
import DecimalField from '../form/DecimalField.vue';
import QuantityInput from '../form/QuantityInput.vue';
import SourcedInput from '../form/SourcedInput.vue';
import { useI18n } from '../../composables/useI18n';
import { CLARITY_OBSERVATIONS, RATIO_BASES } from '../../lib/calculators/fragrance';

const room = defineModel({ type: Object, required: true });
const { t } = useI18n();

const basisOptions = computed(() => RATIO_BASES.map((basis) => ({ value: basis, label: t(`calculators.fragrance.room.basis.${basis}`) })));
const observationOptions = computed(() => CLARITY_OBSERVATIONS.map((observation) => ({ value: observation, label: t(`calculators.fragrance.room.observation.${observation}`) })));
</script>

<template>
    <div class="flex flex-col gap-4">
        <p class="text-label text-ink-3">{{ t('calculators.fragrance.hints.room_spray') }}</p>
        <div class="grid gap-3 sm:grid-cols-2">
            <DecimalField v-model="room.oilPct" label-key="calculators.fragrance.fields.oil_pct" hint-key="calculators.fragrance.hints.oil_pct" suffix="%" />
            <DecimalField v-model="room.otherPct" label-key="calculators.fragrance.room.other_pct" hint-key="calculators.fragrance.room.other_pct_hint" suffix="%" />
        </div>
        <SourcedInput v-model="room.ratio" label-key="calculators.fragrance.room.ratio" hint-key="calculators.fragrance.room.ratio_hint" unit-key="calculators.fragrance.room.ratio_unit" />
        <FormField :label="t('calculators.fragrance.room.ratio_basis')" :hint="t('calculators.fragrance.room.ratio_basis_hint')">
            <SegmentedControl v-model="room.ratioBasis" :options="basisOptions" :aria-label="t('calculators.fragrance.room.ratio_basis')" />
        </FormField>
        <QuantityInput v-model="room.batch" label-key="calculators.fragrance.fields.batch" :units="['g', 'kg']" />
        <FormField :label="t('calculators.fragrance.room.observation_label')" :hint="t('calculators.fragrance.room.observation_hint')">
            <Select v-model="room.observation" :options="observationOptions" :placeholder="t('common.select_placeholder')" />
        </FormField>
        <SourcedInput v-model="room.clarity" valueless label-key="calculators.fragrance.room.clarity_source" hint-key="calculators.fragrance.room.clarity_source_hint" />
        <SourcedInput v-model="room.ifra" label-key="calculators.fragrance.fields.ifra_limit" hint-key="calculators.fragrance.hints.ifra_limit" :units="['%w/w']" />
    </div>
</template>
