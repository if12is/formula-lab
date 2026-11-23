/**
 * Fine fragrance batch (L-02), alcohol dilution by the OIML mass balance (L-03), the IFRA check against a certificate limit,
 * a water-based room spray (L-05), an aerosol (L-06), reed diffuser, oil, gel and car blends (L-07, L-08), candles and wax
 * (L-07) and the full perfume cost with its process losses (L-04, L-10).
 */
import { aerosol, airCareBlend, candle, checkIfraLimit, diluteEthanol, fineFragrance, inputLeaf, perfumeCost, roomSpray } from '@fl/engine';
import { EMPTY, decimalOrNull, isBlank, outcomeOf, row, toQty, typed, warningOf } from './common';
import { blankSourced, toSourced } from './sourced';

export const MODES = ['fine', 'cost', 'room_spray', 'aerosol', 'blend', 'candle', 'alcohol', 'ifra'];
/** What each air-care blend is made of besides the oil: the carrier that takes the remainder and an optional sourced dose. */
export const BLEND_TYPES = {
    reed_diffuser: { carrier: 'solvent', dose: null },
    oil_burner: { carrier: 'solvent', dose: null },
    gel: { carrier: 'water', dose: 'gelAgent' },
    car: { carrier: 'solvent', dose: null },
};
export const RATIO_BASES = ['tds', 'trial'];
export const CLARITY_OBSERVATIONS = ['clear', 'hazy', 'separated'];
export const LOSS_STAGES = ['ageing', 'chilling', 'filtration'];
export const PACKAGING_SLOTS = ['bottle', 'sprayer', 'box'];
export const PRICE_ITEMS = ['oil', 'alcohol', 'water', 'fixative'];
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
    room: {
        oilPct: '',
        otherPct: '',
        ratio: blankSourced(''),
        ratioBasis: '',
        batch: { value: '', unit: 'g' },
        observation: '',
        clarity: blankSourced(''),
        ifra: blankSourced('%w/w'),
    },
    aerosol: {
        oilPct: '',
        ratio: blankSourced(''),
        ratioBasis: '',
        fill: { value: '', unit: 'g' },
        cans: '',
        maxFill: blankSourced('g'),
        ifra: blankSourced('%w/w'),
    },
    blend: {
        type: Object.keys(BLEND_TYPES)[0],
        oilPct: '',
        otherPct: '',
        dose: blankSourced('%w/w'),
        batch: { value: '', unit: 'g' },
        ifra: blankSourced('%w/w'),
    },
    candle: {
        loadPct: '',
        wax: { value: '', unit: 'g' },
        maxLoad: blankSourced('%w/w'),
        ifra: blankSourced('%w/w'),
    },
    /* The recipe of the cost is the fine-fragrance one (state.fine); these are the prices, losses, fill and packaging on top of it. */
    cost: {
        prices: Object.fromEntries(PRICE_ITEMS.map((item) => [item, blankSourced('EGP')])),
        loss: Object.fromEntries(LOSS_STAGES.map((stage) => [stage, blankSourced('%w/w')])),
        version: null,
        lossFromLab: null,
        fill: blankSourced('mL'),
        packaging: Object.fromEntries(PACKAGING_SLOTS.map((slot) => [slot, { price: blankSourced('EGP'), reject: blankSourced('%w/w') }])),
    },
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

/** The clarity of a trial: the observation with the source, confidence and date of the measurement, or nothing. */
function clarityOf(state) {
    const { observation, clarity } = state;

    return observation && clarity.sourceId && clarity.confidence && clarity.validFrom
        ? { observation, sourceId: clarity.sourceId, confidence: clarity.confidence, validFrom: clarity.validFrom }
        : undefined;
}

export function runRoomSpray(state) {
    if (isBlank(state.oilPct) && isBlank(state.batch.value) && isBlank(state.ratio.value)) {
        return EMPTY;
    }

    const result = roomSpray({
        fragranceOilPct: typed(state.oilPct),
        ...(isBlank(state.otherPct) ? {} : { otherPct: typed(state.otherPct) }),
        solubilizerRatio: toSourced(state.ratio),
        ratioBasis: state.ratioBasis || undefined,
        clarity: clarityOf(state),
        batchMass: toQty(state.batch),
        ...(state.ifra.value === '' ? {} : { ifraLimitPct: toSourced(state.ifra) }),
        policy: {},
    });

    return outcomeOf(result, (spray) => [
        row('oil_mass', spray.fragranceOilMass, 3),
        row('solubilizer_mass', spray.solubilizerMass, 3, { primary: true }),
        row('water_mass', spray.waterMass, 3, { primary: true }),
        row('total_mass', spray.totalMass, 3),
        row('solubilizer_pct', spray.solubilizerPct, 3, { group: 'shares' }),
        row('water_pct', spray.waterPct, 3, { group: 'shares' }),
        row('clarity_status', null, undefined, { kind: 'status', status: spray.clarity.status, missing: spray.clarity.missing, primary: true, group: 'checks' }),
        ...ifraRows(spray.ifra, ''),
    ], SCOPE);
}

