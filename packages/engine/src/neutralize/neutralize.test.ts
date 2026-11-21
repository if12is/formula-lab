import { describe, expect, it } from 'vitest';
import { qty } from '../core/quantity';
import { leavesOf } from '../trace/trace';
import { fixture } from '../test-fixtures';
import { NEUTRALIZE_WARNINGS, neutralize, type NeutralizeInput } from './neutralize';

// AV, purity and strength below are TEST INPUTS (sourceId TEST-FIXTURE), not real COA data.
// Ratio f = M_NaOH / M_KOH = 39.997 / 56.106 = 0.71288275763732934...
const base = (over: Partial<NeutralizeInput> = {}): NeutralizeInput => ({
  acidMass: qty('1000', 'g'),
  acidId: 'LABSA',
  acidValue: fixture('180', 'mgKOH/g', 'L1_coa_batch'),
  base: 'NaOH',
  baseId: 'NAOH',
  form: 'solid',
  strength: fixture('99', '%w/w', 'L1_coa_batch'),
  policy: {},
  ...over,
});
/** Truncates (not rounds) a decimal string to n places, so hand-computed digits compare exactly. */
const dp = (s: string | undefined, n: number) => (s === undefined ? s : s.slice(0, s.indexOf('.') + 1 + n));

describe('neutralize (m_NaOH = m_LABSA × AV/1000 × M_NaOH/M_KOH / purity)', () => {
  it('NaOH solid: 1000 g LABSA, AV 180, purity 99 %', () => {
    // pure = 1000 × 180/1000 × 0.712882757... = 128.318896...; / 0.99 = 129.615046...
    const r = neutralize(base());
    expect(r.ok && [dp(r.value.pureBaseMass.value, 6), dp(r.value.reagentMass.value, 6)]).toEqual(['128.318896', '129.615046']);
    expect(r.ok && r.value.waterInReagent).toBeUndefined();
  });

  it('NaOH solid: 500 g LABSA, AV 195.5, purity 98 %', () => {
    // pure = 500 × 195.5/1000 × 0.712882757... = 69.684289...; / 0.98 = 71.106417...
    const r = neutralize(base({ acidMass: qty('0.5', 'kg'), acidValue: fixture('195.5', 'mgKOH/g'), strength: fixture('98', '%w/w') }));
    expect(r.ok && [dp(r.value.pureBaseMass.value, 6), dp(r.value.reagentMass.value, 6)]).toEqual(['69.684289', '71.106417']);
  });

  it('KOH variant has no molar-mass ratio: 1000 × 180/1000 = 180 g pure; purity 90 % => 200 g', () => {
    const r = neutralize(base({ base: 'KOH', strength: fixture('90', '%w/w') }));
    expect(r.ok && [r.value.pureBaseMass.value, r.value.reagentMass.value]).toEqual(['180', '200']);
  });

  it('solution variant: 50 % NaOH solution => reagent = pure / 0.5, water = reagent − pure', () => {
    // pure 128.318896...; solution = 256.637792...; water = 128.318896...
    const r = neutralize(base({ form: 'solution', strength: fixture('50', '%w/w') }));
    expect(r.ok && [dp(r.value.reagentMass.value, 6), dp(r.value.waterInReagent?.value, 6)]).toEqual(['256.637792', '128.318896']);
  });

  it('KOH 45 % solution: 100 g LABSA AV 180 => pure 18 g, solution 40 g, water 22 g', () => {
    const r = neutralize(base({ acidMass: qty('100', 'g'), base: 'KOH', form: 'solution', strength: fixture('45', '%w/w') }));
    expect(r.ok && [r.value.pureBaseMass.value, r.value.reagentMass.value, r.value.waterInReagent?.value]).toEqual(['18', '40', '22']);
  });

  it('always warns that the result is a theoretical start and final pH is measured', () => {
    const r = neutralize(base());
    expect(r.warnings).toContain(NEUTRALIZE_WARNINGS.THEORETICAL_START);
  });

  it('trace cites AV and purity sources and the molar-mass constants', () => {
    const r = neutralize(base());
    if (!r.ok) {
      throw new Error('expected ok');
    }
    const leaves = leavesOf(r.value.reagentMass.trace);
    expect(leaves.find((n) => n.label === 'Acid value (mg KOH/g)')).toMatchObject({ value: '180', sourceId: 'TEST-FIXTURE', confidence: 'L1_coa_batch' });
    expect(leaves.find((n) => n.label === 'M_NaOH (g/mol)')).toMatchObject({ value: '39.997', sourceId: 'IUPAC-STD-ATOMIC-WEIGHTS' });
    expect(leaves.find((n) => n.label === 'M_KOH (g/mol)')?.value).toBe('56.106');
  });

  it('missing AV and purity are BOTH reported (never a silent 100 %)', () => {
    const r = neutralize(base({ acidValue: undefined as never, strength: undefined as never }));
    expect(!r.ok && r.missing.map((m) => [m.materialId, m.property, m.reason])).toEqual([
      ['LABSA', 'acidValue', 'input.missing'],
      ['NAOH', 'purity', 'input.missing'],
    ]);
  });

  it('solution concentration is reported under its own property; bad values are rejected', () => {
    const r = neutralize(base({ acidMass: qty('0', 'g'), form: 'solution', strength: fixture('0', '%w/w'), acidValue: fixture('180', '%w/w') }));
    expect(!r.ok && r.missing.map((m) => [m.property, m.reason])).toEqual([
      ['acidMass', 'input.invalid_mass'],
      ['acidValue', 'input.unit_mismatch'],
      ['concentration', 'input.out_of_range'],
    ]);
  });

  it('applies the confidence policy to the COA values', () => {
    const l7 = neutralize(base({ strength: fixture('99', '%w/w', 'L7_estimate') }));
    expect(!l7.ok && l7.missing[0]?.reason).toBe('confidence.l7_requires_signed_override');
    const low = neutralize(base({ strength: fixture('99', '%w/w', 'L6_community'), policy: { allowConfidenceBelow: 'L3_standard' } }));
    expect(low.ok && low.warnings.some((w) => w.startsWith('confidence.below_policy:'))).toBe(true);
  });

  it('falls back to generic labels when material ids are not given', () => {
    const r = neutralize(base({ acidId: undefined, baseId: undefined }));
    expect(r.ok && leavesOf(r.value.reagentMass.trace).map((n) => n.label)).toContain('NaOH purity (%)');
    const s = neutralize(base({ acidId: undefined, baseId: undefined, form: 'solution', strength: fixture('50', '%w/w') }));
    expect(s.ok && leavesOf(s.value.reagentMass.trace).map((n) => n.label)).toContain('NaOH concentration (%)');
  });
});
