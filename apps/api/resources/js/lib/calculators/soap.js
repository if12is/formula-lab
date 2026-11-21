/** Saponification (F-06): lye for a set of oils at a superfat, with the lye's water in three equivalent ways. */
import { soapLye } from '@fl/engine';
import { EMPTY, isBlank, outcomeOf, row, toQty, typed } from './common';
import { resolvedProperty } from '../engine';
import { blankSourced, fillFromMaterial, toSourced, withProvenanceOf } from './sourced';

export const SAP_UNITS = ['mgKOH/g', 'gNaOH/g'];
export const BASES = ['NaOH', 'KOH'];
export const WATER_METHODS = ['ratio', 'lyeConcentration', 'pctOfOils'];

export const blankOil = (key) => ({
    key,
    material: null,
    mass: { value: '', unit: 'g' },
    sap: blankSourced(SAP_UNITS[0]),
    rangeMin: '',
    rangeMax: '',
    /** A material's own SAP range ({ min, max } as sourced values), when it has one in the SAP's unit. */
    materialRange: null,
});

export const blankSoap = (firstKey) => ({
    base: BASES[0],
    purity: blankSourced('%w/w'),
    superfat: '',
    water: { method: WATER_METHODS[0], value: '' },
    lines: [blankOil(firstKey)],
});

/** The name each oil carries into the engine (and its trace): the material's name, or the line's number, made unique. */
export function oilNames(lines, fallbackOf) {
    const seen = new Map();

    return lines.map((line, index) => {
        const base = line.material?.label || fallbackOf(index + 1);
        const count = (seen.get(base) ?? 0) + 1;
        seen.set(base, count);

        return count === 1 ? base : `${base} (${count})`;
    });
}

export function runSoap(state, fallbackOf) {
    const blank = state.lines.every((line) => isBlank(line.mass.value) && isBlank(line.sap.value)) && isBlank(state.superfat) && isBlank(state.purity.value);

    if (blank) {
        return EMPTY;
    }

    const names = oilNames(state.lines, fallbackOf);
    const oils = state.lines.map((line, index) => {
        const min = line.materialRange?.min ?? withProvenanceOf(line.sap, line.rangeMin);
        const max = line.materialRange?.max ?? withProvenanceOf(line.sap, line.rangeMax);

        return {
            oilId: names[index],
            mass: toQty(line.mass),
            sap: { unit: line.sap.unit, typ: toSourced(line.sap), ...(min && { min }), ...(max && { max }) },
        };
    });

    const result = soapLye({
        oils,
        base: state.base,
        purity: toSourced(state.purity),
        superfatPct: typed(state.superfat),
        ...(isBlank(state.water.value) ? {} : { water: { method: state.water.method, value: typed(state.water.value) } }),
        policy: {},
    });

    return outcomeOf(result, (lye) => [
        row('lye_used', lye.used, 3, { params: { base: state.base }, primary: true }),
        row('oils_mass', lye.oilsMass, 3),
        ...(lye.water
            ? [
                row('water_mass', lye.water.waterMass, 3, { group: 'water' }),
                row('water_ratio', lye.water.ratioWaterToLye, 3, { group: 'water' }),
                row('water_concentration', lye.water.lyeConcentrationPct, 3, { group: 'water' }),
                row('water_pct_of_oils', lye.water.waterPctOfOils, 3, { group: 'water' }),
                row('solution_mass', lye.water.solutionMass, 3, { group: 'water' }),
            ]
            : []),
        ...(lye.min && lye.max
            ? [
                row('lye_min', lye.min, 3, { group: 'range', params: { base: state.base } }),
                row('lye_typ', lye.typ, 3, { group: 'range', params: { base: state.base } }),
                row('lye_max', lye.max, 3, { group: 'range', params: { base: state.base } }),
                row('spread_abs', lye.spread.absolute, 3, { group: 'range' }),
                row('spread_pct', lye.spread.pctOfTyp, 3, { group: 'range' }),
            ]
            : []),
    ]);
}

/**
 * Puts a material on an oil line and prefills its SAP (and SAP range) from the material's own values: the ones the engine's
 * resolveValue picks. A range is taken only in the SAP's own unit; anything the material lacks is left to be typed with a source.
 */
export function applyMaterialToOil(line, option) {
    line.material = option;
    line.materialRange = null;
    fillFromMaterial(line.sap, option, 'sap');

    const range = resolvedProperty(option.properties ?? [], 'sap_range');

    if (range && range.candidate.min !== undefined && range.candidate.max !== undefined && range.candidate.unit === line.sap.unit) {
        const { candidate } = range;
        const sourced = (value) => ({ value, sourceId: candidate.sourceId, confidence: candidate.confidence, validFrom: candidate.validFrom });
        line.materialRange = { min: sourced(candidate.min), max: sourced(candidate.max) };
    }
}
