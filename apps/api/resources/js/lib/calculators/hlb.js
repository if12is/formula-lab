/**
 * HLB (F-08): the required HLB of an oil phase, the split of two emulsifiers for a target, and Griffin / Davies from
 * the structure. Every rHLB and Davies group number is a sourced input; the engine returns bare values here, so each
 * is wrapped as a trace node over the leaves it came from (see trace.js).
 */
import { IONICITIES, blendEmulsifiers, daviesHLB, emulsifierSystem, griffinHLB, requiredHLB } from '@fl/engine';
import { EMPTY, decimalOrNull, isBlank, outcomeOf, row, toQty, typed } from './common';
import { blankSourced, toSourced } from './sourced';
import { leaf, node, sourcedLeaf } from './trace';

export const MODES = ['required', 'blend', 'system', 'griffin', 'davies'];
export { IONICITIES };
const SCOPE = 'hlb';

export const blankOilLine = (key) => ({ key, material: null, mass: '', hlb: blankSourced('') });
export const blankGroup = (key) => ({ key, group: '', count: '1', h: blankSourced('') });

export const blankHlb = (firstKey) => ({
    mode: MODES[0],
    required: { lines: [blankOilLine(firstKey)] },
    // The HLB blend rule is for non-ionic emulsifiers, so that is what each one starts as; the formulator declares otherwise and the engine refuses it.
    blend: { target: '', low: blankSourced(''), high: blankSourced(''), lowMaterial: null, highMaterial: null, lowIonicity: 'nonionic', highIonicity: 'nonionic', total: { value: '', unit: 'g' } },
    griffin: { hydrophilic: '', total: '' },
    davies: { groups: [blankGroup(firstKey)] },
});

const names = (lines, fallbackOf) => {
    const seen = new Map();

    return lines.map((line, index) => {
        const base = line.material?.label || fallbackOf(index + 1);
        const count = (seen.get(base) ?? 0) + 1;
        seen.set(base, count);

        return count === 1 ? base : `${base} (${count})`;
    });
};

export function runRequired(state, fallbackOf) {
    if (state.lines.every((line) => isBlank(line.mass) && isBlank(line.hlb.value))) {
        return EMPTY;
    }

    const ids = names(state.lines, fallbackOf);
    const lines = state.lines.map((line, index) => ({ materialId: ids[index], mass: typed(line.mass), phase: 'oil', requiredHLB: toSourced(line.hlb) }));
    const result = requiredHLB(lines);

    return outcomeOf(result, ({ value }) => {
        const leaves = lines.flatMap((line) => [
            leaf(`${line.materialId} mass`, line.mass, 'g'),
            sourcedLeaf(`${line.materialId} required HLB`, line.requiredHLB, 'HLB'),
        ]);

        return [row('required_hlb', node('div', 'Required HLB = Σ(m × rHLB) / Σm', value, 'HLB', leaves), 2, { primary: true })];
    }, SCOPE);
}

export function runBlend(state) {
    if (isBlank(state.target) && isBlank(state.low.value) && isBlank(state.high.value) && isBlank(state.total.value)) {
        return EMPTY;
    }

    const lowId = state.lowMaterial?.label ?? 'low';
    const highId = state.highMaterial?.label ?? 'high';
    const total = toQty(state.total);
    const result = blendEmulsifiers(typed(state.target), { materialId: lowId, hlb: toSourced(state.low) ?? { value: '' }, ionicity: state.lowIonicity }, { materialId: highId, hlb: toSourced(state.high) ?? { value: '' }, ionicity: state.highIonicity }, total);

    return outcomeOf(result, (blend) => {
        const inputs = [
            leaf('Target HLB', typed(state.target), 'HLB'),
            sourcedLeaf(`${lowId} HLB`, toSourced(state.low), 'HLB'),
            sourcedLeaf(`${highId} HLB`, toSourced(state.high), 'HLB'),
        ];
        const mass = (id, quantity, fraction) => node('mul', `${id} mass = M × f`, quantity.value.toFixed(), quantity.unit, [leaf('Emulsifier total (M)', total.value.toFixed(), total.unit), node('div', 'Fraction f from the HLB mixing rule', fraction, '', inputs)]);

        return [
            row('mass_high', mass(highId, blend.high.mass, blend.fractionHigh), 3, { primary: true, params: { name: highId } }),
            row('mass_low', mass(lowId, blend.low.mass, blend.fractionLow), 3, { primary: true, params: { name: lowId } }),
            row('fraction_high', node('div', 'f_high = (HLB_t − HLB_low) / (HLB_high − HLB_low)', blend.fractionHigh, '', inputs), 4, { params: { name: highId } }),
            row('fraction_low', node('sub', 'f_low = 1 − f_high', blend.fractionLow, '', inputs), 4, { params: { name: lowId } }),
        ];
    }, SCOPE);
}

