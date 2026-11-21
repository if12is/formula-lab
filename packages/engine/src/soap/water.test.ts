import { describe, expect, it } from 'vitest';
import { inputLeaf } from '../trace/trace';
import { waterForLye, type WaterSpec } from './water';

// Fixed example: lye 100 g, oils 500 g.
const lye = inputLeaf('Lye (g)', '100', 'g');
const oils = inputLeaf('Oils (g)', '500', 'g');
const plan = (spec: WaterSpec) => {
  const r = waterForLye(lye, oils, spec);
  if (!r.ok) {
    throw new Error('expected ok');
  }
  const v = r.value;
  return [v.waterMass.value, v.ratioWaterToLye.value, v.lyeConcentrationPct.value.slice(0, 8), v.waterPctOfOils.value, v.solutionMass.value];
};

describe('waterForLye: one method given, the other two derived', () => {
  it('ratio 2: water 200; conc 100/300 = 33.3333 %; 200/500 = 40 % of oils', () => {
    expect(plan({ method: 'ratio', value: '2' })).toEqual(['200', '2', '33.33333', '40', '300']);
  });

  it('lye concentration 25 %: water = 100 × 75 / 25 = 300; ratio 3; 60 % of oils', () => {
    expect(plan({ method: 'lyeConcentration', value: '25' })).toEqual(['300', '3', '25', '60', '400']);
  });

  it('% of oils 40: water = 500 × 0.40 = 200; ratio 2; conc 33.33 %', () => {
    expect(plan({ method: 'pctOfOils', value: '40' })).toEqual(['200', '2', '33.33333', '40', '300']);
  });

  it('the three methods round-trip to the same water mass', () => {
    const a = plan({ method: 'ratio', value: '3' });
    const b = plan({ method: 'lyeConcentration', value: '25' });
    const c = plan({ method: 'pctOfOils', value: '60' });
    expect([a[0], b[0], c[0]]).toEqual(['300', '300', '300']);
  });

  it('the given method is echoed exactly and traced', () => {
    const r = waterForLye(lye, oils, { method: 'lyeConcentration', value: '33.3' });
    if (!r.ok) {
      throw new Error('expected ok');
    }
    expect(r.value.lyeConcentrationPct.value).toBe('33.3');
    expect(r.value.waterMass.trace.op).toBe('water');
  });

  it('rejects non-positive values, concentration >= 100 and non-numbers', () => {
    const bad = (spec: WaterSpec) => {
      const r = waterForLye(lye, oils, spec);
      return !r.ok && r.missing[0]?.reason;
    };
    expect(bad({ method: 'ratio', value: '0' })).toBe('input.out_of_range');
    expect(bad({ method: 'pctOfOils', value: '-3' })).toBe('input.out_of_range');
    expect(bad({ method: 'lyeConcentration', value: '0' })).toBe('input.out_of_range');
    expect(bad({ method: 'lyeConcentration', value: '100' })).toBe('water.concentration_must_be_below_100');
    expect(bad({ method: 'lyeConcentration', value: '101' })).toBe('input.out_of_range');
    expect(bad({ method: 'ratio', value: 'two' })).toBe('input.invalid_number');
  });
});
