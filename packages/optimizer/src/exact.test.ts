import { beforeAll, describe, expect, it } from 'vitest';
import { buildModel, costObjective, type BuildMaterial } from './build';
import { solveExact } from './exact';
import { loadHighs } from './highs';
import { optimize } from './optimize';
import { CASE1_MATERIALS, case2, profile } from './test-fixtures';
import { dec } from './util';

beforeAll(async () => {
  await loadHighs();
});

const bm = (materialId: string, price: string, active: string): BuildMaterial => ({ materialId, pricePerKg: dec(price), activePct: dec(active), functions: [] });

describe('exact vertex recovery from the HiGHS basis', () => {
  it('returns 100/3 to full Decimal precision although the solver prints 33.3333', () => {
    // min 0.55 B + 0.01 W, B + W = 100, 0.3 B >= 10  ->  B = 100/3, W = 200/3
    const model = buildModel({ profile: profile({ totalActiveMinPct: '10' }), materials: [bm('B', '55', '30'), bm('W', '1', '0')], qsMaterialId: 'W', targetCostCapPerKg: null });
    const r = solveExact(model, costObjective(model));
    expect(r.exact).toBe(true);
    expect(r.sol.primal.get('x0000')).toBeCloseTo(33.3333, 4);
    expect(r.values[0]?.minus(dec('100').div(3)).abs().lt('1e-30')).toBe(true);
    expect(r.values[1]?.minus(dec('200').div(3)).abs().lt('1e-30')).toBe(true);
  });

  it('optimize() never needs the printed-double fallback on the hand cases', async () => {
    for (const input of [case2(), { profile: profile({ totalActiveMinPct: '10' }), materials: CASE1_MATERIALS, qsMaterialId: 'WATER' }]) {
      const out = await optimize(input);
      expect(out.warnings).not.toContain('optimizer.warning.solver_precision_fallback');
    }
  });

  it('without a declared q.s. material the formula still sums to exactly 100', async () => {
    const out = await optimize({ profile: profile({ totalActiveMinPct: '10' }), materials: [{ ...CASE1_MATERIALS[0]! }, { ...CASE1_MATERIALS[1]! }] });
    expect(out.status).toBe('optimal');
    expect(out.formula.map((l) => [l.materialId, l.percentWW])).toEqual([['WATER', '80.000'], ['AAA', '20.000']]);
    const sum = out.formula.reduce((a, l) => a.plus(l.percentWW), dec('0'));
    expect(sum.toString()).toBe('100');
  });

  it('without q.s. and an off-grid vertex, largest-remainder rounding plus the margin attempt still verifies', async () => {
    const out = await optimize({ profile: profile({ totalActiveMinPct: '10' }), materials: [{ ...CASE1_MATERIALS[0]!, materialId: 'W' }, { ...CASE1_MATERIALS[1]!, materialId: 'B', activeMatterPct: { ...CASE1_MATERIALS[1]!.activeMatterPct!, value: '30' } }] });
    expect(out.status).toBe('optimal');
    const sum = out.formula.reduce((a, l) => a.plus(l.percentWW), dec('0'));
    expect(sum.toString()).toBe('100');
    const b = out.formula.find((l) => l.materialId === 'B');
    expect(dec(b?.percentWW ?? '0').times('0.3').gte(10)).toBe(true);
  });
});
