/** Percent from weight (F-15): % w/w of a material in a batch, from weights or volumes with explicit densities. */
import { percentFromWeight } from '@fl/engine';
import { EMPTY, isBlank, outcomeOf, row, toQty } from './common';
import { blankSourced, toSourced } from './sourced';

export const AMOUNT_UNITS = ['g', 'kg', 'mg', 'mL', 'L'];
export const BATCH_UNITS = ['g', 'kg', 'mL', 'L'];
const isVolume = (unit) => unit === 'mL' || unit === 'L';

export const blankPercent = () => ({
    material: null,
    amount: { value: '', unit: 'g' },
    batch: { value: '', unit: 'kg' },
    materialDensity: blankSourced('g/mL'),
    productDensity: blankSourced('g/mL'),
});

export const needsMaterialDensity = (state) => isVolume(state.amount.unit);
export const needsProductDensity = (state) => isVolume(state.batch.unit);

export function runPercent(state) {
    if (isBlank(state.amount.value) && isBlank(state.batch.value)) {
        return EMPTY;
    }

    const result = percentFromWeight({
        ...(state.material ? { materialId: state.material.label } : {}),
        amount: toQty(state.amount),
        // A zero batch is refused by the engine (percent_from_weight.batch_not_positive), so a blank one is reported too.
        batchSize: toQty(state.batch),
        ...(needsMaterialDensity(state) ? { materialDensity: toSourced(state.materialDensity, { withUnit: true }) } : {}),
        ...(needsProductDensity(state) ? { productDensity: toSourced(state.productDensity, { withUnit: true }) } : {}),
        policy: {},
    });

    return outcomeOf(result, (percent) => [row('percent', percent, 3, { primary: true })], 'percent');
}
