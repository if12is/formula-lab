import { describe, expect, it } from 'vitest';
import type { SourcedValue } from '../contract';
import { inputLeaf } from '../trace/trace';
import { compareExpectedMeasured } from './compare';

const expectedOf = (v: string) => inputLeaf('Computed active matter', v, '%w/w');
const measuredOf = (v: string): SourcedValue => ({ value: v, unit: '%w/w', sourceId: 'lab_measurement:5', confidence: 'L1_coa_batch', validFrom: '2026-11-12' });

describe('compareExpectedMeasured', () => {
  it('measured below expected: 14.4 - 15 = -0.6; -0.6 / 15 x 100 = -4 %', () => {
    const r = compareExpectedMeasured('active_matter', expectedOf('15'), measuredOf('14.4'));
    expect(r.ok).toBe(true);
    if (r.ok) {
      expect(r.value.difference.value).toBe('-0.6');
      expect(r.value.difference.unit).toBe('%w/w');
      expect(r.value.relativePct?.value).toBe('-4');
      expect(r.value.relativePct?.unit).toBe('%');
      expect(r.value.measured.trace.sourceId).toBe('lab_measurement:5');
    }
  });

  it('measured above expected: 13 - 12.5 = 0.5; 0.5 / 12.5 x 100 = 4 %', () => {
    const r = compareExpectedMeasured('active_matter', expectedOf('12.5'), measuredOf('13'));
    expect(r.ok && r.value.difference.value).toBe('0.5');
    expect(r.ok && r.value.relativePct?.value).toBe('4');
  });

  it('equal: 8 - 8 = 0 and 0 %', () => {
    const r = compareExpectedMeasured('active_matter', expectedOf('8'), measuredOf('8.0'));
    expect(r.ok && r.value.difference.value).toBe('0');
    expect(r.ok && r.value.relativePct?.value).toBe('0');
  });

  it('exact decimals, no float drift: 0.3 - 0.1 = 0.2; 0.2 / 0.1 x 100 = 200 %', () => {
    const r = compareExpectedMeasured('active_matter', expectedOf('0.1'), measuredOf('0.3'));
    expect(r.ok && r.value.difference.value).toBe('0.2');
    expect(r.ok && r.value.relativePct?.value).toBe('200');
  });

  it('either side missing => missing; both missing lists both', () => {
    const noExpected = compareExpectedMeasured('active_matter', undefined, measuredOf('1'));
    expect(!noExpected.ok && noExpected.missing.map((m) => m.reason)).toEqual(['compare.expected_missing']);
    const noMeasured = compareExpectedMeasured('active_matter', expectedOf('1'), undefined);
    expect(!noMeasured.ok && noMeasured.missing.map((m) => m.reason)).toEqual(['compare.measured_missing']);
    const none = compareExpectedMeasured('active_matter', undefined, undefined);
    expect(!none.ok && none.missing.map((m) => m.reason)).toEqual(['compare.expected_missing', 'compare.measured_missing']);
  });

  it('unit mismatch and a non-decimal measured value are missing', () => {
    const unit = compareExpectedMeasured('active_matter', expectedOf('5'), { ...measuredOf('5'), unit: '%v/v' });
    expect(!unit.ok && unit.missing[0]?.reason).toContain('compare.unit_mismatch');
    const bad = compareExpectedMeasured('active_matter', expectedOf('5'), measuredOf('abc'));
    expect(!bad.ok && bad.missing[0]?.reason).toBe('compare.invalid_measured_value');
  });

  it('an expected value of zero keeps the absolute difference and has no relative one', () => {
    const r = compareExpectedMeasured('active_matter', expectedOf('0'), measuredOf('0.4'));
    expect(r.ok && r.value.difference.value).toBe('0.4');
    expect(r.ok && r.value.relativePct).toBeUndefined();
    expect(r.warnings).toContain('compare.relative_undefined_expected_zero');
  });

  it('decides nothing: no status, tolerance or pass/fail in the result', () => {
    const r = compareExpectedMeasured('active_matter', expectedOf('15'), measuredOf('14.4'));
    expect(r.ok && Object.keys(r.value).sort()).toEqual(['difference', 'expected', 'measured', 'relativePct']);
  });
});
