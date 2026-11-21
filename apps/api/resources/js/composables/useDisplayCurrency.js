import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { toDisplayCurrency } from '../lib/engine';

/**
 * The visitor's display currency (a cookie, set from the topbar menu) and the newest exchange rate the server found for
 * it. Costs are computed and stored in EGP; when the display currency is not EGP a second figure sits next to the EGP one.
 * The conversion is the engine's (lib/engine.js toDisplayCurrency); this only reads the shared `currency` prop.
 *
 *   const { code, active, rate, convert } = useDisplayCurrency();
 *   convert('96')    '1.9...' (decimal string) in the display currency, or null without a rate
 */
export function useDisplayCurrency() {
    const page = usePage();
    const info = computed(() => page.props.currency ?? { base: 'EGP', display: 'EGP', options: [], rate: null });
    const code = computed(() => info.value.display);

    return {
        base: computed(() => info.value.base),
        code,
        active: computed(() => info.value.display !== info.value.base),
        rate: computed(() => info.value.rate),
        options: computed(() => info.value.options),
        convert: (egp) => toDisplayCurrency(egp, code.value, info.value.rate),
    };
}
