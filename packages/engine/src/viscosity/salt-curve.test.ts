import { describe, expect, it } from 'vitest';
import type { Confidence } from '../contract';
import { saltForViscosity, viscosityAt, type SaltPoint } from './salt-curve';

const p = (saltPct: string, v: string, sourceId = 's', confidence: Confidence = 'L1_coa_batch'): SaltPoint => ({
  saltPct,
  viscosity_mPas: v,
  sourceId,
  confidence,
});

// Fictional measurements, deliberately unsorted.
const curve = [p('2', '5000', 'm3'), p('0', '100', 'm1'), p('1', '1100', 'm2', 'L6_community')];

describe('viscosityAt', () => {
  it('sorts points and interpolates linearly: at 0.5 => 100 + 0.5*1000 = 600', () => {
    const r = viscosityAt(curve, '0.5');
    expect(r.ok).toBe(true);
    if (r.ok) {
      expect(r.value.viscosity_mPas).toBe('600');
      expect(r.value.bracket.from.sourceId).toBe('m1');
      expect(r.value.bracket.to.sourceId).toBe('m2');
      expect(r.value.confidence).toBe('L6_community'); // worst of the two neighbours
    }
  });
  it('second segment: 1.5 => 1100 + 0.5*3900 = 3050', () => {
    const r = viscosityAt(curve, '1.5');
    expect(r.ok && r.value.viscosity_mPas).toBe('3050');
  });
  it('exact measured point returns the measurement itself', () => {
    const r = viscosityAt(curve, '1');
    expect(r.ok && r.value.viscosity_mPas).toBe('1100');
    expect(r.ok && r.value.bracket.from.sourceId).toBe('m2');
    expect(r.ok && r.value.bracket.to.sourceId).toBe('m2');
    const end = viscosityAt(curve, '2');
    expect(end.ok && end.value.viscosity_mPas).toBe('5000');
  });
  it('no extrapolation: outside range => out_of_measured_range', () => {
    for (const x of ['2.01', '3']) {
      const r = viscosityAt(curve, x);
      expect(!r.ok && r.missing[0]?.reason).toContain('out_of_measured_range');
    }
  });
  it('needs >= 2 points', () => {
    const r = viscosityAt([p('1', '1')], '1');
    expect(!r.ok && r.missing[0]?.reason).toBe('need_at_least_2_points');
  });
  it('duplicate salt % rejected (also when written differently)', () => {
    const r = viscosityAt([p('1', '1'), p('1.0', '2'), p('2', '3')], '1.5');
    expect(!r.ok && r.missing[0]?.reason).toContain('duplicate_salt_pct');
  });
  it('invalid points / inputs are missing, not thrown', () => {
    expect(viscosityAt([p('x', '1'), p('1', '-2')], '1').ok).toBe(false);
    expect(viscosityAt(curve, 'abc').ok).toBe(false);
    expect(viscosityAt(curve, '-1').ok).toBe(false);
  });
  it('is independent of input order', () => {
    const a = viscosityAt(curve, '1.25');
    const b = viscosityAt([...curve].reverse(), '1.25');
    expect(a).toEqual(b);
  });
  it('worst confidence picks the second neighbour when it is worse', () => {
    const r = viscosityAt([p('0', '1', 'a', 'L6_community'), p('1', '2', 'b', 'L2_supplier_tds')], '0.5');
    expect(r.ok && r.value.confidence).toBe('L6_community');
  });
});

describe('saltForViscosity', () => {
  it('monotonic: 600 => 0.5, 3050 => 1.5', () => {
    const a = saltForViscosity(curve, '600');
    expect(a.ok && a.value.solutions.map((s) => s.saltPct)).toEqual(['0.5']);
    expect(a.ok && a.value.monotonic).toBe(true);
    const b = saltForViscosity(curve, '3050');
    expect(b.ok && b.value.solutions.map((s) => s.saltPct)).toEqual(['1.5']);
  });
  it('target at a shared knot is not duplicated; target at an end is exact', () => {
    const k = saltForViscosity(curve, '1100');
    expect(k.ok && k.value.solutions.map((s) => s.saltPct)).toEqual(['1']);
    const e = saltForViscosity(curve, '100');
    expect(e.ok && e.value.solutions.map((s) => s.saltPct)).toEqual(['0']);
    const f = saltForViscosity(curve, '5000');
    expect(f.ok && f.value.solutions.map((s) => s.saltPct)).toEqual(['2']);
  });
  it('outside measured viscosity span => missing', () => {
    expect(saltForViscosity(curve, '99').ok).toBe(false);
    expect(saltForViscosity(curve, '5001').ok).toBe(false);
  });
  it('non-monotonic (rises to peak then falls): all solutions, ascending, with warning', () => {
    // 0 -> 100, 1 -> 900 (peak), 2 -> 100. Target 500: x=0.5 (rising) and x=1.5 (falling).
    const peak = [p('2', '100'), p('0', '100'), p('1', '900')];
    const r = saltForViscosity(peak, '500');
    expect(r.ok).toBe(true);
    if (r.ok) {
      expect(r.value.monotonic).toBe(false);
      expect(r.value.solutions.map((s) => s.saltPct)).toEqual(['0.5', '1.5']);
      expect(r.warnings).toContain('multiple_salt_levels_give_target_viscosity');
    }
  });
  it('falling segment inverts correctly: target 300 on 900->100 over [1,2] => 1.75', () => {
    const r = saltForViscosity([p('0', '100'), p('1', '900'), p('2', '100')], '300');
    expect(r.ok && r.value.solutions.map((s) => s.saltPct)).toEqual(['0.25', '1.75']);
  });
  it('flat segment at the target returns an interval', () => {
    const r = saltForViscosity([p('0', '100'), p('1', '500'), p('2', '500'), p('3', '900')], '500');
    expect(r.ok).toBe(true);
    if (r.ok) {
      // knots 1 and 2 are inside the flat interval, so only the interval is reported
      expect(r.value.solutions).toHaveLength(1);
      expect(r.value.solutions[0]).toMatchObject({ saltPct: '1', saltPctTo: '2' });
    }
  });
  it('flat segment not at target is skipped', () => {
    const r = saltForViscosity([p('0', '100'), p('1', '100'), p('2', '300')], '200');
    expect(r.ok && r.value.solutions.map((s) => s.saltPct)).toEqual(['1.5']);
  });
  it('propagates curve validation and bad targets', () => {
    expect(saltForViscosity([p('1', '1')], '1').ok).toBe(false);
    expect(saltForViscosity(curve, 'x').ok).toBe(false);
    expect(saltForViscosity(curve, '-5').ok).toBe(false);
  });
});
