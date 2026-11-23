/**
 * T-01 golden cases, family 4: formula arithmetic (scale, q.s., percent from weight, phases), the measured-curve
 * calculators (salt curve, pH titration), the weighing sheet, deviation/limits and the OIML lookups.
 * Hand arithmetic in the comments; fictional inputs.
 */
import { describe, expect, it } from 'vitest';
import {
  balanceQs,
  checkLimits,
  densityFromWW,
  evaluateDeviation,
  percentFromWeight,
  phaseSummary,
  qty,
  reagentForTargetPH,
  saltForViscosity,
  scaleFormula,
  validatePhases,
  viscosityAt,
  vvFromWW,
  weighingSheet,
  wwFromVV,
} from '../../src/index';
import { HAND_TABLE, must, mustFormula } from './support';

const pt = (saltPct: string, viscosity_mPas: string) => ({ saltPct, viscosity_mPas, sourceId: 'LAB-GOLDEN', confidence: 'L1_coa_batch' as const });
const tp = (added: string, pH: string) => ({ added, pH, sourceId: 'LAB-GOLDEN', confidence: 'L1_coa_batch' as const });

describe('golden[phase_balance] scale, q.s. and the three phases of an emulsion', () => {
  it('scale 62.5 % + 37.5 % to 8 kg: 8 × 62.5 / 100 = 5 kg and 8 × 37.5 / 100 = 3 kg, no residual', () => {
    const r = mustFormula(scaleFormula([{ materialId: 'A', percentWW: '62.5' }, { materialId: 'B', percentWW: '37.5' }], qty('8', 'kg')));
    expect([r.lines.map((l) => l.mass.value), r.total.value, r.residual]).toEqual([['5', '3'], '8', '0']);
  });

  it('q.s.: 100 − 12.5 − 3.25 = 84.25 % for the balancing water; the others are untouched', () => {
    const r = mustFormula(balanceQs([{ materialId: 'A', percentWW: '12.5' }, { materialId: 'B', percentWW: '3.25' }, { materialId: 'W', qs: true }]));
    expect(r.lines.map((l) => [l.materialId, l.percentWW])).toEqual([['A', '12.5'], ['B', '3.25'], ['W', '84.25']]);
    expect(r.balanceEvent).toEqual({ materialId: 'W', before: null, after: '84.25' });
  });

  it('percent from weight: 250 g in 2 kg = 250 / 2000 × 100 = 12.5 %; 500 mL at 0.9 kg/L = 450 g in 4.5 kg = 10 %', () => {
    expect(must(percentFromWeight({ amount: qty('250', 'g'), batchSize: qty('2', 'kg') })).value).toBe('12.5');
    const rho = { value: '0.9', unit: 'kg/L', sourceId: 'TDS-OIL', confidence: 'L2_supplier_tds', validFrom: '2026-01-01' } as const;
    expect(must(percentFromWeight({ materialId: 'OIL', amount: qty('500', 'mL'), batchSize: qty('4.5', 'kg'), materialDensity: rho })).value).toBe('10');
  });

  it('phase sums: water 60 + 2, oil 25 + 8, cool-down 1 + 4 => 62 / 33 / 5, total 100', () => {
    const lines = [
      { materialId: 'W', percentWW: '60', phase: 'water' }, { materialId: 'S', percentWW: '2', phase: 'water' },
      { materialId: 'O', percentWW: '25', phase: 'oil' }, { materialId: 'E', percentWW: '8', phase: 'oil' },
      { materialId: 'P', percentWW: '1', phase: 'cool_down' }, { materialId: 'F', percentWW: '4', phase: 'cool_down' },
    ];
    const s = phaseSummary(lines);
    expect([s.totals.map((t) => [t.phase, t.sum]), s.sum, s.deviation, s.withinTolerance]).toEqual([[['water', '62'], ['oil', '33'], ['cool_down', '5']], '100', '0', true]);
    expect(validatePhases(lines).ok).toBe(true);
  });

  it('phases that add up to 99.5 are rejected (deviation −0.5), not silently accepted', () => {
    const r = validatePhases([{ materialId: 'W', percentWW: '70', phase: 'water' }, { materialId: 'O', percentWW: '29.5', phase: 'oil' }]);
    expect(!r.ok && r.errors.map((e) => e.messageKey)).toEqual(['formula.phase_sum_not_100']);
  });
});

describe('golden[salt_curve] viscosity vs salt from user measurements only (never extrapolated)', () => {
  const curve = [pt('0', '100'), pt('2', '5100'), pt('4', '12100')];

  it('between the points (2, 5100) and (4, 12100): at 3 % salt (5100 + 12100) / 2 = 8600; at 1 % (100 + 5100) / 2 = 2600', () => {
    expect(must(viscosityAt(curve, '3')).viscosity_mPas).toBe('8600');
    expect(must(viscosityAt(curve, '1')).viscosity_mPas).toBe('2600');
  });

  it('inverse: 8600 mPa.s is reached at 3 % salt', () => {
    const r = must(saltForViscosity(curve, '8600'));
    expect([r.solutions.map((s) => s.saltPct), r.monotonic]).toEqual([['3'], true]);
  });

  it('beyond the measured range is missing, not extrapolated: 5 % salt', () => {
    const r = viscosityAt(curve, '5');
    expect(!r.ok && r.missing[0]?.reason.startsWith('out_of_measured_range')).toBe(true);
  });
});

