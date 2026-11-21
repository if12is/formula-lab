import { describe, expect, it } from 'vitest';
import { formulaSaltForViscosity, formulaViscosityAtSalt, type FormulaSaltCurve } from './formula-salt-curve';

const pt = (saltPct: string, v: string, sourceId: string, confidence: 'L1_coa_batch' | 'L6_community' = 'L1_coa_batch') => ({
  saltPct,
  viscosity_mPas: v,
  sourceId,
  confidence,
});

// Fictional lab points of formula A, deliberately unsorted: salt 0 -> 100, 1 -> 1100, 2 -> 5000 mPa.s.
const curveA: FormulaSaltCurve = { formulaId: 'A', points: [pt('2', '5000', 'lab_measurement:3'), pt('0', '100', 'lab_measurement:1'), pt('1', '1100', 'lab_measurement:2')] };
// Formula B has its OWN curve: salt 0 -> 200, 2 -> 1000.
const curveB: FormulaSaltCurve = { formulaId: 'B', points: [pt('0', '200', 'lab_measurement:8'), pt('2', '1000', 'lab_measurement:9')] };

describe('formulaViscosityAtSalt', () => {
  it('between two measured points: at 0.5 salt => 100 + 0.5 x (1100 - 100) = 600', () => {
    const r = formulaViscosityAtSalt(curveA, '0.5');
    expect(r.ok).toBe(true);
    if (r.ok) {
      expect(r.value.viscosity.value).toBe('600');
      expect(r.value.viscosity.unit).toBe('mPa·s');
      expect(r.value.bracket.from.sourceId).toBe('lab_measurement:1');
      expect(r.value.bracket.to.sourceId).toBe('lab_measurement:2');
      // the trace keeps the salt asked for and both measured neighbours with source and confidence
      const leaves = r.value.viscosity.trace.inputs;
      expect(leaves.map((l) => l.sourceId)).toEqual([undefined, 'lab_measurement:1', 'lab_measurement:2']);
      expect(leaves[1]?.confidence).toBe('L1_coa_batch');
      expect(leaves[0]?.value).toBe('0.5');
    }
  });

  it('second segment: at 1.5 salt => 1100 + 0.5 x (5000 - 1100) = 3050', () => {
    const r = formulaViscosityAtSalt(curveA, '1.5');
    expect(r.ok && r.value.viscosity.value).toBe('3050');
  });

  it('a measured salt % returns the measurement itself (one leaf): at 1 => 1100', () => {
    const r = formulaViscosityAtSalt(curveA, '1');
    expect(r.ok && r.value.viscosity.value).toBe('1100');
    expect(r.ok && r.value.viscosity.trace.inputs).toHaveLength(2); // the salt input + the single measured point
  });

  it("uses the formula's own curve: B at 1 salt => 200 + 0.5 x (1000 - 200) = 600, A at 1 is 1100", () => {
    const b = formulaViscosityAtSalt(curveB, '1');
    const a = formulaViscosityAtSalt(curveA, '1');
    expect(b.ok && b.value.viscosity.value).toBe('600');
    expect(a.ok && a.value.viscosity.value).toBe('1100');
  });

  it('no curve, no points, one point or outside the measured range => missing (never a number)', () => {
    for (const c of [undefined, { formulaId: 'X', points: [] }]) {
      const r = formulaViscosityAtSalt(c, '1');
      expect(!r.ok && r.missing[0]).toEqual({ property: 'salt_curve', reason: 'salt_curve.formula_curve_missing' });
    }
    const one = formulaViscosityAtSalt({ formulaId: 'X', points: [pt('1', '5', 'm')] }, '1');
    expect(!one.ok && one.missing[0]?.reason).toBe('need_at_least_2_points');
    const out = formulaViscosityAtSalt(curveA, '2.5');
    expect(!out.ok && out.missing[0]?.reason).toContain('out_of_measured_range');
  });

  it('is independent of the order of the points', () => {
    const a = formulaViscosityAtSalt(curveA, '1.25');
    const b = formulaViscosityAtSalt({ formulaId: 'A', points: [...curveA.points].reverse() }, '1.25');
    expect(a).toEqual(b);
    expect(a.ok && a.value.viscosity.value).toBe('2075'); // 1100 + 0.25 x 3900
  });
});

describe('formulaSaltForViscosity', () => {
  it('inverse: 600 => 0.5 and 3050 => 1.5 (hand: (600 - 100) / 1000 and 1 + (3050 - 1100) / 3900)', () => {
    const a = formulaSaltForViscosity(curveA, '600');
    expect(a.ok && a.value.solutions.map((s) => s.saltPct.value)).toEqual(['0.5']);
    const b = formulaSaltForViscosity(curveA, '3050');
    expect(b.ok && b.value.solutions.map((s) => s.saltPct.value)).toEqual(['1.5']);
    if (a.ok) {
      expect(a.value.solutions[0]?.saltPct.trace.inputs.map((n) => n.sourceId)).toEqual([undefined, 'lab_measurement:1', 'lab_measurement:2']);
    }
  });

  it("the other formula's curve answers differently: B at 600 => 1 (200 + 800 x s/2 = 600)", () => {
    const r = formulaSaltForViscosity(curveB, '600');
    expect(r.ok && r.value.solutions.map((s) => s.saltPct.value)).toEqual(['1']);
  });

  it('a peak curve gives every salt level, with the engine warning', () => {
    const peak: FormulaSaltCurve = { formulaId: 'P', points: [pt('0', '100', 'p1'), pt('1', '900', 'p2'), pt('2', '100', 'p3')] };
    const r = formulaSaltForViscosity(peak, '500');
    expect(r.ok && r.value.solutions.map((s) => s.saltPct.value)).toEqual(['0.5', '1.5']);
    expect(r.warnings).toContain('multiple_salt_levels_give_target_viscosity');
  });

  it('missing curve and a viscosity off the curve are missing', () => {
    const none = formulaSaltForViscosity(undefined, '600');
    expect(!none.ok && none.missing[0]?.reason).toBe('salt_curve.formula_curve_missing');
    expect(formulaSaltForViscosity(curveA, '99').ok).toBe(false);
  });
});
