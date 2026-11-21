/** Sulfonic acid neutralisation (F-05): the base to weigh for an acid of a given acid value. A theoretical start, pH is measured. */
import { neutralize } from '@fl/engine';
import { EMPTY, isBlank, outcomeOf, row, toQty } from './common';
import { blankSourced, toSourced } from './sourced';

export const BASES = ['NaOH', 'KOH'];
export const FORMS = ['solid', 'solution'];

export const blankNeutralize = () => ({
    acidMass: { value: '', unit: 'g' },
    acidMaterial: null,
    acidValue: blankSourced('mgKOH/g'),
    base: BASES[0],
    form: FORMS[0],
    strength: blankSourced('%w/w'),
});

export function runNeutralize(state) {
    if (isBlank(state.acidMass.value) && isBlank(state.acidValue.value) && isBlank(state.strength.value)) {
        return EMPTY;
    }

    const result = neutralize({
        acidMass: toQty(state.acidMass),
        ...(state.acidMaterial ? { acidId: state.acidMaterial.label } : {}),
        acidValue: toSourced(state.acidValue),
        base: state.base,
        form: state.form,
        strength: toSourced(state.strength),
        policy: {},
    });

    return outcomeOf(result, (value) => [
        row('reagent_mass', value.reagentMass, 3, { params: { base: state.base }, primary: true }),
        row('pure_base_mass', value.pureBaseMass, 3, { params: { base: state.base } }),
        ...(value.waterInReagent ? [row('water_in_reagent', value.waterInReagent, 3)] : []),
    ]);
}
