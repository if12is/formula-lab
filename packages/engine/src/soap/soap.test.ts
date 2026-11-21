import { describe, expect, it } from 'vitest';
import { qty } from '../core/quantity';
import { leavesOf } from '../trace/trace';
import { fixture } from '../test-fixtures';
import { soapLye, SOAP_REASONS, SOAP_WARNINGS, type SaponifiableOil, type SoapInput } from './soap';

// SAP values below are TEST INPUTS (sourceId TEST-FIXTURE), not oil data. f = M_NaOH/M_KOH = 0.712882757637...
const koh = (id: string, g: string, typ: string, extra: Partial<SaponifiableOil['sap']> = {}): SaponifiableOil => ({
  oilId: id,
  mass: qty(g, 'g'),
  sap: { unit: 'mgKOH/g', typ: fixture(typ), ...extra },
});
const input = (over: Partial<SoapInput> = {}): SoapInput => ({
  oils: [koh('OIL-A', '500', '200')],
  base: 'KOH',
  purity: fixture('90', '%w/w', 'L1_coa_batch'),
  superfatPct: '10',
  policy: {},
  ...over,
});
/** Truncates (not rounds) a decimal string to n places, so hand-computed digits compare exactly. */
const dp = (s: string | undefined, n: number) => (s === undefined ? s : s.slice(0, s.indexOf('.') + 1 + n));

describe('soapLye: Lye = Σ m·SAP × (1 − SF/100) / purity', () => {
  it('KOH: 500 g × 200 mgKOH/g = 100 g; SF 10 % => 90 g; purity 90 % => 100 g', () => {
    const r = soapLye(input());
    expect(r.ok && [r.value.used.value, r.value.typ.value]).toEqual(['100', '100']);
  });

  it('NaOH from mgKOH/g: 1000 g × 190/1000 × f = 135.44772...; SF 5, purity 97', () => {
    // 190 × 0.712882757637 = 135.447723...; × 0.95 = 128.675337...; / 0.97 = 132.654987...
    const r = soapLye(input({ oils: [koh('OIL-A', '1000', '190')], base: 'NaOH', superfatPct: '5', purity: fixture('97', '%w/w') }));
    expect(r.ok && dp(r.value.used.value, 6)).toBe('132.654987');
  });

  it('mixed units: OIL-A 600 g 190 mgKOH/g + OIL-B 400 g 0.128 gNaOH/g, NaOH, SF 5, purity 97', () => {
    // A: 600 × 190/1000 × f = 81.268634...; B: 400 × 0.128 = 51.2 (direct); Σ = 132.468634...
    // × 0.95 / 0.97 = 129.737322...
    const oils: SaponifiableOil[] = [
      koh('OIL-A', '600', '190'),
      { oilId: 'OIL-B', mass: qty('400', 'g'), sap: { unit: 'gNaOH/g', typ: fixture('0.128') } },
    ];
    const r = soapLye(input({ oils, base: 'NaOH', superfatPct: '5', purity: fixture('97', '%w/w') }));
    expect(r.ok && dp(r.value.used.value, 6)).toBe('129.737322');
  });

  it('gNaOH/g converted to a KOH base: 400 g × 0.128 / f = 71.821066...', () => {
    const r = soapLye(input({ oils: [{ oilId: 'OIL-B', mass: qty('400', 'g'), sap: { unit: 'gNaOH/g', typ: fixture('0.128') } }], superfatPct: '0', purity: fixture('100', '%w/w') }));
    expect(r.ok && dp(r.value.typ.value, 6)).toBe('71.821066');
  });

  it('superfat 0 is allowed; SF 100 and negative are rejected', () => {
    const zero = soapLye(input({ superfatPct: '0', purity: fixture('100', '%w/w') }));
    expect(zero.ok && zero.value.used.value).toBe('100');
    for (const sf of ['100', '-1', 'x']) {
      const r = soapLye(input({ superfatPct: sf }));
      expect(!r.ok && r.missing[0]).toMatchObject({ property: 'superfatPct', reason: sf === 'x' ? 'input.invalid_number' : 'input.out_of_range' });
    }
  });
});

