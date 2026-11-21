/** Active matter and dilution (F-04): the active % of a blend, the raw grade to weigh for a target, the water to add to a batch. */
import { activeMatterOfBlend, rawMassForTarget, waterToReachTarget } from '@fl/engine';
import { EMPTY, isBlank, outcomeOf, row, toQty, typed } from './common';
import { blankSourced, toSourced } from './sourced';

export const MODES = ['blend', 'target', 'water'];

/** `contributes` false is for water and other components that carry no active matter: the engine then wants 0 with a source. */
export const blankBlendLine = (key) => ({ key, material: null, mass: { value: '', unit: 'g' }, contributes: true, active: blankSourced('%w/w') });

export const blankDilution = (firstKey) => ({
    mode: MODES[0],
    blend: { lines: [blankBlendLine(firstKey)] },
    target: { batch: { value: '', unit: 'g' }, targetPct: '', raw: blankSourced('%w/w'), rawMaterial: null },
    water: { existing: { value: '', unit: 'g' }, current: blankSourced('%w/w'), targetPct: '' },
});

/** Unique engine ids for the blend's lines: the material's name, or the line number. */
export function lineNames(lines, fallbackOf) {
    const seen = new Map();

    return lines.map((line, index) => {
        const base = line.material?.label || fallbackOf(index + 1);
        const count = (seen.get(base) ?? 0) + 1;
        seen.set(base, count);

        return count === 1 ? base : `${base} (${count})`;
    });
}

export function runBlend(state, fallbackOf) {
    if (state.lines.every((line) => isBlank(line.mass.value) && isBlank(line.active.value))) {
        return EMPTY;
    }

    const names = lineNames(state.lines, fallbackOf);
    const result = activeMatterOfBlend({
        lines: state.lines.map((line, index) => ({
            materialId: names[index],
            mass: toQty(line.mass),
            contributesActive: line.contributes,
            activeMatter: toSourced(line.active),
        })),
        policy: {},
    });

    return outcomeOf(result, (blend) => [
        row('active_pct', blend.activeMatterPct, 3, { primary: true }),
        row('active_mass', blend.activeMass, 3),
        row('total_mass', blend.totalMass, 3),
    ]);
}

export function runTarget(state) {
    if (isBlank(state.batch.value) && isBlank(state.targetPct) && isBlank(state.raw.value)) {
        return EMPTY;
    }

    const result = rawMassForTarget({
        batchMass: toQty(state.batch),
        targetActivePct: typed(state.targetPct),
        rawActive: toSourced(state.raw),
        ...(state.rawMaterial ? { rawMaterialId: state.rawMaterial.label } : {}),
        policy: {},
    });

    return outcomeOf(result, (dilution) => [
        row('raw_mass', dilution.rawMass, 3, { primary: true }),
        row('diluent_mass', dilution.diluentMass, 3),
    ]);
}

export function runWater(state) {
    if (isBlank(state.existing.value) && isBlank(state.current.value) && isBlank(state.targetPct)) {
        return EMPTY;
    }

    const result = waterToReachTarget({
        existingMass: toQty(state.existing),
        currentActive: toSourced(state.current),
        targetActivePct: typed(state.targetPct),
        policy: {},
    });

    return outcomeOf(result, (plan) => [
        row('water_mass', plan.waterMass, 3, { primary: true }),
        row('final_mass', plan.finalMass, 3),
    ]);
}

export function runDilution(state, fallbackOf) {
    return { blend: () => runBlend(state.blend, fallbackOf), target: () => runTarget(state.target), water: () => runWater(state.water) }[state.mode]();
}
