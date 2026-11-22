import { describe, expect, it } from 'vitest';
import { isAdditionTemp, phaseSummary, validatePhases, type PhasedLine } from './phases';

const line = (materialId: string, percentWW: string, phase?: string | null, additionTempC?: string | null): PhasedLine => ({ materialId, percentWW, phase, additionTempC });

describe('phaseSummary', () => {
  it('sums each phase exactly: water 60.3 + 9.7 = 70, oil 20.1 + 4.9 = 25, cool-down 5 => 100', () => {
    const s = phaseSummary([
      line('w1', '60.3', 'water'),
      line('o1', '20.1', 'oil'),
      line('w2', '9.7', 'water'),
      line('o2', '4.9', 'oil'),
      line('c1', '5', 'cool_down'),
    ]);
    expect(s.totals).toEqual([
      { phase: 'water', sum: '70', lines: 2 },
      { phase: 'oil', sum: '25', lines: 2 },
      { phase: 'cool_down', sum: '5', lines: 1 },
    ]);
    expect(s.sum).toBe('100');
    expect(s.deviation).toBe('0');
    expect(s.withinTolerance).toBe(true);
    expect(s.unphased).toEqual({ sum: '0', lines: 0 });
  });
  it('keeps a decimal sum exact where floats would drift: 0.1 + 0.2 in one phase is 0.3', () => {
    const s = phaseSummary([line('a', '0.1', 'oil'), line('b', '0.2', 'oil')]);
    expect(s.totals[0]?.sum).toBe('0.3');
  });
  it('lines without a phase (or with an unknown one) go to `unphased`', () => {
    const s = phaseSummary([line('a', '40', 'water'), line('b', '35'), line('c', '25', 'steam')]);
    expect(s.unphased).toEqual({ sum: '60', lines: 2 });
    expect(s.sum).toBe('100');
  });
});

describe('validatePhases', () => {
  it('a formula with no phase at all is valid (phases are optional)', () => {
    const r = validatePhases([line('a', '60'), line('b', '30')]);
    expect(r.ok).toBe(true);
    expect(r.ok && r.value.totals).toEqual([]);
  });
  it('a fully phased formula that sums to 100 is valid', () => {
    // 70 + 25 + 5 = 100
    const r = validatePhases([line('w', '70', 'water', '75'), line('o', '25', 'oil', '75'), line('c', '5', 'cool_down', '40')]);
    expect(r.ok).toBe(true);
  });
  it('phase sums that miss 100 are rejected with the sum and deviation: 55 + 40 = 95', () => {
    const r = validatePhases([line('w', '55', 'water'), line('o', '40', 'oil')]);
    expect(r.ok).toBe(false);
    expect(!r.ok && r.errors).toEqual([
      { code: 'PHASE_SUM_NOT_100', messageKey: 'formula.phase_sum_not_100', detail: { sum: '95', deviation: '-5' } },
    ]);
  });
  it('tolerance is 0.001: 99.9995 passes, 99.998 does not', () => {
    expect(validatePhases([line('w', '70', 'water'), line('o', '29.9995', 'oil')]).ok).toBe(true);
    expect(validatePhases([line('w', '70', 'water'), line('o', '29.998', 'oil')]).ok).toBe(false);
  });
  it('once one line has a phase, every other line needs one', () => {
    const r = validatePhases([line('w', '70', 'water'), line('o', '30')]);
    expect(!r.ok && r.errors.map((e) => [e.code, e.materialId])).toEqual([['PHASE_MISSING', 'o']]);
  });
  it('an unknown phase code is rejected by name', () => {
    const r = validatePhases([line('w', '70', 'A'), line('o', '30', 'oil')]);
    expect(!r.ok && r.errors[0]).toMatchObject({ code: 'PHASE_UNKNOWN', materialId: 'w', detail: { phase: 'A' } });
  });
  it('a temperature needs a phase and must be a non-negative decimal; all problems reported together', () => {
    const r = validatePhases([line('a', '50', 'water', 'hot'), line('b', '50', undefined, '70'), line('c', '0', 'oil', '-5')]);
    expect(!r.ok && r.errors.map((e) => `${e.code}:${e.materialId}`)).toEqual([
      'PHASE_TEMP_INVALID:a',
      'PHASE_MISSING:b',
      'PHASE_TEMP_WITHOUT_PHASE:b',
      'PHASE_TEMP_INVALID:c',
    ]);
    const lone = validatePhases([line('a', '100', undefined, '70')]);
    expect(!lone.ok && lone.errors[0]?.code).toBe('PHASE_TEMP_WITHOUT_PHASE');
  });
  it('isAdditionTemp', () => {
    expect(['0', '75', '72.5'].every(isAdditionTemp)).toBe(true);
    expect(['-1', 'x', '', '1e2'].some(isAdditionTemp)).toBe(false);
  });
});
