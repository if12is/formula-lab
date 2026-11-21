import { describe, expect, it } from 'vitest';
import { dec } from '../core/decimal';
import { qty } from '../core/quantity';
import { fixture } from '../test-fixtures';
import { leavesOf } from '../trace/trace';
import {
  availableChlorineDilution,
  CHLORINE_REASONS,
  convertChlorineBasis,
  storageLossAt,
  type ChlorineDilutionInput,
  type StorageLossPoint,
} from './available-chlorine';

/*
 * All grade strengths and loss measurements below are TEST INPUTS (sourceId TEST-FIXTURE), not engine data.
 * Basis ratio: M_Cl2 / M_NaOCl = 70.90 / 74.44 = 0.9524449...
 *
 * Hand calculations (batch 1000 g, COA 12.5 % w/w):
 *  A) same basis, target 5 %: m_raw = 1000 x 5 / 12.5 = 400 g, water 600 g
 *  B) COA as NaOCl, target 5 % as Cl2: target on NaOCl basis = 5 x 74.44/70.90 = 5.249647...;
 *     m_raw = 1000 x 5.249647 / 12.5 = 419.9718 g
 *  C) lab data (0 d: 0 %), (30 d: 6 %), (60 d: 10 %); storage 45 d: loss = 6 + 0.5 x 4 = 8 %;
 *     target 5 % at END of storage => fill = 5 / 0.92 = 5.434783 %; m_raw = 1000 x 5.434783 / 12.5 = 434.7826 g
 *  D) storage exactly on a measured day (30 d, 6 %): fill = 5 / 0.94 = 5.319149 %; m_raw = 425.5319 g
 */
const pt = (days: string, loss: string, confidence: Parameters<typeof fixture>[2] = 'L1_coa_batch'): StorageLossPoint => ({
  days,
  lossPct: fixture(loss, '%w/w', confidence),
});
const lab = [pt('60', '10'), pt('0', '0'), pt('30', '6')]; // deliberately unsorted
const base = (over: Partial<ChlorineDilutionInput> = {}): ChlorineDilutionInput => ({
  materialId: 'NAOCL-12',
  coa: fixture('12.5', '%w/w', 'L1_coa_batch'),
  coaBasis: 'Cl2',
  targetPct: '5',
  targetBasis: 'Cl2',
  batchMass: qty('1000', 'g'),
  policy: {},
  ...over,
});
const run = (over: Partial<ChlorineDilutionInput> = {}) => {
  const r = availableChlorineDilution(base(over));
  if (!r.ok) {
    throw new Error(`expected ok: ${JSON.stringify(r.missing)}`);
  }
  return r;
};
const gaps = (over: Partial<ChlorineDilutionInput>) => {
  const r = availableChlorineDilution(base(over));
  return r.ok ? null : r.missing;
};
const starts = (v: string, prefix: string): boolean => v.startsWith(prefix);

describe('convertChlorineBasis', () => {
  it('12.5 % NaOCl = 12.5 x 70.90 / 74.44 = 11.9056 % Cl2, and back', () => {
    const cl2 = convertChlorineBasis(dec('12.5'), 'NaOCl', 'Cl2');
    expect(starts(cl2.toFixed(), '11.90556152606')).toBe(true);
    expect(convertChlorineBasis(cl2, 'Cl2', 'NaOCl').toDecimalPlaces(20).toFixed()).toBe('12.5');
  });
  it('same basis is the identity', () => {
    expect(convertChlorineBasis(dec('7'), 'Cl2', 'Cl2').toFixed()).toBe('7');
  });
});

