import { inject, provide } from 'vue';

const KEY = Symbol('calculator-lookups');

/**
 * What every calculator input needs from the server: the lookup addresses (materials, sources), the confidence levels and
 * the source kinds. CalculatorLayout provides them once; SourcedInput and MaterialPicker read them, so a page passes them a
 * single time instead of on every input.
 */
export function provideCalculatorLookups(lookups) {
    provide(KEY, lookups);
}

export function useCalculatorLookups() {
    return inject(KEY, { urls: {}, confidenceOptions: [], sourceKindOptions: [] });
}
