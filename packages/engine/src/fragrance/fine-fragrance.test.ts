import { describe, expect, it } from 'vitest';
import type { SourcedValue } from '../contract';
import { qty } from '../core/quantity';
import { fixture } from '../test-fixtures';
import { inputLeaf, leavesOf } from '../trace/trace';
import { checkIfraLimit, fineFragrance, FRAGRANCE_REASONS, FRAGRANCE_WARNINGS, type FineFragranceInput } from './fine-fragrance';

/*
 * Hand checks (OIML R 22 rows of data/oiml-r22/table-20c.json, hit exactly):
 *   row %w/w 40.0 : %v/v 47.3947631607      row %w/w 29.6 : %v/v 35.7961398403
 *   row %w/w 31.6 : %v/v 38.0789469303      row %w/w 32.0 : %v/v 38.5326454115
 * Alcohol strength 80 %w/w, the IFRA limits and the density are TEST INPUTS (sourceId TEST-FIXTURE).
 * Carrier = ethanol-water at 47.3947631607 %v/v = 40 %w/w, made from 80 %w/w stock: stock = carrier / 2.
 */
const V40 = '47.3947631607';
const density = (value: string, confidence: SourcedValue['confidence'] = 'L1_coa_batch'): SourcedValue => ({
  value,
  unit: 'g/mL',
  sourceId: 'TEST-FIXTURE',
  confidence,
  validFrom: '2026-01-01',
});
const base = (over: Partial<FineFragranceInput> = {}): FineFragranceInput => ({
  fragranceId: 'FRAG-1',
  fragranceOilPct: '20',
  fixativePct: '1',
  alcoholSource: fixture('80', '%w/w', 'L1_coa_batch'),
  alcoholMaterialId: 'ETOH',
  alcoholTargetPctVV: V40,
  batch: { mass: qty('1', 'kg') },
  ifraLimitPct: fixture('25', '%w/w'),
  policy: {},
  ...over,
});
const run = (over: Partial<FineFragranceInput> = {}) => {
  const r = fineFragrance(base(over));
  if (!r.ok) {
    throw new Error(`expected ok: ${JSON.stringify(r.missing)}`);
  }
  return r;
};
const gaps = (over: Partial<FineFragranceInput>) => {
  const r = fineFragrance(base(over));
  return r.ok ? null : r.missing;
};

describe('fineFragrance: masses', () => {
  it('1000 g, oil 20 %, fixative 1 %: carrier 79 % = 790 g -> stock 395 g + water 395 g; oil 200 g; fixative 10 g', () => {
    const { value: r } = run();
    expect(r.carrierPctWW.value).toBe('79');
    expect([r.fragranceOilMass.value, r.fixativeMass?.value, r.alcoholMass.value, r.waterMass.value]).toEqual(['200', '10', '395', '395']);
    expect(r.totalMass.value).toBe('1000');
    expect(r.totalVolume).toBeUndefined();
  });

  it('final ethanol: 79 % x 0.40 = 31.6 %w/w (row 31.6 -> 38.0789469303 %v/v), flagged as indicator', () => {
    const r = run();
    expect(r.value.finalEthanolPctWW.value).toBe('31.6');
    expect(r.value.finalEthanolPctVV.value).toBe('38.0789469303');
    expect(r.warnings).toContain(FRAGRANCE_WARNINGS.FINAL_ETHANOL_INDICATOR_ONLY);
    expect(r.value.finalEthanolPctVV.trace.label).toMatch(/indicator/);
  });

  it('added water 5 %: carrier 74 % = 740 g -> stock 370 + dilution water 370 + added 50 = water 420; ethanol 74 x 0.4 = 29.6 %w/w (35.7961398403 %v/v)', () => {
    const { value: r } = run({ addedWaterPct: '5' });
    expect([r.alcoholMass.value, r.waterMass.value, r.finalEthanolPctWW.value, r.finalEthanolPctVV.value]).toEqual(['370', '420', '29.6', '35.7961398403']);
    expect(Number(r.fragranceOilMass.value) + Number(r.fixativeMass?.value) + Number(r.alcoholMass.value) + Number(r.waterMass.value)).toBe(1000);
  });

  it('no fixative given: no fixative line; oil 20 % carrier 80 %: stock 400 g, ethanol 32 %w/w (38.5326454115 %v/v)', () => {
    const { value: r } = run({ fixativePct: undefined });
    expect('fixativeMass' in r).toBe(false);
    expect([r.alcoholMass.value, r.waterMass.value, r.finalEthanolPctWW.value, r.finalEthanolPctVV.value]).toEqual(['400', '400', '32', '38.5326454115']);
  });

  it('VOLUME batch via a measured density: 100 mL x 0.89 g/mL = 89 g; oil 17.8 g, stock 35.6 g, water 35.6 g (oil 20 %, no fixative)', () => {
    const { value: r } = run({ fixativePct: undefined, batch: { volume: qty('100', 'mL') }, productDensity: density('0.89') });
    expect([r.totalMass.value, r.fragranceOilMass.value, r.alcoholMass.value, r.waterMass.value]).toEqual(['89', '17.8', '35.6', '35.6']);
    expect(r.totalVolume?.value).toBe('100');
  });

  it('a mass batch with a measured density also reports the volume: 1000 g / 0.89 = 1123.5955 mL', () => {
    const { value: r } = run({ productDensity: density('0.89') });
    expect(r.totalVolume?.value.startsWith('1123.5955056179')).toBe(true);
  });

  it('trace of the alcohol line reaches the COA leaf (sourceId, confidence)', () => {
    const { value: r } = run();
    const leaf = leavesOf(r.alcoholMass.trace).find((n) => n.label === 'Source ethanol strength');
    expect(leaf).toMatchObject({ sourceId: 'TEST-FIXTURE', confidence: 'L1_coa_batch' });
  });
});

