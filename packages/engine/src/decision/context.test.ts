import { describe, expect, it } from 'vitest';
import { countString, coverageGap, evaluateSpecs } from './context';

describe('coverageGap / countString', () => {
  it('returns the sorted, de-duplicated required items that are absent', () => {
    expect(coverageGap(['viscosity', 'ph', 'ph', 'density'], ['density'])).toEqual(['ph', 'viscosity']);
    expect(coverageGap([], ['x'])).toEqual([]);
    expect(countString(['a', 'b'])).toBe('2');
    expect(countString([])).toBe('0');
  });
});

describe('evaluateSpecs', () => {
  const limits = [
    { property: 'ph', min: '5.0', max: '6.5' },
    { property: 'density', min: '0.98', max: null },
    { property: 'viscosity', min: null, max: '5000' },
  ];

  it('accepts values on the bounds and inside', () => {
    expect(evaluateSpecs(limits, { ph: '5.00', density: '0.98', viscosity: '5000' })).toEqual({ outOfSpec: [], notEvaluated: [] });
  });

  it('flags values below min and above max, exactly (no float)', () => {
    expect(evaluateSpecs(limits, { ph: '4.9999999999999999', density: '0.9799', viscosity: '5000.0001' })).toEqual({
      outOfSpec: ['density', 'ph', 'viscosity'],
      notEvaluated: [],
    });
    expect(evaluateSpecs(limits, { ph: '6.51', density: '2', viscosity: '1' }).outOfSpec).toEqual(['ph']);
  });

  it('never passes a missing result, an undefined limit, or a malformed value', () => {
    const r = evaluateSpecs(
      [...limits, { property: 'color', min: null, max: null }, { property: 'odor', min: 'x', max: null }],
      { ph: 'abc', density: '1', color: 'pale', odor: '1' },
    );
    expect(r.outOfSpec).toEqual([]);
    expect(r.notEvaluated).toEqual(['color', 'odor', 'ph', 'viscosity']);
  });
});
