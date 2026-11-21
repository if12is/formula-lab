import { describe, expect, it } from 'vitest';
import { optimize } from './optimize';
import { case2 } from './test-fixtures';

describe('J-07 sensitivity', () => {
  it('duals are the hand-computed shadow prices', async () => {
    // see optimize.test.ts case 2: y_sum 0.01, y_active 1.975, y_fn 0.305
    const out = await optimize(case2());
    expect(out.duals?.rows).toEqual([
      { id: 'sum', dual: '0.01' }, { id: 'active.min', dual: '1.975' }, { id: 'fn.amphoteric.min', dual: '0.305' },
    ]);
    // one more active point costs 1.975: 2.5 % more S1 (+2.5·0.80) in place of 2.5 % water (−2.5·0.01)
    const more = await optimize(case2({ profile: { ...case2().profile as object, constraints: { ...(case2().profile as { constraints: object }).constraints, totalActiveMinPct: '13' } } }));
    expect(Number(more.costPerKg) - Number(out.costPerKg)).toBeCloseTo(1.975, 9);
  });

  it('S1 +10 %: same formula, cost +1.8', async () => {
    // S1 88 EGP/kg: 88/0.4 = 220 per active-kg < S2's 250, so nothing changes; delta = 22.5 % · 8 EGP/kg = 1.8
    // 1.8 / 26.225 = 6.8637 % -> 6.864
    const out = await optimize(case2({ scenarios: [{ materialId: 'S1', changePct: '10' }] }));
    expect(out.sensitivity).toEqual([{
      materialId: 'S1', changePct: '10', basePriceEGPperKg: '80', scenarioPriceEGPperKg: '88', status: 'optimal',
      scenarioCostPerKg: '28.025', costDeltaPerKg: '1.8', costDeltaPct: '6.864', costDeltaIfFormulaUnchangedPerKg: '1.8',
      formulaChanged: false, formulaDelta: [],
    }]);
  });

  it('S1 +30 %: the optimizer switches to S2, and the do-nothing exposure is larger', async () => {
    // S1 104 EGP/kg: 104/0.4 = 260 > 250, so S2 alone supplies the active: S2 = 12/0.6 = 20, water 80, S1 0
    // cost = 20·1.5 + 80·0.01 = 30.8; delta = 30.8 − 26.225 = 4.575 (17.445 %); unchanged formula would cost 22.5·0.24 = 5.4 more
    const out = await optimize(case2({ scenarios: [{ materialId: 'S1', changePct: '30' }] }));
    const s = out.sensitivity[0];
    expect(s).toMatchObject({ status: 'optimal', scenarioCostPerKg: '30.8', costDeltaPerKg: '4.575', costDeltaPct: '17.445', costDeltaIfFormulaUnchangedPerKg: '5.4', formulaChanged: true });
    expect(s?.formulaDelta).toEqual([
      { materialId: 'S1', basePercentWW: '22.500', scenarioPercentWW: '0.000', deltaPercentWW: '-22.500' },
      { materialId: 'S2', basePercentWW: '5.000', scenarioPercentWW: '20.000', deltaPercentWW: '15.000' },
      { materialId: 'WATER', basePercentWW: '72.500', scenarioPercentWW: '80.000', deltaPercentWW: '7.500' },
    ]);
  });

  it('scenarios are deterministic, ordered, and do not affect the base solution', async () => {
    const a = await optimize(case2({ scenarios: [{ materialId: 'S2', changePct: '-10' }, { materialId: 'S1', changePct: '10' }] }));
    const b = await optimize(case2({ scenarios: [{ materialId: 'S1', changePct: '10' }, { materialId: 'S2', changePct: '-10' }] }));
    expect(a.outputHash).toBe(b.outputHash);
    expect(a.sensitivity.map((s) => s.materialId)).toEqual(['S1', 'S2']);
    expect(a.formula).toEqual((await optimize(case2())).formula);
  });

  it('an unknown material or invalid change is reported, not guessed', async () => {
    const out = await optimize(case2({ scenarios: [{ materialId: 'NOPE', changePct: '10' }, { materialId: 'S1', changePct: '-100' }] }));
    expect(out.sensitivity.map((s) => s.status)).toEqual(['missing', 'missing']);
    expect(out.warnings).toEqual(expect.arrayContaining(['optimizer.warning.scenario_invalid:NOPE', 'optimizer.warning.scenario_invalid:S1']));
  });
});
