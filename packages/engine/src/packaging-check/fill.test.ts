import { describe, expect, it } from 'vitest';
import type { SourcedValue } from '../contract';
import { qty } from '../core/quantity';
import { fixture } from '../test-fixtures';
import { leavesOf } from '../trace/trace';
import { checkFill, type FillCheckInput } from './fill';

/*
 * Container specs and densities are TEST INPUTS (sourceId TEST-FIXTURE).
 * Hand calculations (headspace = % of brimful capacity unless stated):
 *   1) nominal 500 mL, brimful 530, headspace 5 %  => 26.5 mL, usable 503.5, margin +3.5 => PASS; fill 500/530 = 94.3396 %
 *   2) nominal 500 mL, brimful 520, headspace 5 %  => 26 mL,   usable 494,   margin -6   => FAIL
 *   3) nominal 475 mL, brimful 500, headspace 5 %  => 25 mL,   usable 475,   margin 0    => PASS (<=)
 *   4) nominal 450 g at 0.90 g/mL = 500 mL, brimful 540, headspace 30 mL => usable 510, margin +10 => PASS
 *   5) nominal 1 L = 1000 mL, brimful 1050, headspace 20 mL => usable 1030, margin +30 => PASS
 */
const density = (value: string, confidence: SourcedValue['confidence'] = 'L1_coa_batch'): SourcedValue => ({
  value,
  unit: 'g/mL',
  sourceId: 'TEST-FIXTURE',
  confidence,
  validFrom: '2026-01-01',
});
const base = (over: Partial<FillCheckInput> = {}): FillCheckInput => ({
  containerId: 'BOTTLE-500',
  nominal: qty('500', 'mL'),
  brimfulMl: fixture('530', 'mL', 'L2_supplier_tds'),
  headspace: { kind: 'pct', value: '5' },
  policy: {},
  ...over,
});
const v = (t: { value: string } | undefined) => t?.value;

describe('checkFill', () => {
  it('1) 500 mL in 530 mL brimful with 5 % headspace: PASS, margin 3.5 mL', () => {
    const r = checkFill(base());
    expect([r.status, v(r.headspaceMl), v(r.usableMl), v(r.marginMl), v(r.nominalMl)]).toEqual(['PASS', '26.5', '503.5', '3.5', '500']);
    expect(r.fillPctOfBrimful?.value.startsWith('94.33962264150943')).toBe(true);
    expect(r.missing).toEqual([]);
  });

  it('2) brimful 520 mL: FAIL, margin -6 mL', () => {
    const r = checkFill(base({ brimfulMl: fixture('520', 'mL') }));
    expect([r.status, v(r.usableMl), v(r.marginMl)]).toEqual(['FAIL', '494', '-6']);
  });

  it('3) exactly at the limit passes (<=): margin 0', () => {
    const r = checkFill(base({ nominal: qty('475', 'mL'), brimfulMl: fixture('500', 'mL') }));
    expect([r.status, v(r.marginMl)]).toEqual(['PASS', '0']);
  });

  it('4) nominal as a MASS uses the measured density: 450 g / 0.90 = 500 mL; headspace 30 mL => margin +10', () => {
    const r = checkFill(base({ nominal: qty('450', 'g'), productDensity: density('0.90'), brimfulMl: fixture('540', 'mL'), headspace: { kind: 'mL', value: '30' } }));
    expect([r.status, v(r.nominalMl), v(r.headspaceMl), v(r.usableMl), v(r.marginMl)]).toEqual(['PASS', '500', '30', '510', '10']);
    expect(leavesOf(r.nominalMl!.trace).find((n) => n.label.startsWith('Measured product density'))).toMatchObject({ sourceId: 'TEST-FIXTURE', confidence: 'L1_coa_batch' });
  });

  it('5) litres and kilograms convert: 1 L in 1050 mL with 20 mL headspace => margin +30; 0.45 kg = 450 g', () => {
    expect(v(checkFill(base({ nominal: qty('1', 'L'), brimfulMl: fixture('1050', 'mL'), headspace: { kind: 'mL', value: '20' } })).marginMl)).toBe('30');
    expect(v(checkFill(base({ nominal: qty('0.45', 'kg'), productDensity: density('0.90') })).nominalMl)).toBe('500');
  });

  it('headspace of 0 is an explicit choice and allowed; 100 % leaves nothing usable => FAIL', () => {
    expect(checkFill(base({ headspace: { kind: 'pct', value: '0' } })).status).toBe('PASS');
    expect(checkFill(base({ headspace: { kind: 'pct', value: '100' } })).status).toBe('FAIL');
  });

  it('warns when the container value is below the confidence policy floor', () => {
    const r = checkFill(base({ brimfulMl: fixture('530', 'mL', 'L6_community'), policy: { allowConfidenceBelow: 'L2_supplier_tds' } }));
    expect(r.status).toBe('PASS');
    expect(r.warnings).toEqual(['confidence.below_policy:BOTTLE-500:brimfulMl:L6_community']);
  });
});

describe('checkFill: UNKNOWN, never PASS', () => {
  const unknown = (over: Partial<FillCheckInput>) => {
    const r = checkFill(base(over));
    return r.status === 'UNKNOWN' ? r.missing.map((g) => `${g.property}:${g.reason}`) : r.status;
  };

  it('no brimful capacity and no headspace: both gaps listed', () => {
    expect(unknown({ brimfulMl: undefined, headspace: undefined })).toEqual(['brimfulMl:input.missing', 'headspace:input.missing']);
  });

  it('a mass nominal without a measured density', () => {
    expect(unknown({ nominal: qty('450', 'g') })).toEqual(['productDensity:density.required_for_conversion']);
  });

  it('an L7 estimate (brimful or density) needs a signed override', () => {
    expect(unknown({ brimfulMl: fixture('530', 'mL', 'L7_estimate') })).toEqual(['brimfulMl:confidence.l7_requires_signed_override']);
    expect(unknown({ nominal: qty('450', 'g'), productDensity: density('0.9', 'L7_estimate') })).toEqual(['productDensity:confidence.l7_requires_signed_override']);
  });

  it('invalid inputs are reported: non-positive nominal, wrong units, bad headspace', () => {
    expect(unknown({ nominal: qty('0', 'mL') })).toEqual(['nominal:input.invalid_volume']);
    expect(unknown({ nominal: qty('3', 'pc') })).toEqual(['nominal:input.invalid_volume']);
    expect(unknown({ nominal: qty('0', 'g'), productDensity: density('0.9') })).toEqual(['nominal:input.invalid_mass']);
    expect(unknown({ brimfulMl: fixture('530', 'g') })).toEqual(['brimfulMl:input.unit_mismatch']);
    expect(unknown({ headspace: { kind: 'pct', value: '101' } })).toEqual(['headspace:input.out_of_range']);
    expect(unknown({ headspace: { kind: 'mL', value: '-1' } })).toEqual(['headspace:input.out_of_range']);
    expect(unknown({ headspace: { kind: 'mL', value: 'x' } })).toEqual(['headspace:input.invalid_number']);
  });

  it('density of the wrong dimension is refused', () => {
    expect(unknown({ nominal: qty('450', 'g'), productDensity: { ...density('0.9'), unit: 'g' } })).toEqual(['productDensity:density.must_be_positive_density_unit']);
  });
});
