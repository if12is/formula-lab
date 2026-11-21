import { describe, expect, it } from 'vitest';
import type { SourcedScalar } from '../core/parse';
import { qty } from '../core/quantity';
import { blendEmulsifiers, daviesHLB, griffinHLB, HLB_SOURCES, requiredHLB, type HlbLine } from './hlb';

const sv = (value: string, confidence: SourcedScalar['confidence'] = 'L5_reference'): SourcedScalar => ({
  value,
  sourceId: 'test:fictional',
  confidence,
  validFrom: '2026-01-01',
});

describe('requiredHLB', () => {
  // Fictional numbers. (20*5 + 30*10) / (20+30) = 400/50 = 8
  const lines: HlbLine[] = [
    { materialId: 'oilA', mass: '20', phase: 'oil', requiredHLB: sv('5') },
    { materialId: 'oilB', mass: '30', phase: 'oil', requiredHLB: sv('10') },
    { materialId: 'water', mass: '500', phase: 'water' },
    { materialId: 'pres', mass: '5', phase: 'other' },
  ];
  it('mass-weighted mean over the oil phase only', () => {
    const r = requiredHLB(lines);
    expect(r.ok && r.value.value).toBe('8');
  });
  it('non-terminating results stay exact decimals: (1*5 + 2*10)/3 = 25/3', () => {
    const r = requiredHLB([
      { materialId: 'a', mass: '1', phase: 'oil', requiredHLB: sv('5') },
      { materialId: 'b', mass: '2', phase: 'oil', requiredHLB: sv('10') },
    ]);
    expect(r.ok && r.value.value.startsWith('8.3333333')).toBe(true);
  });
  it('missing rHLB lists every offending oil line (not just the first)', () => {
    const r = requiredHLB([
      { materialId: 'a', mass: '1', phase: 'oil' },
      { materialId: 'b', mass: '2', phase: 'oil', requiredHLB: sv('10') },
      { materialId: 'c', mass: '2', phase: 'oil' },
      { materialId: 'w', mass: '9', phase: 'water' },
    ]);
    expect(r.ok).toBe(false);
    expect(!r.ok && r.missing.map((m) => m.materialId)).toEqual(['a', 'c']);
  });
  it('no oil lines => missing', () => {
    const r = requiredHLB([{ materialId: 'w', mass: '1', phase: 'water' }]);
    expect(!r.ok && r.missing[0]?.reason).toBe('no_oil_phase_lines');
  });
  it('invalid mass / invalid rHLB => missing', () => {
    const r = requiredHLB([
      { materialId: 'a', mass: 'x', phase: 'oil', requiredHLB: sv('5') },
      { materialId: 'b', mass: '1', phase: 'oil', requiredHLB: sv('-1') },
    ]);
    expect(!r.ok && r.missing.map((m) => m.reason)).toEqual(['invalid_mass', 'invalid_required_hlb']);
  });
  it('zero total oil mass => missing, never NaN', () => {
    const r = requiredHLB([{ materialId: 'a', mass: '0', phase: 'oil', requiredHLB: sv('5') }]);
    expect(!r.ok && r.missing[0]?.reason).toBe('oil_phase_total_mass_zero');
  });
  it('L7 rHLB rejected by default policy, allowed with override plus warning', () => {
    const l7: HlbLine[] = [{ materialId: 'a', mass: '1', phase: 'oil', requiredHLB: sv('6', 'L7_estimate') }];
    const rejected = requiredHLB(l7);
    expect(!rejected.ok && rejected.missing[0]?.reason).toBe('confidence_L7_rejected_by_policy');
    const allowed = requiredHLB(l7, { allowL7: true });
    expect(allowed.ok && allowed.value.value).toBe('6');
    expect(allowed.warnings[0]).toContain('L7');
  });
});