describe('golden[ph_titration] reagent for a target pH from the measured curve', () => {
  const points = [tp('0', '9'), tp('1', '8'), tp('2', '7')];

  it('pH 7.5 lies halfway between (1, 8) and (2, 7): 1.5 %w/w of 2000 g = 2000 × 1.5 / 100 = 30 g', () => {
    const r = must(reagentForTargetPH({ curve: { reagentId: 'ACID', unit: 'pctWW', points }, targetPH: '7.5', batchMass: qty('2000', 'g') }));
    expect([r.solutions[0]?.added, r.solutions[0]?.amountG]).toEqual(['1.5', '30']);
  });

  it('in g per kg: 1.5 g/kg of 2000 g = 2 kg × 1.5 = 3 g', () => {
    const r = must(reagentForTargetPH({ curve: { reagentId: 'ACID', unit: 'gPerKg', points }, targetPH: '7.5', batchMass: qty('2000', 'g') }));
    expect(r.solutions[0]?.amountG).toBe('3');
  });

  it('a pH outside the measured span (6) is missing', () => {
    const r = reagentForTargetPH({ curve: { reagentId: 'ACID', unit: 'pctWW', points }, targetPH: '6' });
    expect(r.ok).toBe(false);
  });
});

describe('golden[weighing_sheet] weighing sheet of a production order (Q-02)', () => {
  it('333 g batch, resolution 0.5 g: oil 33.3 % = 110.889 g -> 111.0 ; water q.s. 66.7 % = 222.111 g -> 222.0; order water then oil', () => {
    // 333 × 33.3 / 100 = 110.889 ; 110.889 / 0.5 = 221.778 -> 222 -> × 0.5 = 111.0 ; 333 × 66.7 / 100 = 222.111 -> 444.222 -> 444 -> 222.0
    // consumeKg at the contract's 6 decimals: 0.110889 and 0.222111
    const r = must(
      weighingSheet({
        batchMass: { value: '333', unit: 'g' },
        resolutionG: '0.5',
        lines: [
          { lineNo: '1', materialId: 'OIL', phase: 'oil', percentWW: '33.3' },
          { lineNo: '2', materialId: 'WATER', phase: 'water', qs: true },
        ],
      }),
    );
    expect(r.lines.map((l) => [l.materialId, l.percentWW, l.exactG, l.weighG, l.consumeKg])).toEqual([
      ['WATER', '66.7', '222.111', '222.0', '0.222111'],
      ['OIL', '33.3', '110.889', '111.0', '0.110889'],
    ]);
    expect([r.totalExactG, r.totalWeighG]).toEqual(['333', '333']);
  });

  it('no registered scale resolution: missing, never a default step', () => {
    const r = weighingSheet({ batchMass: { value: '1', unit: 'kg' }, resolutionG: undefined, lines: [{ lineNo: '1', materialId: 'A', percentWW: '100' }] });
    expect(!r.ok && r.missing[0]?.reason).toBe('production.scale_resolution_missing');
  });
});

describe('golden[deviation] planned against actual, and limits', () => {
  it('planned 200 g, actual 210 g: +10 g = +5 %; threshold 5 % is not exceeded (equal), 6 % deviation (212 g) is', () => {
    const at = must(evaluateDeviation({ planned: '200', actual: '210', unit: 'g', thresholdPct: '5' }));
    expect([at.difference.value, at.differencePct.value, at.exceedsThreshold, at.verdict]).toEqual(['10', '5', false, 'within']);
    const over = must(evaluateDeviation({ planned: '200', actual: '212', unit: 'g', thresholdPct: '5' }));
    expect([over.differencePct.value, over.exceedsThreshold, over.verdict]).toEqual(['6', true, 'exceeds']);
  });

  it('no threshold set: not evaluated (verdict threshold_not_set), never "within"', () => {
    const r = must(evaluateDeviation({ planned: '200', actual: '212', unit: 'g' }));
    expect([r.exceedsThreshold, r.verdict]).toEqual([null, 'threshold_not_set']);
  });

  it('limits 6 .. 8: 7.5 PASS, 8.1 FAIL, a value on a limit passes (8), no limits => UNKNOWN', () => {
    expect([must(checkLimits({ value: '7.5', min: '6', max: '8' })).status, must(checkLimits({ value: '8.1', min: '6', max: '8' })).status, must(checkLimits({ value: '8', min: '6', max: '8' })).status]).toEqual(['PASS', 'FAIL', 'PASS']);
    expect(must(checkLimits({ value: '7' })).status).toBe('UNKNOWN');
  });
});

describe('golden[core_alcohol] OIML lookups by linear interpolation (hand table)', () => {
  it('%w/w -> %v/v: 20 %w/w = 20 × 50 / 40 = 25 ; 70 %w/w = 50 + (70 − 40) × 50 / 60 = 75', () => {
    expect([must(vvFromWW('20', HAND_TABLE)).toFixed(), must(vvFromWW('70', HAND_TABLE)).toFixed()]).toEqual(['25', '75']);
  });

  it('%v/v -> %w/w: 25 %v/v = 25 × 40 / 50 = 20 ; density at 20 %w/w = 1000 − (20 / 40) × 50 = 975 kg/m3', () => {
    expect([must(wwFromVV('25', HAND_TABLE)).toFixed(), must(densityFromWW('20', HAND_TABLE)).toFixed()]).toEqual(['20', '975']);
  });

  it('outside the table (101 %w/w) is missing, not extrapolated', () => {
    expect(vvFromWW('101', HAND_TABLE).ok).toBe(false);
  });
});
