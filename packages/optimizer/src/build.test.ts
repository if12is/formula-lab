import { describe, expect, it } from 'vitest';
import { buildModel, renderLp, tightenForRounding, costObjective, type BuildMaterial } from './build';
import { roundSolution, verify } from './round';
import { profile, seededShuffle } from './test-fixtures';
import { dec } from './util';

const bm = (materialId: string, price: string, active: string, ...functions: string[]): BuildMaterial => ({ materialId, pricePerKg: dec(price), activePct: dec(active), functions });

describe('J-04 LP builder', () => {
  it('emits exact CPLEX LP text with deterministic names', () => {
    const model = buildModel({ profile: profile({ totalActiveMinPct: '10' }), materials: [bm('WATER', '1', '0'), bm('AAA', '100', '50')], qsMaterialId: 'WATER', targetCostCapPerKg: null });
    expect(renderLp(model, { objective: costObjective(model) })).toBe(
      ['Minimize', ' obj: 1 x0000 + 0.01 x0001', 'Subject To', ' r00: 1 x0000 + 1 x0001 = 100', ' r01: 0.5 x0000 >= 10', 'Bounds', ' 0 <= x0000 <= 100', ' 0 <= x0001 <= 100', 'End', ''].join('\n'),
    );
  });

  it('is independent of input order (same LP text for every permutation)', () => {
    const mats = [bm('M1', '10.5', '20', 'a'), bm('M2', '7', '0', 'b'), bm('M3', '3.25', '35', 'a'), bm('M4', '1', '0')];
    const p = profile({ totalActiveMinPct: '8', functionBounds: [{ function: 'b', min: null, max: '20' }, { function: 'a', min: '2', max: '30' }], maxCostEGPperKg: '9', materialBounds: [{ materialId: 'M4', min: '10', max: null }] });
    const text = (m: BuildMaterial[]): string => {
      const model = buildModel({ profile: p, materials: m, qsMaterialId: 'M4', targetCostCapPerKg: null });
      return renderLp(model, { objective: costObjective(model) });
    };
    const base = text(mats);
    for (let seed = 1; seed <= 20; seed += 1) {
      expect(text(seededShuffle(mats, seed))).toBe(base);
    }
    // fixed row order: active, function bounds by name, maxCost, material bounds
    expect(base).toContain('r01: ');
    expect(base.indexOf('r02:')).toBeLessThan(base.indexOf('r04:'));
  });

  it('forbidden materials get no variable; function groups, max cost and bounds become rows', () => {
    const model = buildModel({
      profile: profile({ forbiddenMaterials: ['BAD'], functionBounds: [{ function: 'f', min: '1', max: null }], maxCostEGPperKg: '50', materialBounds: [{ materialId: 'OK', min: '2', max: '9' }], requiredMaterials: ['OK'] }),
      materials: [bm('BAD', '1', '0', 'f'), bm('OK', '4', '10', 'f'), bm('W', '1', '0')],
      qsMaterialId: 'W',
      targetCostCapPerKg: dec('40'),
    });
    expect(model.variables.map((v) => v.materialId)).toEqual(['OK', 'W']);
    expect(model.rows.map((r) => r.id)).toEqual(['sum', 'fn.f.min', 'maxCost', 'targetPrice', 'mat.OK.min', 'mat.OK.max']);
    expect(model.rows.find((r) => r.id === 'mat.OK.min')?.message?.params.required).toBe('true');
  });

  it('tightening: grid-aligns single-variable bounds, and the safety margin follows the proven rounding bound', () => {
    const model = buildModel({ profile: profile({ totalActiveMinPct: '10', materialBounds: [{ materialId: 'A', min: '1.2345', max: null }] }), materials: [bm('A', '100', '50'), bm('W', '1', '0')], qsMaterialId: 'W', targetCostCapPerKg: null });
    const aligned = tightenForRounding(model, false);
    expect(aligned.rows.find((r) => r.id === 'mat.A.min')?.rhs.toString()).toBe('1.235');
    const safe = tightenForRounding(model, true);
    // active row: margin = 0.0005 · |0.5 − 0| = 0.00025 ; A bound: 0.0005 · |1 − 0| = 0.0005
    expect(safe.rows.find((r) => r.id === 'active.min')?.rhs.toString()).toBe('10.00025');
    expect(safe.rows.find((r) => r.id === 'mat.A.min')?.rhs.toString()).toBe('1.2355');
    expect(safe.variables[1]?.lower.toString()).toBe('0.0005');
  });

  it('rounds to 0.001 % and re-balances through q.s. so the sum is exactly 100', () => {
    const model = buildModel({ profile: profile(), materials: [bm('A', '1', '0'), bm('B', '1', '0'), bm('W', '1', '0')], qsMaterialId: 'W', targetCostCapPerKg: null });
    const r = roundSolution(model, [dec('33.3333333'), dec('33.3333333'), dec('33.3333334')]);
    expect(r.map(String)).toEqual(['33.333', '33.333', '33.334']);
    expect(r.reduce((a, b) => a.plus(b)).toString()).toBe('100');
  });

  it('without q.s. largest-remainder rounding restores the sum', () => {
    const model = buildModel({ profile: profile(), materials: [bm('A', '1', '0'), bm('B', '1', '0'), bm('C', '1', '0')], qsMaterialId: null, targetCostCapPerKg: null });
    const r = roundSolution(model, [dec('33.3334'), dec('33.3333'), dec('33.3333')]);
    expect(r.reduce((a, b) => a.plus(b)).toString()).toBe('100');
    expect(r.map(String)).toEqual(['33.334', '33.333', '33.333']);
  });

  it('exact verification flags any violation (never silently accepted)', () => {
    const model = buildModel({ profile: profile({ totalActiveMinPct: '10' }), materials: [bm('B', '55', '30'), bm('W', '1', '0')], qsMaterialId: 'W', targetCostCapPerKg: null });
    const bad = verify(model, [dec('33.333'), dec('66.667')]); // active 9.9999 < 10
    expect(bad.ok).toBe(false);
    expect(bad.violations.map((v) => [v.id, v.slack.toString()])).toEqual([['active.min', '-0.0001']]);
    expect(verify(model, [dec('33.334'), dec('66.666')]).ok).toBe(true);
    expect(verify(model, [dec('-1'), dec('101')]).violations.map((v) => v.id)).toContain('nonneg.B');
    expect(verify(model, [dec('33.334'), dec('66.667')]).violations.map((v) => v.id)).toContain('sum');
  });
});