describe('blendEmulsifiers', () => {
  const low = { materialId: 'low', hlb: sv('4') };
  const high = { materialId: 'high', hlb: sv('14') };
  it('f_high = (10-4)/(14-4) = 0.6; 10 g total => 6 g high + 4 g low', () => {
    const r = blendEmulsifiers('10', low, high, qty('10', 'g'));
    expect(r.ok).toBe(true);
    if (r.ok) {
      expect(r.value.fractionHigh).toBe('0.6');
      expect(r.value.fractionLow).toBe('0.4');
      expect(r.value.high.mass.toJSON()).toEqual({ value: '6', unit: 'g' });
      expect(r.value.low.mass.toJSON()).toEqual({ value: '4', unit: 'g' });
      expect(r.value.high.materialId).toBe('high');
    }
  });
  it('keeps the caller mass unit', () => {
    const r = blendEmulsifiers('9', low, high, qty('2', 'kg'));
    expect(r.ok && r.value.high.mass.toJSON()).toEqual({ value: '1', unit: 'kg' });
  });
  it('target outside or equal to the bounds is an error', () => {
    for (const t of ['4', '14', '3', '15']) {
      const r = blendEmulsifiers(t, low, high, qty('10', 'g'));
      expect(!r.ok && r.missing[0]?.reason).toContain('target_out_of_range');
    }
  });
  it('reversed pair (low >= high) is an error', () => {
    const r = blendEmulsifiers('10', high, low, qty('10', 'g'));
    expect(r.ok).toBe(false);
  });
  it('invalid inputs are all reported', () => {
    const r = blendEmulsifiers('x', { materialId: 'l', hlb: sv('y') }, { materialId: 'h', hlb: sv('z') }, qty('0', 'g'));
    expect(!r.ok && r.missing).toHaveLength(4);
    const v = blendEmulsifiers('10', low, high, qty('5', 'mL'));
    expect(v.ok).toBe(false);
  });
});

describe('griffinHLB', () => {
  it('20 x 7 / 10 = 14', () => {
    const r = griffinHLB('7', '10');
    expect(r.ok && r.value.value).toBe('14');
  });
  it('bounds: 0 -> 0, Mh=M -> 20', () => {
    const a = griffinHLB('0', '5');
    const b = griffinHLB('5', '5');
    expect(a.ok && a.value.value).toBe('0');
    expect(b.ok && b.value.value).toBe('20');
  });
  it('rejects Mh > M, M <= 0, negative, non-decimal', () => {
    expect(griffinHLB('11', '10').ok).toBe(false);
    expect(griffinHLB('1', '0').ok).toBe(false);
    expect(griffinHLB('-1', '10').ok).toBe(false);
    expect(griffinHLB('a', '10').ok).toBe(false);
  });
});

describe('daviesHLB', () => {
  // Fictional group numbers: 2 x 2.0 + 1 x 1.5 = 5.5; n = 3; 7 + 5.5 - 0.475*3 = 11.075
  it('7 + sum(H) - 0.475 n', () => {
    const r = daviesHLB([
      { group: 'gA', h: sv('2.0'), count: '2' },
      { group: 'gB', h: sv('1.5') },
    ]);
    expect(r.ok && r.value.value).toBe('11.075');
  });
  it('missing group number or bad count => missing listing every group', () => {
    const r = daviesHLB([
      { group: 'gA', h: undefined as unknown as SourcedScalar },
      { group: 'gB', h: sv('1'), count: '1.5' },
      { group: 'gC', h: sv('1'), count: '0' },
    ]);
    expect(!r.ok && r.missing.map((m) => m.property)).toEqual(['davies_group:gA', 'davies_group:gB', 'davies_group:gC']);
  });
  it('empty group list => missing', () => {
    expect(daviesHLB([]).ok).toBe(false);
  });
});

describe('HLB_SOURCES', () => {
  it('documents the definitional constants', () => {
    expect(HLB_SOURCES.davies.constants).toEqual({ base: '7', perGroup: '0.475' });
    expect(HLB_SOURCES.griffin.constant).toBe('20');
  });
});