export function runAerosol(state) {
    if (isBlank(state.oilPct) && isBlank(state.fill.value) && isBlank(state.ratio.value)) {
        return EMPTY;
    }

    const result = aerosol({
        fragranceOilPctOfConcentrate: typed(state.oilPct),
        propellantRatio: toSourced(state.ratio),
        ratioBasis: state.ratioBasis || undefined,
        netFillMass: toQty(state.fill),
        ...(isBlank(state.cans) ? {} : { cans: typed(state.cans) }),
        ...(state.maxFill.value === '' ? {} : { maxFillMassG: toSourced(state.maxFill, { withUnit: true }) }),
        ...(state.ifra.value === '' ? {} : { ifraLimitPct: toSourced(state.ifra) }),
        policy: {},
    });

    return outcomeOf(result, (can) => [
        row('propellant_mass', can.propellantMassPerCan, 3, { primary: true }),
        row('concentrate_mass', can.concentrateMassPerCan, 3, { primary: true }),
        row('oil_mass', can.oilMassPerCan, 3),
        row('concentrate_pct', can.concentrateSharePct, 3, { group: 'gas' }),
        row('propellant_pct', can.propellantSharePct, 3, { group: 'gas' }),
        row('oil_pct_product', can.oilPctOfProduct, 3, { group: 'gas' }),
        ...(can.batch ? [row('run_concentrate', can.batch.concentrateMass, 3, { group: 'run' }), row('run_propellant', can.batch.propellantMass, 3, { group: 'run' })] : []),
        row('fill_status', null, undefined, { kind: 'status', status: can.fillCheck.status, missing: can.fillCheck.missing, primary: true, group: 'checks' }),
        ...(can.fillCheck.limit ? [row('fill_limit', can.fillCheck.limit, 3, { group: 'checks' }), row('fill_margin', can.fillCheck.margin, 3, { group: 'checks' })] : []),
        ...ifraRows(can.ifra, ''),
    ], SCOPE);
}

export function runBlend(state) {
    if (isBlank(state.oilPct) && isBlank(state.batch.value)) {
        return EMPTY;
    }

    const { carrier, dose } = BLEND_TYPES[state.type];
    const result = airCareBlend({
        fragranceOilPct: typed(state.oilPct),
        ...(isBlank(state.otherPct) ? {} : { otherPct: typed(state.otherPct) }),
        doses: dose ? [{ key: dose, pct: toSourced(state.dose) }] : [],
        batchMass: toQty(state.batch),
        ...(state.ifra.value === '' ? {} : { ifraLimitPct: toSourced(state.ifra) }),
        policy: {},
    });

    return outcomeOf(result, (blend) => [
        row('oil_mass', blend.fragranceOil.mass, 3),
        ...blend.doses.map((part) => row(`dose_${part.key}_mass`, part.mass, 3)),
        row(`carrier_${carrier}_mass`, blend.remainder.mass, 3, { primary: true }),
        row('total_mass', blend.totalMass, 3),
        ...blend.doses.map((part) => row(`dose_${part.key}_pct`, part.pct, 3, { group: 'shares' })),
        row(`carrier_${carrier}_pct`, blend.remainder.pct, 3, { group: 'shares' }),
        ...ifraRows(blend.ifra, ''),
    ], SCOPE);
}

