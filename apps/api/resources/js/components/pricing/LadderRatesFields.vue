<script setup>
/**
 * The margins and costs between the factory and the shelf, typed as what-ifs: the same fields feed the price ladder screen and the
 * reverse pricing of the tiers screen, so both walk the engine's one waterfall with the same inputs. Nothing is pre-filled: an empty
 * field is "not given" and the engine lists it as missing. The numbers are the person's assumptions, not data the app holds.
 *
 *   <LadderRatesFields v-model="rates" :errors="errors" with-leakage />
 */
import { FormField, SegmentedControl } from '@kit';
import { useI18n } from '../../composables/useI18n';
import DecimalField from '../form/DecimalField.vue';

defineProps({
    errors: { type: Object, default: () => ({}) },
    /** Also ask for the returns and promotion lines (optional lines of the waterfall). */
    withLeakage: { type: Boolean, default: false },
});

const rates = defineModel({ type: Object, required: true });
const { t } = useI18n();
const basisOptions = ['markup_on_cost', 'margin_on_price'].map((value) => ({ value, label: t(`price_ladder.rates.basis.${value}`) }));
</script>

<template>
    <div class="flex flex-col gap-4" data-testid="ladder-rates">
        <FormField :label="t('price_ladder.rates.basis.label')" :hint="t('price_ladder.rates.basis.hint')" :error="errors.margin_basis">
            <SegmentedControl v-model="rates.margin_basis" :options="basisOptions" data-testid="margin-basis" />
        </FormField>
        <div class="grid gap-4 sm:grid-cols-2">
            <DecimalField v-model="rates.factory_margin_pct" label-key="price_ladder.rates.factory_margin_pct" suffix="%" :error="errors.factory_margin_pct" />
            <DecimalField v-model="rates.distribution_cost_per_unit" label-key="price_ladder.rates.distribution_cost_per_unit" :suffix="t('price_ladder.units.egp_per_unit')" :error="errors.distribution_cost_per_unit" />
            <DecimalField v-model="rates.wholesaler_margin_pct" label-key="price_ladder.rates.wholesaler_margin_pct" suffix="%" :error="errors.wholesaler_margin_pct" />
            <DecimalField v-model="rates.retailer_margin_pct" label-key="price_ladder.rates.retailer_margin_pct" suffix="%" :error="errors.retailer_margin_pct" />
            <template v-if="withLeakage">
                <DecimalField v-model="rates.returns_pct" label-key="price_ladder.rates.returns_pct" suffix="%" :error="errors.returns_pct" />
                <DecimalField v-model="rates.promo_pct" label-key="price_ladder.rates.promo_pct" suffix="%" :error="errors.promo_pct" />
            </template>
        </div>
    </div>
</template>