describe('fineFragrance: IFRA hook', () => {
  it('oil 20 % vs limit 25 %: PASS, margin 5', () => {
    const { value: r } = run();
    expect([r.ifra.status, r.ifra.marginPct?.value, r.ifra.limitPct?.value]).toEqual(['PASS', '5', '25']);
  });

  it('oil equal to the limit passes (<=), margin 0; above the limit FAILs with a negative margin (20 vs 15 => -5)', () => {
    expect(run({ ifraLimitPct: fixture('20', '%w/w') }).value.ifra).toMatchObject({ status: 'PASS' });
    const fail = run({ ifraLimitPct: fixture('15', '%w/w') }).value.ifra;
    expect([fail.status, fail.marginPct?.value]).toEqual(['FAIL', '-5']);
  });

  it('no certificate limit => UNKNOWN (never PASS), with a gap and a warning', () => {
    const r = run({ ifraLimitPct: undefined });
    expect(r.value.ifra).toMatchObject({ status: 'UNKNOWN', missing: [{ materialId: 'FRAG-1', property: 'ifraLimitPct', reason: 'input.missing' }] });
    expect(r.value.ifra.limitPct).toBeUndefined();
    expect(r.warnings).toContain(FRAGRANCE_WARNINGS.IFRA_UNKNOWN);
  });

  it('an L7 estimate or a wrong unit is UNKNOWN too', () => {
    expect(run({ ifraLimitPct: fixture('25', '%w/w', 'L7_estimate') }).value.ifra.status).toBe('UNKNOWN');
    expect(run({ ifraLimitPct: fixture('25', 'g') }).value.ifra.missing[0]?.reason).toBe('input.unit_mismatch');
  });

  it('checkIfraLimit works standalone on a traced oil %', () => {
    const c = checkIfraLimit(inputLeaf('oil', '8', '%w/w'), fixture('7.5', '%w/w'), {});
    expect([c.status, c.marginPct?.value]).toEqual(['FAIL', '-0.5']);
  });
});

describe('fineFragrance: missing data', () => {
  it('a VOLUME batch without a measured density is missing productDensity', () => {
    expect(gaps({ batch: { volume: qty('100', 'mL') } })).toEqual([{ property: 'productDensity', reason: 'density.required_for_conversion' }]);
  });

  it('density is gated by policy: an L7 density is refused', () => {
    expect(gaps({ productDensity: density('0.89', 'L7_estimate') })?.[0]?.reason).toBe('confidence.l7_requires_signed_override');
  });

  it('missing COA strength / stated basis', () => {
    expect(gaps({ alcoholSource: undefined })).toEqual([{ materialId: 'ETOH', property: 'alcoholSource', reason: 'input.missing' }]);
    const { unit: _u, ...noUnit } = fixture('96');
    void _u;
    expect(gaps({ alcoholSource: noUnit })?.[0]?.reason).toBe('alcohol.basis_required');
  });

  it('lists every gap at once', () => {
    const g = gaps({ fragranceOilPct: 'x', fixativePct: '-1', addedWaterPct: '101', alcoholSource: undefined, alcoholTargetPctVV: '0', batch: { volume: qty('0', 'mL') } });
    expect(g?.map((x) => x.property)).toEqual(['fragranceOilPct', 'fixativePct', 'addedWaterPct', 'alcoholSource', 'alcoholTargetPctVV', 'batchVolume', 'productDensity']);
  });

  it('oil + fixative + water must leave room for the carrier', () => {
    expect(gaps({ fragranceOilPct: '60', fixativePct: '30', addedWaterPct: '10' })).toEqual([
      { materialId: 'FRAG-1', property: 'fragranceOilPct', reason: FRAGRANCE_REASONS.PERCENTS_EXCEED_100 },
    ]);
  });

  it('a carrier stronger than the stock cannot be made', () => {
    expect(gaps({ alcoholTargetPctVV: '93.2664044525' })?.[0]).toMatchObject({ property: 'targetPctVV', reason: 'alcohol.target_exceeds_source' });
  });

  it('an empty OIML table is reported', () => {
    expect(gaps({ table: { rows: [] } })?.some((g) => g.property === 'oiml_r22_table')).toBe(true);
  });
});