export function runCandle(state) {
    if (isBlank(state.loadPct) && isBlank(state.wax.value)) {
        return EMPTY;
    }

    const result = candle({
        loadPctOfWax: typed(state.loadPct),
        waxMass: toQty(state.wax),
        ...(state.maxLoad.value === '' ? {} : { waxMaxLoadPct: toSourced(state.maxLoad) }),
        ...(state.ifra.value === '' ? {} : { ifraLimitPct: toSourced(state.ifra) }),
        policy: {},
    });

    return outcomeOf(result, (wax) => [
        row('fragrance_mass', wax.fragranceMass, 3, { primary: true }),
        row('finished_mass', wax.finishedMass, 3),
        row('candle_pct', wax.fragrancePctOfCandle, 3),
        row('load_status', null, undefined, { kind: 'status', status: wax.loadCheck.status, missing: wax.loadCheck.missing, primary: true, group: 'checks' }),
        ...(wax.loadCheck.limit ? [row('load_limit', wax.loadCheck.limit, 3, { group: 'checks' }), row('load_margin', wax.loadCheck.margin, 3, { group: 'checks' })] : []),
        ...ifraRows(wax.ifra, ''),
    ], SCOPE);
}

/** One packaging slot: the price of one piece and its reject %; the piece count is 1 (the engine says so on the line). */
const slotOf = (slot) => ({ price: toSourced(slot.price, { withUnit: true }), rejectPct: toSourced(slot.reject) });

const COST_KEYS = ['oil', 'alcohol', 'water', 'fixative', 'loss', 'bottle', 'sprayer', 'box'];

export function runCost(state) {
    const { fine, cost } = state;

    if (isBlank(fine.oilPct) && isBlank(cost.fill.value) && PRICE_ITEMS.every((item) => isBlank(cost.prices[item].value))) {
        return EMPTY;
    }

    const result = perfumeCost({
        fine: {
            fragranceOilPct: typed(fine.oilPct),
            ...(isBlank(fine.fixativePct) ? {} : { fixativePct: typed(fine.fixativePct) }),
            ...(isBlank(fine.addedWaterPct) ? {} : { addedWaterPct: typed(fine.addedWaterPct) }),
            alcoholSource: toSourced(fine.alcohol, { withUnit: true }),
            alcoholTargetPctVV: typed(fine.targetPct),
            ...(fine.ifra.value === '' ? {} : { ifraLimitPct: toSourced(fine.ifra) }),
        },
        prices: Object.fromEntries(PRICE_ITEMS.map((item) => [item, toSourced(cost.prices[item], { withUnit: true })])),
        loss: Object.fromEntries(LOSS_STAGES.map((stage) => [stage, toSourced(cost.loss[stage], { withUnit: true })])),
        productDensity: toSourced(fine.productDensity, { withUnit: true }),
        fill: toSourced(cost.fill, { withUnit: true }),
        packaging: Object.fromEntries(PACKAGING_SLOTS.map((slot) => [slot, slotOf(cost.packaging[slot])])),
        policy: {},
    });

    return outcomeOf(result, (perfume) => [
        ...COST_KEYS.flatMap((key) => {
            const component = perfume.components.find((item) => item.key === key);

            return component ? [row(`cost_${key}`, component.perUnit, 4, { group: 'components' })] : [];
        }),
        row('cost_direct_total', perfume.directTotal, 4, { primary: true }),
        row('loss_total_pct', perfume.loss.totalLossPct, 3, { group: 'loss' }),
        row('loss_yield', perfume.loss.yield, 4, { group: 'loss' }),
        row('oil_mass', perfume.fine.fragranceOilMass, 3, { group: 'batch_per_kg' }),
        row('alcohol_mass', perfume.fine.alcoholMass, 3, { group: 'batch_per_kg' }),
        row('water_mass', perfume.fine.waterMass, 3, { group: 'batch_per_kg' }),
        ...ifraRows(perfume.fine.ifra, fine.category),
    ], SCOPE);
}

export function runFragrance(state) {
    return {
        fine: () => runFine(state.fine),
        cost: () => runCost(state),
        room_spray: () => runRoomSpray(state.room),
        aerosol: () => runAerosol(state.aerosol),
        blend: () => runBlend(state.blend),
        candle: () => runCandle(state.candle),
        alcohol: () => runAlcohol(state.alcohol),
        ifra: () => runIfra(state.ifra),
    }[state.mode]();
}

/**
 * Fills the loss fields of the cost from what the lab measured for a formula version (payload of
 * lab.formula-versions.process-losses). A stage no trial measured keeps what the person typed; it is never zeroed.
 * Returns the stages that were filled.
 */
export function fillLossesFromLab(cost, losses) {
    const filled = LOSS_STAGES.filter((stage) => losses?.[stage]);

    for (const stage of filled) {
        const { value, unit, sourceId, sourceTitle, confidence, validFrom, href } = losses[stage];
        Object.assign(cost.loss[stage], { value, unit, sourceId, sourceTitle, confidence, validFrom, fromMaterial: null, href });
    }

    return filled;
}