/** Oil phase to emulsifier blend (M-03): the required HLB of the oil lines, then the non-ionic pair that reaches it. */
export function runSystem(state, fallbackOf) {
    const oil = state.required.lines;
    const blend = state.blend;

    if (oil.every((line) => isBlank(line.mass) && isBlank(line.hlb.value)) && isBlank(blend.low.value) && isBlank(blend.high.value) && isBlank(blend.total.value)) {
        return EMPTY;
    }

    const ids = names(oil, fallbackOf);
    const lines = oil.map((line, index) => ({ materialId: ids[index], mass: typed(line.mass), phase: 'oil', requiredHLB: toSourced(line.hlb) }));
    const lowId = blend.lowMaterial?.label ?? 'low';
    const highId = blend.highMaterial?.label ?? 'high';
    const total = toQty(blend.total);
    const result = emulsifierSystem({
        oilLines: lines,
        low: { materialId: lowId, hlb: toSourced(blend.low) ?? { value: '' }, ionicity: blend.lowIonicity },
        high: { materialId: highId, hlb: toSourced(blend.high) ?? { value: '' }, ionicity: blend.highIonicity },
        totalMass: total,
    });

    return outcomeOf(result, (system) => {
        const oilLeaves = lines.flatMap((line) => [
            leaf(`${line.materialId} mass`, line.mass, 'g'),
            sourcedLeaf(`${line.materialId} required HLB`, line.requiredHLB, 'HLB'),
        ]);
        const required = node('div', 'Required HLB = Σ(m × rHLB) / Σm', system.requiredHLB, 'HLB', oilLeaves);
        const inputs = [required, sourcedLeaf(`${lowId} HLB`, toSourced(blend.low), 'HLB'), sourcedLeaf(`${highId} HLB`, toSourced(blend.high), 'HLB')];
        const mass = (id, quantity, fraction) => node('mul', `${id} mass = M × f`, quantity.value.toFixed(), quantity.unit, [leaf('Emulsifier total (M)', total.value.toFixed(), total.unit), node('div', 'Fraction f from the HLB mixing rule', fraction, '', inputs)]);

        return [
            row('required_hlb', required, 2),
            row('mass_high', mass(highId, system.blend.high.mass, system.blend.fractionHigh), 3, { primary: true, params: { name: highId } }),
            row('mass_low', mass(lowId, system.blend.low.mass, system.blend.fractionLow), 3, { primary: true, params: { name: lowId } }),
            row('fraction_high', node('div', 'f_high = (HLB_t − HLB_low) / (HLB_high − HLB_low)', system.blend.fractionHigh, '', inputs), 4, { params: { name: highId } }),
            row('fraction_low', node('sub', 'f_low = 1 − f_high', system.blend.fractionLow, '', inputs), 4, { params: { name: lowId } }),
        ];
    }, SCOPE);
}

export function runGriffin(state) {
    if (isBlank(state.hydrophilic) && isBlank(state.total)) {
        return EMPTY;
    }

    const result = griffinHLB(typed(state.hydrophilic), typed(state.total));

    return outcomeOf(result, ({ value }) => [
        row('griffin', node('div', 'HLB = 20 × Mh / M', value, 'HLB', [leaf('Hydrophilic part Mh', decimalOrNull(state.hydrophilic) ?? '0', 'g'), leaf('Molar mass M', decimalOrNull(state.total) ?? '0', 'g')]), 2, { primary: true }),
    ], SCOPE);
}

export function runDavies(state) {
    if (state.groups.every((group) => isBlank(group.group) && isBlank(group.h.value))) {
        return EMPTY;
    }

    const groups = state.groups.map((group, index) => ({ group: group.group || String(index + 1), h: toSourced(group.h), count: typed(group.count) }));
    const result = daviesHLB(groups);

    return outcomeOf(result, ({ value }) => [
        row('davies', node('sum', 'HLB = 7 + Σ(count × Hi) − 0.475 × n', value, 'HLB', groups.map((group) => sourcedLeaf(`${group.group} × ${group.count}`, group.h, 'HLB'))), 2, { primary: true }),
    ], SCOPE);
}

export function runHlb(state, fallbackOf) {
    return {
        required: () => runRequired(state.required, fallbackOf),
        blend: () => runBlend(state.blend),
        system: () => runSystem(state, fallbackOf),
        griffin: () => runGriffin(state.griffin),
        davies: () => runDavies(state.davies),
    }[state.mode]();
}