describe('availableChlorineDilution', () => {
  it('A) same basis: 1000 g to 5 % from 12.5 % => 400 g raw + 600 g water', () => {
    const { value: r } = run();
    expect([r.rawMass.value, r.diluentMass.value, r.fillTargetPct.value, r.targetOnCoaBasisPct.value]).toEqual(['400', '600', '5', '5']);
    expect(r.lossPct).toBeUndefined();
  });

  it('B) COA as NaOCl, target as Cl2: target converts to 5.2496 % NaOCl => 419.9718 g raw', () => {
    const { value: r } = run({ coaBasis: 'NaOCl', targetBasis: 'Cl2' });
    expect(starts(r.targetOnCoaBasisPct.value, '5.24964739069')).toBe(true);
    expect(starts(r.rawMass.value, '419.97179125528')).toBe(true);
  });

  it('B2) opposite direction: COA as Cl2, target 5 % NaOCl => 4.762225 % Cl2 => 380.978 g raw (1000 x 4.762225 / 12.5)', () => {
    const { value: r } = run({ coaBasis: 'Cl2', targetBasis: 'NaOCl' });
    expect(starts(r.targetOnCoaBasisPct.value, '4.76222461042')).toBe(true);
    expect(starts(r.rawMass.value, '380.97796883')).toBe(true);
  });

  it('C) measured storage loss, 45 d => 8 % (interpolated); fill target 5.434783 %; 434.7826 g raw', () => {
    const { value: r } = run({ storage: { lossPoints: lab, days: '45' } });
    expect(r.lossPct?.value).toBe('8');
    expect(starts(r.fillTargetPct.value, '5.43478260869')).toBe(true);
    expect(starts(r.rawMass.value, '434.78260869565')).toBe(true);
  });

  it('D) storage on a measured day uses the measurement itself: 30 d => 6 %, 425.5319 g raw', () => {
    const { value: r } = run({ storage: { lossPoints: lab, days: '30' } });
    expect(r.lossPct?.value).toBe('6');
    expect(starts(r.rawMass.value, '425.53191489361')).toBe(true);
  });

  it('cross-basis + storage: COA Cl2, target 5 % NaOCl, 45 d => 4.762225 / 0.92 = 5.176331 %; 414.1065 g raw', () => {
    const { value: r } = run({ targetBasis: 'NaOCl', storage: { lossPoints: lab, days: '45' } });
    expect(starts(r.fillTargetPct.value, '5.17633109828')).toBe(true);
    expect(starts(r.rawMass.value, '414.10648786300')).toBe(true);
  });

  it('trace of the raw mass reaches the COA grade and the measured loss points (sourceId kept)', () => {
    const { value: r } = run({ storage: { lossPoints: lab, days: '45' } });
    const leaves = leavesOf(r.rawMass.trace);
    expect(leaves.find((n) => n.label === 'NAOCL-12 active matter (%)')).toMatchObject({ value: '12.5', sourceId: 'TEST-FIXTURE' });
    expect(leavesOf(r.fillTargetPct.trace).filter((n) => n.label.startsWith('Measured storage loss')).map((n) => n.value)).toEqual(['6', '10']);
  });
});

