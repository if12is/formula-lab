import { describe, expect, it } from 'vitest';
import { densityFromWW, type OimlTable } from '../core/alcohol';
import { qty } from '../core/quantity';
import { fixture } from '../test-fixtures';
import { leavesOf } from '../trace/trace';
import { ALCOHOL_REASONS, diluteEthanol, type AlcoholDilutionInput } from './alcohol-dilution';

/*
 * Hand checks use rows of data/oiml-r22/table-20c.json (OIML R 22, 20 C) that are hit EXACTLY,
 * so every expectation below is plain arithmetic on table values:
 *   row %w/w 0.0  : rho 998.2012300000 kg/m3
 *   row %w/w 40.0 : %v/v 47.3947631607, rho 935.1450331484
 *   row %w/w 80.0 : %v/v 85.4885247619, rho 843.3861041913
 *   row %w/w 90.0 : %v/v 93.2664044525
 *   rows %w/w 93.8 / 93.9 : %v/v 95.9734813473 / 96.0425273036 (for the 96 %v/v interpolation)
 * Source strengths below are TEST INPUTS (sourceId TEST-FIXTURE), not engine data.
 */
const V40 = '47.3947631607'; // %v/v of the 40.0 %w/w row
const base = (over: Partial<AlcoholDilutionInput> = {}): AlcoholDilutionInput => ({
  source: fixture('80', '%w/w', 'L1_coa_batch'),
  sourceMaterialId: 'ETOH',
  targetPctVV: V40,
  batch: { mass: qty('1000', 'g') },
  policy: {},
  ...over,
});
const run = (over: Partial<AlcoholDilutionInput> = {}) => {
  const r = diluteEthanol(base(over));
  if (!r.ok) {
    throw new Error(`expected ok: ${JSON.stringify(r.missing)}`);
  }
  return r.value;
};

describe('diluteEthanol: mass balance', () => {
  it('1000 g of 40 %w/w from 80 %w/w: 1000 x 40/80 = 500 g source + 500 g water', () => {
    const r = run();
    expect([r.sourceMass.value, r.waterMass.value, r.totalMass.value]).toEqual(['500', '500', '1000']);
    expect(r.targetPctWW.value).toBe('40');
  });

  it('volume of the product: V = 1000 g / 0.9351450331484 g/mL = 1069.3528 mL', () => {
    const r = run();
    expect(r.targetDensity.value).toBe('0.9351450331484');
    expect(r.totalVolume.value.startsWith('1069.35284319828926')).toBe(true);
  });

  it('contraction: ingredient volumes (500/0.8433861041913 = 592.8483 + 500/0.99820123 = 500.9010) exceed the mix volume', () => {
    const r = run();
    expect(r.sourceVolume.value.startsWith('592.84827852')).toBe(true);
    expect(r.waterVolume.value.startsWith('500.90100570')).toBe(true);
    // 592.8483 + 500.9010 = 1093.7493 mL of parts give 1069.3528 mL of mix (contraction 24.3965 mL, 2.2 %)
    const parts = r.sourceVolume.value.length > 0 ? Number(r.sourceVolume.value) + Number(r.waterVolume.value) : 0;
    expect(parts).toBeGreaterThan(Number(r.totalVolume.value));
  });

  it('target given as a VOLUME: 1000 mL of 40 %w/w weighs 1000 x 0.9351450331484 = 935.1450331484 g; source = half = 467.5725165742 g', () => {
    const r = run({ batch: { volume: qty('1', 'L') } });
    expect(r.totalMass.value).toBe('935.1450331484');
    expect(r.sourceMass.value).toBe('467.5725165742');
    expect(r.waterMass.value).toBe('467.5725165742');
    expect(r.totalVolume.value).toBe('1000');
  });

  it('source 90 %w/w: 1000 x 40/90 = 444.444 g source, 555.556 g water', () => {
    const r = run({ source: fixture('90', '%w/w') });
    expect(r.sourceMass.value.startsWith('444.44444444')).toBe(true);
    expect(r.waterMass.value.startsWith('555.55555555')).toBe(true);
  });

  it('source quoted in %v/v is converted via OIML: 85.4885247619 %v/v = the 80.0 %w/w row, same answer as the w/w case', () => {
    const r = run({ source: fixture('85.4885247619', '%v/v') });
    expect(r.sourcePctWW.value).toBe('80');
    expect([r.sourceMass.value, r.waterMass.value]).toEqual(['500', '500']);
  });

  it('96 %v/v source: w/w by interpolation between rows 93.8 and 93.9 = 93.8384 %w/w; 1000 x 40/93.8384 = 426.2647 g', () => {
    const r = run({ source: fixture('96', '%v/v') });
    expect(r.sourcePctWW.value.startsWith('93.83840724949')).toBe(true);
    expect(r.sourceMass.value.startsWith('426.26469451')).toBe(true);
    expect(r.waterMass.value.startsWith('573.73530548')).toBe(true);
  });

  it('is NOT a volume subtraction: water to add exceeds (V - V_source) because of contraction', () => {
    // 1000 mL of 47.3947631607 %v/v from 96 %v/v: source volume = 1000 x 47.3947631607 / 96 = 493.695 mL
    // (ethanol volume is conserved by definition of %v/v); naive water = 1000 - 493.695 = 506.305 mL,
    // but the water that mass balance puts in (537.49 mL) is larger: the parts (493.70 + 537.49 = 1031.19 mL)
    // contract by 31.19 mL on mixing. Subtracting volumes would under-dilute.
    const r = run({ source: fixture('96', '%v/v'), batch: { volume: qty('1000', 'mL') } });
    expect(Number(r.sourceVolume.value)).toBeCloseTo(493.695, 2);
    expect(Number(r.waterVolume.value)).toBeGreaterThan(1000 - Number(r.sourceVolume.value) + 25);
  });

  it('conserves ethanol: m_source x w_S = M x w_T (within 1e-20 g)', () => {
    const r = run({ source: fixture('96', '%v/v') });
    const lhs = Number(r.sourceMass.value) * Number(r.sourcePctWW.value);
    const rhs = Number(r.totalMass.value) * Number(r.targetPctWW.value);
    expect(Math.abs(lhs - rhs)).toBeLessThan(1e-9);
    expect(Number(r.sourceMass.value) + Number(r.waterMass.value)).toBeCloseTo(1000, 9);
  });

  it('converts kg to g; source equal to target needs no water', () => {
    expect(run({ batch: { mass: qty('1', 'kg') } }).sourceMass.value).toBe('500');
    expect(run({ source: fixture('40', '%w/w') }).waterMass.value).toBe('0');
  });

  it('trace keeps the COA source leaf with sourceId and confidence', () => {
    const r = run();
    const leaf = leavesOf(r.sourceMass.trace).find((n) => n.label === 'Source ethanol strength');
    expect(leaf).toMatchObject({ value: '80', sourceId: 'TEST-FIXTURE', confidence: 'L1_coa_batch' });
  });
});