describe('SAP range (min / typ / max) and COA lot', () => {
  const oils = (lotA?: string): SaponifiableOil[] => [
    koh('OIL-A', '600', '190', { min: fixture('188'), max: fixture('192'), ...(lotA && { lot: fixture(lotA, undefined, 'L1_coa_batch') }) }),
    { oilId: 'OIL-B', mass: qty('400', 'g'), sap: { unit: 'gNaOH/g', typ: fixture('0.128'), min: fixture('0.126'), max: fixture('0.130') } },
  ];
  const run = (lotA?: string) => soapLye(input({ oils: oils(lotA), base: 'NaOH', superfatPct: '5', purity: fixture('97', '%w/w') }));

  it('returns lye at min / typ / max and the spread', () => {
    // min: (600×188/1000×f + 400×0.126) × 0.95/0.97 = 128.115996...
    // typ: 129.737322...   max: (600×192/1000×f + 400×0.130) × 0.95/0.97 = 131.358648...
    // spread = 131.358648 − 128.115996 = 3.242652...; % of typ = 3.242652 / 129.737322 × 100 = 2.499398...
    const r = run();
    if (!r.ok) {
      throw new Error('expected ok');
    }
    expect([dp(r.value.min?.value, 6), dp(r.value.typ.value, 6), dp(r.value.max?.value, 6)]).toEqual(['128.115996', '129.737322', '131.358648']);
    expect([dp(r.value.spread?.absolute.value, 6), dp(r.value.spread?.pctOfTyp.value, 6)]).toEqual(['3.242652', '2.499398']);
    expect(r.value.usedBasis).toEqual({ 'OIL-A': 'typ', 'OIL-B': 'typ' });
    expect(r.value.used.value).toBe(r.value.typ.value);
  });

  it('a COA lot value takes precedence over typ for "used"; typ stays reported', () => {
    // OIL-A lot 191: 600×191/1000×f = 81.69... => (81.7... + 51.2) × 0.95/0.97 = 130.156232...
    const r = run('191');
    if (!r.ok) {
      throw new Error('expected ok');
    }
    expect(dp(r.value.used.value, 6)).toBe('130.156232');
    expect(dp(r.value.typ.value, 6)).toBe('129.737322');
    expect(r.value.usedBasis).toEqual({ 'OIL-A': 'lot', 'OIL-B': 'typ' });
    const lotLeaf = leavesOf(r.value.used.trace).find((n) => n.label.includes('SAP lot'));
    expect(lotLeaf).toMatchObject({ value: '191', sourceId: 'TEST-FIXTURE', confidence: 'L1_coa_batch' });
  });

  it('range is omitted (with a warning naming the oils) unless EVERY oil has min and max', () => {
    const r = soapLye(input({ oils: [oils()[0] as SaponifiableOil, koh('OIL-C', '100', '250')] }));
    if (!r.ok) {
      throw new Error('expected ok');
    }
    expect(r.value.min).toBeUndefined();
    expect(r.value.max).toBeUndefined();
    expect(r.value.spread).toBeUndefined();
    expect(r.warnings).toContain(`${SOAP_WARNINGS.SAP_RANGE_UNAVAILABLE}:OIL-C`);
  });

  it('rejects an inconsistent range (min > typ or max < typ)', () => {
    const r = soapLye(input({ oils: [koh('OIL-A', '100', '190', { min: fixture('195') }), koh('OIL-B', '100', '190', { max: fixture('180') })] }));
    expect(!r.ok && r.missing).toEqual([
      { materialId: 'OIL-A', property: 'sap.range', reason: SOAP_REASONS.SAP_RANGE_INCONSISTENT },
      { materialId: 'OIL-B', property: 'sap.range', reason: SOAP_REASONS.SAP_RANGE_INCONSISTENT },
    ]);
  });
});

describe('missing data', () => {
  it('missing purity is reported, never defaulted to 100 %', () => {
    const r = soapLye(input({ purity: undefined as never }));
    expect(!r.ok && r.missing).toEqual([{ materialId: undefined, property: 'purity', reason: 'input.missing' }]);
  });

  it('lists EVERY gap across oils, purity and superfat', () => {
    const r = soapLye(input({
      oils: [koh('OIL-A', '100', '0'), { ...koh('OIL-B', '0', '190') }, { oilId: 'OIL-C', mass: qty('100', 'g'), sap: { unit: 'mgKOH/g', typ: undefined as never } }],
      purity: fixture('0', '%w/w'),
      superfatPct: '-5',
    }));
    expect(!r.ok && r.missing.map((m) => [m.materialId ?? '-', m.property, m.reason])).toEqual([
      ['OIL-A', 'sap.typ', 'input.out_of_range'],
      ['OIL-B', 'mass', 'input.invalid_mass'],
      ['OIL-C', 'sap.typ', 'input.missing'],
      ['-', 'purity', 'input.out_of_range'],
      ['-', 'superfatPct', 'input.out_of_range'],
    ]);
  });

  it('no oils', () => {
    const r = soapLye(input({ oils: [] }));
    expect(!r.ok && r.missing[0]?.reason).toBe(SOAP_REASONS.NO_OILS);
  });
});

describe('water inside soapLye', () => {
  it('computes the water plan from the "used" lye and total oils', () => {
    // lye 100 g, oils 500 g; 40 % of oils => water 200 g; ratio 2; concentration 100/300 = 33.33 %
    const r = soapLye(input({ water: { method: 'pctOfOils', value: '40' } }));
    if (!r.ok || r.value.water === undefined) {
      throw new Error('expected ok with water');
    }
    expect(r.value.oilsMass.value).toBe('500');
    expect([r.value.water.waterMass.value, r.value.water.ratioWaterToLye.value, r.value.water.solutionMass.value]).toEqual(['200', '2', '300']);
  });

  it('a bad water spec fails the whole result and keeps earlier warnings', () => {
    const r = soapLye(input({ oils: [koh('OIL-A', '500', '200')], water: { method: 'ratio', value: '0' } }));
    expect(!r.ok && r.missing[0]).toMatchObject({ property: 'water.ratio' });
    expect(r.warnings).toContain(`${SOAP_WARNINGS.SAP_RANGE_UNAVAILABLE}:OIL-A`);
  });
});
