<script setup>
/**
 * Aerosol air freshener (L-06): oil % of the concentrate, the propellant to concentrate mass ratio from a TDS or a
 * documented trial (no built-in ratio), the net fill per can, optional can count and rated maximum fill, and an optional
 * IFRA limit. The model is state.aerosol of lib/calculators/fragrance.js; its fields are edited in place.
 */
import { computed } from 'vue';
import { FormField, SegmentedControl } from '@kit';
import DecimalField from '../form/DecimalField.vue';
import QuantityInput from '../form/QuantityInput.vue';
import SourcedInput from '../form/SourcedInput.vue';
import { useI18n } from '../../composables/useI18n';
import { RATIO_BASES } from '../../lib/calculators/fragrance';

const aerosol = defineModel({ type: Object, required: true });
const { t } = useI18n();

const basisOptions = computed(() => RATIO_BASES.map((basis) => ({ value: basis, label: t(`calculators.fragrance.aerosol.basis.${basis}`) })));
</script>

<template>
    <div class="flex flex-col gap-4">
        <p class="text-label text-ink-3">{{ t('calculators.fragrance.hints.aerosol') }}</p>
        <DecimalField v-model="aerosol.oilPct" label-key="calculators.fragrance.aerosol.oil_pct" hint-key="calculators.fragrance.aerosol.oil_pct_hint" suffix="%" />
        <SourcedInput v-model="aerosol.ratio" label-key="calculators.fragrance.aerosol.ratio" hint-key="calculators.fragrance.aerosol.ratio_hint" unit-key="calculators.fragrance.aerosol.ratio_unit" />
        <FormField :label="t('calculators.fragrance.aerosol.ratio_basis')" :hint="t('calculators.fragrance.aerosol.ratio_basis_hint')">
            <SegmentedControl v-model="aerosol.ratioBasis" :options="basisOptions" :aria-label="t('calculators.fragrance.aerosol.ratio_basis')" />
        </FormField>
        <QuantityInput v-model="aerosol.fill" label-key="calculators.fragrance.aerosol.fill" :units="['g', 'kg']" />
        <DecimalField v-model="aerosol.cans" label-key="calculators.fragrance.aerosol.cans" hint-key="calculators.fragrance.aerosol.cans_hint" />
        <SourcedInput v-model="aerosol.maxFill" label-key="calculators.fragrance.aerosol.max_fill" hint-key="calculators.fragrance.aerosol.max_fill_hint" :units="['g']" />
        <SourcedInput v-model="aerosol.ifra" label-key="calculators.fragrance.fields.ifra_limit" hint-key="calculators.fragrance.hints.ifra_limit" :units="['%w/w']" />
    </div>
</template>