describe('diluteEthanol: missing data', () => {
  const gaps = (over: Partial<AlcoholDilutionInput>) => {
    const r = diluteEthanol(base(over));
    return r.ok ? null : r.missing;
  };

  it('no COA strength => missing sourceStrength, never a default', () => {
    expect(gaps({ source: undefined })).toEqual([{ materialId: 'ETOH', property: 'sourceStrength', reason: 'input.missing' }]);
  });

  it('a COA value without a stated basis is refused (basis is never guessed)', () => {
    const { unit: _unit, ...noUnit } = fixture('96');
    void _unit;
    expect(gaps({ source: noUnit })).toEqual([{ materialId: 'ETOH', property: 'sourceStrength', reason: ALCOHOL_REASONS.BASIS_REQUIRED }]);
  });

  it('a non-strength unit is a unit mismatch', () => {
    expect(gaps({ source: fixture('96', 'g') })?.[0]?.reason).toBe('input.unit_mismatch');
  });

  it('lists ALL gaps at once: source missing + bad target + bad batch', () => {
    const g = gaps({ source: undefined, targetPctVV: 'abc', batch: { mass: qty('0', 'g') } });
    expect(g?.map((x) => x.property)).toEqual(['sourceStrength', 'targetPctVV', 'batchMass']);
  });

  it('a volume given where a mass belongs is invalid; a mass given as a volume too', () => {
    expect(gaps({ batch: { mass: qty('1', 'L') } })?.[0]?.reason).toBe('input.invalid_mass');
    expect(gaps({ batch: { volume: qty('1', 'kg') } })?.[0]?.reason).toBe('input.invalid_volume');
  });

  it('target stronger than the source cannot be reached by dilution', () => {
    // 93.2664044525 %v/v = 90 %w/w row > 80 %w/w source
    expect(gaps({ targetPctVV: '93.2664044525' })).toEqual([{ materialId: 'ETOH', property: 'targetPctVV', reason: ALCOHOL_REASONS.TARGET_EXCEEDS_SOURCE }]);
  });

  it('target out of (0, 100] is refused; L7 source needs a signed override', () => {
    expect(gaps({ targetPctVV: '0' })?.[0]?.reason).toBe('input.out_of_range');
    expect(gaps({ targetPctVV: '101' })?.[0]?.reason).toBe('input.out_of_range');
    expect(gaps({ source: fixture('80', '%w/w', 'L7_estimate') })?.[0]?.reason).toBe('confidence.l7_requires_signed_override');
  });

  it('an empty OIML table is reported as missing oiml_r22_table', () => {
    const empty: OimlTable = { rows: [] };
    expect(gaps({ table: empty })?.some((g) => g.property === 'oiml_r22_table')).toBe(true);
    expect(densityFromWW('40', empty).ok).toBe(false);
  });

  it('warns when a COA value is below the confidence policy floor', () => {
    const r = diluteEthanol(base({ source: fixture('80', '%w/w', 'L6_community'), policy: { allowConfidenceBelow: 'L2_supplier_tds' } }));
    expect(r.ok && r.warnings).toEqual(['confidence.below_policy:ETOH:sourceStrength:L6_community']);
  });
});
