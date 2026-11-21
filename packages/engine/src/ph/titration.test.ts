import { describe, expect, it } from 'vitest';
import type { Confidence } from '../contract';
import { qty } from '../core/quantity';
import { reagentForTargetPH, TITRATION_REASONS, TITRATION_WARNINGS, type TitrationCurve, type TitrationInput, type TitrationPoint } from './titration';

/*
 * Curves are fictional lab measurements (TEST INPUTS), deliberately unsorted.
 * Curve A (reagent ACID-A, % w/w added): (0, 9.5) (0.1, 7.2) (0.2, 6.0) (0.4, 4.8) (0.8, 4.0)
 *   pH 7.2 is a measured point            => 0.1
 *   pH 6.6 between (0.1, 7.2)-(0.2, 6.0)  => 0.1 + (6.6-7.2)/(6.0-7.2) x 0.1 = 0.1 + 0.5 x 0.1 = 0.15
 *   pH 5.4 between (0.2, 6.0)-(0.4, 4.8)  => 0.2 + (5.4-6.0)/(4.8-6.0) x 0.2 = 0.2 + 0.5 x 0.2 = 0.30
 *   500 g batch at 0.15 % => 500 x 0.15 / 100 = 0.75 g
 * Curve B (g per kg): (0, 9) (2, 6) (4, 5): pH 7.5 => 0 + (7.5-9)/(6-9) x 2 = 1 g/kg; batch 2 kg => 2 g
 */
const p = (added: string, pH: string, sourceId = 'LAB-1', confidence: Confidence = 'L1_coa_batch'): TitrationPoint => ({ added, pH, sourceId, confidence });
const curveA: TitrationCurve = {
  reagentId: 'ACID-A',
  unit: 'pctWW',
  points: [p('0.4', '4.8', 'm4'), p('0', '9.5', 'm1'), p('0.8', '4.0', 'm5'), p('0.2', '6.0', 'm3', 'L5_reference'), p('0.1', '7.2', 'm2')],
};
const curveB: TitrationCurve = { reagentId: 'ACID-B', unit: 'gPerKg', points: [p('0', '9'), p('2', '6'), p('4', '5')] };
const run = (over: Partial<TitrationInput> & { targetPH: string }) => reagentForTargetPH({ curve: curveA, ...over });
const added = (targetPH: string, curve = curveA) => {
  const r = reagentForTargetPH({ curve, targetPH });
  return r.ok ? r.value.solutions.map((s) => s.added) : null;
};
const gaps = (input: TitrationInput) => {
  const r = reagentForTargetPH(input);
  return r.ok ? null : r.missing;
};

