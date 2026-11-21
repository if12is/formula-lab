/**
 * The formula's own salt curve through the engine (formulaViscosityAtSalt / formulaSaltForViscosity): the browser only passes
 * the measured points the server sent and the number typed; every figure shown is the engine's, with its calculation tree.
 */
import { formulaSaltForViscosity, formulaViscosityAtSalt } from '@fl/engine';
import { EMPTY, isBlank, outcomeOf, row, typed } from '../calculators/common';

export const MODES = ['viscosity', 'salt'];

const SCOPE = 'salt_curve';

/**
 * An outcome (see lib/calculators/common.js) of one lookup on the curve, or EMPTY while nothing is typed.
 * `viscosity`: the viscosity at a salt %; `salt`: every salt % that gives a viscosity (a curve that rises and falls has several).
 */
export function runSaltCurve(curve, mode, text) {
    if (isBlank(text)) {
        return EMPTY;
    }

    if (mode === MODES[0]) {
        return outcomeOf(formulaViscosityAtSalt(curve, typed(text)), (found) => [row('viscosity', found.viscosity, 1, { primary: true, bracket: found.bracket })], SCOPE);
    }

    return outcomeOf(formulaSaltForViscosity(curve, typed(text)), (found) => found.solutions.map((solution, index) => (
        row(`salt_${index}`, solution.saltPct, 3, { primary: true, params: { n: index + 1 }, many: found.solutions.length > 1, to: solution.saltPctTo ?? null })
    )), SCOPE);
}

/** The chart's data: measured points as [{ x, y }] in salt order, and the engine's answer as one more point when there is one. */
export function chartSeries(points, outcome, mode, text) {
    const measured = points.map((point) => ({ x: Number(point.salt_pct), y: Number(point.viscosity) }));
    const query = outcome.state === 'ready'
        ? outcome.results.map((result) => (mode === MODES[0]
            ? { x: Number(typed(text)), y: Number(result.traced.value) }
            : { x: Number(result.traced.value), y: Number(typed(text)) }))
        : [];

    return { measured, query };
}
