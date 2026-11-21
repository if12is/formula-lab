<script setup>
/**
 * The small second line under an EGP figure when the visitor chose a display currency: "about 1.92 USD per kg, at 50 on
 * 2026-11-20 · CBE". EGP stays the primary figure and this only adds to it; with no rate it says so and links to add one,
 * never a guess. Shows nothing for a missing figure or when the display currency is EGP. The conversion is the engine's.
 *
 *   <DisplayCurrencyLine :egp="cost.perKg" suffix-key="formulas.units.per_kg" />
 */
import { computed } from 'vue';
import { path } from '../../lib/routes';
import { useDisplayCurrency } from '../../composables/useDisplayCurrency';
import { useI18n } from '../../composables/useI18n';
import Num from '../Num.vue';

const props = defineProps({
    /** The EGP figure as a decimal string, or null when there is none. */
    egp: { type: String, default: null },
    decimals: { type: Number, default: 2 },
    /** Lang key of the unit that follows the figure (per kg). */
    suffixKey: { type: String, default: null },
});

const { t } = useI18n();
const { code, active, rate, convert } = useDisplayCurrency();
const converted = computed(() => (props.egp === null ? null : convert(props.egp)));
const addRateHref = computed(() => path('settings.exchange-rates.index', { new_currency: code.value }));
</script>

<template>
    <span v-if="active && egp !== null" class="flex flex-wrap items-baseline gap-x-1.5 text-label text-ink-3" data-testid="display-currency-line">
        <template v-if="converted !== null && rate">
            <span class="text-ink-2" :title="t('exchange_rates.display.approx')">≈ <Num :value="converted" :currency="code" :decimals="decimals" /><span v-if="suffixKey" class="ms-1">{{ t(suffixKey) }}</span></span>
            <span>{{ t('exchange_rates.display.rate_prefix') }} <Num :value="rate.rate" /> · <bdi dir="ltr" class="tnum">{{ rate.rate_date }}</bdi> · {{ rate.source_title }}</span>
        </template>
        <template v-else>
            <Num :value="null" reason-key="exchange_rates.display.no_rate_reason" />
            <span>{{ t('exchange_rates.display.no_rate', { currency: code }) }}</span>
            <a :href="addRateHref" class="text-primary-ink underline underline-offset-2">{{ t('exchange_rates.display.add_rate') }}</a>
        </template>
    </span>
</template>