describe('storageLossAt (measured points only)', () => {
  it('interpolates inside the measured days; never extrapolates', () => {
    const at = (d: string) => storageLossAt({ lossPoints: lab, days: d }, {});
    const mid = at('15');
    expect(mid.ok && mid.value.value).toBe('3');
    expect(at('0').ok).toBe(true);
    expect(at('60').ok).toBe(true);
    const out = at('61');
    expect(!out.ok && out.missing).toEqual([{ property: 'storageDays', reason: `${CHLORINE_REASONS.STORAGE_OUT_OF_MEASURED_RANGE}:61 not in 0..60` }]);
  });

  it('no early data point => shorter times are out of range (no assumption of 0 % at day 0)', () => {
    const r = storageLossAt({ lossPoints: [pt('7', '1'), pt('30', '6')], days: '3' }, {});
    expect(!r.ok && r.missing[0]?.property).toBe('storageDays');
  });

  it('a single measured point covers exactly its own day', () => {
    const one = [pt('30', '6')];
    const hit = storageLossAt({ lossPoints: one, days: '30' }, {});
    expect(hit.ok && hit.value.value).toBe('6');
    expect(storageLossAt({ lossPoints: one, days: '31' }, {}).ok).toBe(false);
  });

  it('no points, duplicate days, bad numbers, 100 % loss: missing with reasons', () => {
    expect(storageLossAt({ lossPoints: [], days: '1' }, {})).toMatchObject({ ok: false, missing: [{ reason: CHLORINE_REASONS.STORAGE_POINTS_REQUIRED }] });
    const dup = storageLossAt({ lossPoints: [pt('5', '1'), pt('5', '2')], days: '5' }, {});
    expect(!dup.ok && dup.missing[0]?.reason).toBe(`${CHLORINE_REASONS.STORAGE_DAYS_DUPLICATE}:5`);
    const bad = storageLossAt({ lossPoints: [pt('x', '1'), pt('5', '101'), pt('6', '2')], days: '-1' }, {});
    expect(!bad.ok && bad.missing.map((g) => `${g.property}:${g.reason}`)).toEqual([
      'storageDays:input.invalid_number',
      'storageLoss[0].days:input.invalid_number',
      'storageLoss[1].lossPct:input.out_of_range',
    ]);
    const total = storageLossAt({ lossPoints: [pt('0', '0'), pt('10', '100')], days: '10' }, {});
    expect(!total.ok && total.missing[0]?.reason).toBe(CHLORINE_REASONS.STORAGE_LOSS_TOTAL);
  });

  it('a measured point with bad days is reported even when the query is fine', () => {
    const r = storageLossAt({ lossPoints: [pt('-1', '1'), pt('5', '2')], days: '2' }, {});
    expect(!r.ok && r.missing.map((g) => g.property)).toEqual(['storageLoss[0].days']);
  });

  it('L7 loss point needs a signed override; below-floor confidence warns', () => {
    const l7 = storageLossAt({ lossPoints: [pt('0', '0', 'L7_estimate'), pt('5', '2')], days: '2' }, {});
    expect(!l7.ok && l7.missing[0]?.reason).toBe('confidence.l7_requires_signed_override');
    const warn = storageLossAt({ lossPoints: [pt('0', '0', 'L6_community'), pt('5', '2')], days: '2' }, { allowConfidenceBelow: 'L2_supplier_tds' });
    expect(warn.ok && warn.warnings).toEqual(['confidence.below_policy:-:storageLoss[0].lossPct:L6_community']);
  });
});

describe('availableChlorineDilution: missing data', () => {
  it('no COA => missing rawActive (never a default strength)', () => {
    expect(gaps({ coa: undefined })).toEqual([{ materialId: 'NAOCL-12', property: 'rawActive', reason: 'input.missing' }]);
  });

  it('lists ALL gaps: bad basis, no COA, bad mass, bad target, no loss data', () => {
    const g = gaps({
      coa: undefined,
      coaBasis: 'bleach' as never,
      targetBasis: '' as never,
      batchMass: qty('0', 'g'),
      targetPct: '-1',
      storage: { lossPoints: [], days: '10' },
    });
    expect(g?.map((x) => x.property)).toEqual(['coaBasis', 'targetBasis', 'rawActive', 'batchMass', 'targetPct', 'storageLoss']);
  });

  it('target above the COA strength cannot be reached (on the same basis)', () => {
    expect(gaps({ targetPct: '13' })?.[0]?.reason).toBe('active.target_exceeds_raw');
  });

  it('storage compensation can push the fill target above the COA strength => missing', () => {
    // 10 % loss at 60 d: target 12 % at the end => fill 12 / 0.9 = 13.33 % > 12.5 % COA
    expect(gaps({ targetPct: '12', storage: { lossPoints: [pt('0', '0'), pt('60', '10')], days: '60' } })?.[0]?.reason).toBe('active.target_exceeds_raw');
  });

  it('a COA in the wrong unit is a unit mismatch', () => {
    expect(gaps({ coa: fixture('12.5', 'g') })?.[0]?.reason).toBe('input.unit_mismatch');
  });
});
