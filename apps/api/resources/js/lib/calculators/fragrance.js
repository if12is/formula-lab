/** Fine fragrance batch (L-02), alcohol dilution by the OIML mass balance (L-03) and the IFRA check against a certificate limit. */
import { checkIfraLimit, diluteEthanol, fineFragrance, inputLeaf } from '@fl/engine';
import { EMPTY, decimalOrNull, isBlank, outcomeOf, row, toQty, typed, warningOf } from './common';
import { blankSourced, toSourced } from './sourced';

export const MODES = ['fine', 'alcohol', 'ifra'];
export const BATCH_BY = ['mass', 'volume'];
export const ALCOHOL_UNITS = ['%v/v', '%w/w'];
const SCOPE = 'alcohol';

export const blankFragrance = () => ({
    mode: MODES[0],
    fine: {
        oilPct: '',
        fixativePct: '',
        addedWaterPct: '',
        alcohol: blankSourced('%v/v'),
        targetPct: '',
        batchBy: BATCH_BY[0],
        batch: { value: '', unit: 'g' },
        productDensity: blankSourced('g/mL'),
        ifra: blankSourced('%w/w'),
        category: '',
    },
    alcohol: {
        source: blankSourced('%v/v'),
        targetPct: '',
        batchBy: BATCH_BY[0],
        batch: { value: '', unit: 'g' },
    },
    ifra: { oilPct: '', limit: blankSourced('%w/w'), category: '' },
});

const batchOf = (state) => (state.batchBy === 'mass' ? { mass: toQty(state.batch) } : { volume: toQty(state.batch) });

/** The IFRA verdict as a result row: PASS / FAIL / UNKNOWN is the engine's, with its margin and reasons. */
const ifraRows = (ifra, category) => [
    row('ifra_status', null, undefined, { kind: 'status', status: ifra.status, missing: ifra.missing, primary: true, params: { category } }),
    ...(ifra.limitPct ? [row('ifra_limit', ifra.limitPct, 3, { group: 'ifra' }), row('ifra_margin', ifra.marginPct, 3, { group: 'ifra' })] : []),
];

export function runFine(state) {
    if (isBlank(state.oilPct) && isBlank(state.batch.value) && isBlank(state.alcohol.value) && isBlank(state.targetPct)) {
        return EMPTY;
    }

    const result = fineFragrance({
        fragranceOilPct: typed(state.oilPct),
        ...(isBlank(state.fixativePct) ? {} : { fixativePct: typed(state.fixativePct) }),
        ...(isBlank(state.addedWaterPct) ? {} : { addedWaterPct: typed(state.addedWaterPct) }),
        alcoholSource: toSourced(state.alcohol, { withUnit: true }),
        alcoholTargetPctVV: typed(state.targetPct),
        batch: batchOf(state),
        ...(isBlank(state.productDensity.value) ? {} : { productDensity: toSourced(state.productDensity, { withUnit: true }) }),
        ...(state.ifra.value === '' ? {} : { ifraLimitPct: toSourced(state.ifra) }),
        policy: {},
    });

    return outcomeOf(result, (batch) => [
        row('oil_mass', batch.fragranceOilMass, 3),
        ...(batch.fixativeMass ? [row('fixative_mass', batch.fixativeMass, 3)] : []),
        row('alcohol_mass', batch.alcoholMass, 3, { primary: true }),
        row('water_mass', batch.waterMass, 3, { primary: true }),
        row('total_mass', batch.totalMass, 3),
        ...(batch.totalVolume ? [row('total_volume', batch.totalVolume, 2)] : []),
        row('carrier_pct', batch.carrierPctWW, 3, { group: 'ethanol' }),
        row('ethanol_ww', batch.finalEthanolPctWW, 3, { group: 'ethanol' }),
        row('ethanol_vv', batch.finalEthanolPctVV, 2, { group: 'ethanol', indicator: true }),
        ...ifraRows(batch.ifra, state.category),
    ], SCOPE);
}

export function runAlcohol(state) {
    if (isBlank(state.source.value) && isBlank(state.targetPct) && isBlank(state.batch.value)) {
        return EMPTY;
    }

    const result = diluteEthanol({
        source: toSourced(state.source, { withUnit: true }),
        targetPctVV: typed(state.targetPct),
        batch: batchOf(state),
        policy: {},
    });

    return outcomeOf(result, (dilution) => [
        row('source_mass', dilution.sourceMass, 3, { primary: true }),
        row('water_mass', dilution.waterMass, 3, { primary: true }),
        row('total_mass', dilution.totalMass, 3),
        row('total_volume', dilution.totalVolume, 2),
        row('source_ww', dilution.sourcePctWW, 3, { group: 'strength' }),
        row('target_ww', dilution.targetPctWW, 3, { group: 'strength' }),
        row('target_density', dilution.targetDensity, 4, { group: 'strength' }),
        row('source_volume', dilution.sourceVolume, 2, { group: 'contraction' }),
        row('water_volume', dilution.waterVolume, 2, { group: 'contraction' }),
        row('contraction_volume', dilution.contractionVolume, 2, { group: 'contraction' }),
        row('contraction_pct', dilution.contractionPct, 2, { group: 'contraction' }),
    ], SCOPE);
}

export function runIfra(state) {
    if (isBlank(state.oilPct) && isBlank(state.limit.value)) {
        return EMPTY;
    }

    const oil = decimalOrNull(state.oilPct);

    if (oil === null) {
        return { state: 'missing', results: [], missing: [{ property: 'fragranceOilPct', reason: 'input.invalid_number' }], warnings: [] };
    }

    const check = checkIfraLimit(inputLeaf('Fragrance oil (%)', oil, '%w/w'), toSourced(state.limit), {});
    const warnings = [...check.warnings, ...(check.status === 'UNKNOWN' ? ['fragrance.ifra_unknown'] : [])].map(warningOf);

    return { state: 'ready', results: ifraRows(check, state.category), missing: [], warnings };
}

export function runFragrance(state) {
    return { fine: () => runFine(state.fine), alcohol: () => runAlcohol(state.alcohol), ifra: () => runIfra(state.ifra) }[state.mode]();
}
