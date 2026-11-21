/** Litre <-> kilo: mass and volume of a material through an explicit, sourced, measured density. No density, no answer. */
import { massToVolume, resolveDensity, volumeToMass } from '@fl/engine';
import { EMPTY, isBlank, outcomeOf, row, toQty } from './common';
import { leaf, node } from './trace';
import { blankSourced, toSourced } from './sourced';

export const DIRECTIONS = ['mass_to_volume', 'volume_to_mass'];
export const UNITS = { mass: ['g', 'kg', 'mg'], volume: ['mL', 'L'] };

export const blankLitreKilo = () => ({
    direction: DIRECTIONS[0],
    amount: { value: '', unit: 'kg' },
    resultUnit: 'L',
    density: blankSourced('g/mL'),
    material: null,
});

export function runLitreKilo(state) {
    if (isBlank(state.amount.value) && isBlank(state.density.value)) {
        return EMPTY;
    }

    const toVolume = state.direction === DIRECTIONS[0];
    const density = resolveDensity(state.material?.label, 'density', toSourced(state.density, { withUnit: true }), {}, 'Measured density');
    const amount = toQty(state.amount);
    const label = state.material?.label;

    if (!density.ok) {
        return outcomeOf(density, () => [], 'density');
    }

    const converted = toVolume
        ? massToVolume(amount, density.value.ref, state.resultUnit, { materialId: label })
        : volumeToMass(amount, density.value.ref, state.resultUnit, { materialId: label });
    const combined = converted.ok ? { ...converted, warnings: [...density.warnings, ...converted.warnings] } : converted;

    return outcomeOf(combined, (quantity) => [
        row(
            toVolume ? 'volume' : 'mass',
            node(toVolume ? 'div' : 'mul', toVolume ? 'V = m / ρ' : 'm = V × ρ', quantity.value.toFixed(), quantity.unit, [leaf(toVolume ? 'Mass m' : 'Volume V', amount.value.toFixed(), amount.unit), density.value.leaf]),
            3,
            { primary: true },
        ),
    ], 'density');
}