describe('reagentForTargetPH', () => {
  it('measured point returns itself: pH 7.2 => 0.1 % (segment ends at that point)', () => {
    const r = run({ targetPH: '7.2' });
    expect(r.ok && r.value.solutions[0]).toMatchObject({ added: '0.1', segment: { to: { added: '0.1', sourceId: 'm2' } } });
    expect(r.ok && r.value.monotonic).toBe(true);
  });

  it('interpolates inside a segment: pH 6.6 => 0.15 %, pH 5.4 => 0.3 %', () => {
    expect(added('6.6')).toEqual(['0.15']);
    expect(added('5.4')).toEqual(['0.3']);
  });

  it('batch mass gives the reagent mass: 500 g x 0.15 % = 0.75 g', () => {
    const r = run({ targetPH: '6.6', batchMass: qty('500', 'g') });
    expect(r.ok && r.value.solutions[0]?.amountG).toBe('0.75');
  });

  it('g per kg unit: pH 7.5 => 1 g/kg; a 2 kg batch needs 2 g', () => {
    const r = reagentForTargetPH({ curve: curveB, targetPH: '7.5', batchMass: qty('2', 'kg') });
    expect(r.ok && [r.value.solutions[0]?.added, r.value.solutions[0]?.amountG, r.value.unit, r.value.reagentId]).toEqual(['1', '2', 'gPerKg', 'ACID-B']);
  });

  it('confidence of a solution is the worst of its two neighbours (m2 L1 + m3 L5 => L5)', () => {
    const r = run({ targetPH: '6.6' });
    expect(r.ok && [r.value.solutions[0]?.confidence, r.value.solutions[0]?.segment.to.sourceId]).toEqual(['L5_reference', 'm3']);
  });

  it('NO extrapolation: pH outside the measured span (4.0..9.5) is missing', () => {
    for (const t of ['3.9', '9.6', '12']) {
      expect(gaps({ curve: curveA, targetPH: t })).toEqual([{ property: 'targetPH', reason: `${TITRATION_REASONS.PH_OUT_OF_MEASURED_RANGE}:${t} not in 4..9.5` }]);
    }
    expect(added('4.0')).toEqual(['0.8']);
    expect(added('9.5')).toEqual(['0']);
  });

  it('non-monotonic curve: ALL solutions ascending + warning', () => {
    // (0, 8) (1, 5) (2, 6.5) (3, 4); pH 6: 0 + (6-8)/(5-8) = 0.6667 ; 1 + (6-5)/1.5 = 1.6667 ; 2 + (6-6.5)/(4-6.5) = 2.2
    const wavy: TitrationCurve = { reagentId: 'X', unit: 'pctWW', points: [p('2', '6.5'), p('0', '8'), p('3', '4'), p('1', '5')] };
    const r = reagentForTargetPH({ curve: wavy, targetPH: '6' });
    expect(r.ok && r.value.monotonic).toBe(false);
    expect(r.ok && r.value.solutions.map((s) => s.added.slice(0, 6))).toEqual(['0.6666', '1.6666', '2.2']);
    expect(r.ok && r.warnings).toEqual([TITRATION_WARNINGS.CURVE_NON_MONOTONIC]);
  });

  it('flat stretch at the target pH: an interval of amounts (and of masses)', () => {
    // (0, 7) (1, 5) (2, 5) (3, 3): pH 5 for any amount in 1..2
    const flat: TitrationCurve = { reagentId: 'X', unit: 'pctWW', points: [p('0', '7'), p('1', '5'), p('2', '5'), p('3', '3')] };
    const r = reagentForTargetPH({ curve: flat, targetPH: '5', batchMass: qty('100', 'g') });
    expect(r.ok && r.value.solutions).toMatchObject([{ added: '1', addedTo: '2', amountG: '1', amountGTo: '2' }]);
  });
});

describe('reagentForTargetPH: missing data', () => {
  it('fewer than 2 points', () => {
    expect(gaps({ curve: { ...curveA, points: [p('0', '9')] }, targetPH: '9' })).toEqual([{ property: 'titration', reason: TITRATION_REASONS.NEED_TWO_POINTS }]);
    expect(gaps({ curve: { ...curveA, points: [] }, targetPH: '9' })?.[0]?.reason).toBe(TITRATION_REASONS.NEED_TWO_POINTS);
  });

  it('duplicate amounts are refused (deterministic order)', () => {
    expect(gaps({ curve: { ...curveA, points: [p('1', '5'), p('1', '6'), p('2', '4')] }, targetPH: '5' })).toEqual([
      { property: 'titration', reason: `${TITRATION_REASONS.DUPLICATE_ADDED}:1` },
    ]);
  });

  it('lists ALL bad points, an estimated point, a bad target and a bad batch mass together', () => {
    const g = gaps({
      curve: { ...curveA, points: [p('x', '5'), p('1', 'y'), p('-1', '4'), p('2', '4', 'E', 'L7_estimate')] },
      targetPH: 'seven',
      batchMass: qty('0', 'g'),
    });
    expect(g?.map((x) => `${x.property}:${x.reason}`)).toEqual([
      'titration[0].added:input.invalid_number',
      'titration[1].pH:input.invalid_number',
      'titration[2].added:input.invalid_number',
      `titration[3].confidence:${TITRATION_REASONS.ESTIMATE_NOT_ALLOWED}`,
      'targetPH:input.invalid_number',
      'batchMass:input.invalid_mass',
    ]);
  });
});
