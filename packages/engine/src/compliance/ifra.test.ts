import { describe, expect, it } from 'vitest';
import { checkIfraCompliance, ifraCategoryForFamily, IFRA_REASONS, type IfraCertificate, type IfraInput } from './ifra';
import { fixture } from '../test-fixtures';

/* Fictional certificates: oil F-1 limits {4: 20, 5A: 0.5, 9: 1.5}; oil F-2 limits {4: 8}. All TEST inputs. */
const cert = (limits: Record<string, string>): IfraCertificate => ({
  limitsByCategory: Object.fromEntries(Object.entries(limits).map(([k, v]) => [k, fixture(v, '%w/w', 'L4_regulatory_db')])),
});
const input = (over: Partial<IfraInput> = {}): IfraInput => ({
  ifraCategory: '4',
  lines: [
    { materialId: 'F-1', percentWW: '15' },
    { materialId: 'F-2', percentWW: '5' },
    { materialId: 'ETOH', percentWW: '80' },
  ],
  fragrances: [
    { materialId: 'F-1', certificate: cert({ '4': '20', '5A': '0.5', '9': '1.5' }) },
    { materialId: 'F-2', certificate: cert({ '4': '8' }) },
  ],
  ...over,
});

describe('checkIfraCompliance (N-04 / L-09)', () => {
  it('checks each fragrance oil against its own certificate for the product category', () => {
    const r = checkIfraCompliance(input());
    expect(r.results.map((x) => [x.materialId, x.category, x.status])).toEqual([['F-1', '4', 'PASS'], ['F-2', '4', 'PASS']]);
    expect(r.results[0]?.check?.marginPct?.value).toBe('5'); // 20 - 15
    expect(r.results[1]?.check?.marginPct?.value).toBe('3'); // 8 - 5
    expect(r.results[0]?.params).toEqual({ materialId: 'F-1', category: '4', value: '15', limit: '20', margin: '5' });
    expect(r.overall).toBe('PASS');
    expect(r.missing).toEqual([]);
  });

  it('one oil over its own limit fails the whole check even if the other passes', () => {
    const r = checkIfraCompliance(input({ lines: [{ materialId: 'F-1', percentWW: '15' }, { materialId: 'F-2', percentWW: '9' }] }));
    expect(r.results.map((x) => x.status)).toEqual(['PASS', 'FAIL']);
    expect(r.results[1]?.check?.marginPct?.value).toBe('-1');
    expect(r.overall).toBe('FAIL');
  });

  it('limit boundary passes; the same formula in another category uses that category limit (L-09)', () => {
    expect(checkIfraCompliance(input({ lines: [{ materialId: 'F-1', percentWW: '20' }] })).results[0]?.status).toBe('PASS');
    const in5A = checkIfraCompliance(input({ ifraCategory: '5A', fragrances: [input().fragrances[0]!] }));
    expect(in5A.results[0]?.status).toBe('FAIL'); // 15 > 0.5
    expect(in5A.results[0]?.params.limit).toBe('0.5');
    const in9 = checkIfraCompliance(input({ ifraCategory: '9', lines: [{ materialId: 'F-1', percentWW: '1.5' }], fragrances: [input().fragrances[0]!] }));
    expect(in9.results[0]?.status).toBe('PASS');
  });

  it('repeated lines of the same oil add up', () => {
    const r = checkIfraCompliance(input({ lines: [{ materialId: 'F-1', percentWW: '12' }, { materialId: 'F-1', percentWW: '9' }], fragrances: [input().fragrances[0]!] }));
    expect(r.results[0]?.status).toBe('FAIL');
    expect(r.results[0]?.params.value).toBe('21');
  });

  it('missing category, certificate or category limit => UNKNOWN, never PASS', () => {
    const noCat = checkIfraCompliance(input({ ifraCategory: undefined }));
    expect(noCat.overall).toBe('UNKNOWN');
    expect(noCat.results[0]?.missing).toEqual([{ materialId: 'F-1', property: 'ifraCategory', reason: IFRA_REASONS.CATEGORY_MISSING }]);
    expect(noCat.results[0]?.category).toBeNull();

    const noCert = checkIfraCompliance(input({ fragrances: [{ materialId: 'F-1' }, input().fragrances[1]!] }));
    expect(noCert.results.map((x) => x.status)).toEqual(['UNKNOWN', 'PASS']);
    expect(noCert.results[0]?.missing[0]?.reason).toBe(IFRA_REASONS.CERTIFICATE_MISSING);
    expect(noCert.overall).toBe('UNKNOWN');

    const noLimit = checkIfraCompliance(input({ ifraCategory: '12' }));
    expect(noLimit.results.map((x) => x.missing[0]?.reason)).toEqual([IFRA_REASONS.LIMIT_MISSING_FOR_CATEGORY, IFRA_REASONS.LIMIT_MISSING_FOR_CATEGORY]);
    expect(noLimit.overall).toBe('UNKNOWN');
    // prototype keys are not categories
    expect(checkIfraCompliance(input({ ifraCategory: 'toString' })).overall).toBe('UNKNOWN');
  });

  it('known FAIL wins over UNKNOWN for another oil', () => {
    const r = checkIfraCompliance(input({ lines: [{ materialId: 'F-1', percentWW: '25' }, { materialId: 'F-2', percentWW: '5' }], fragrances: [input().fragrances[0]!, { materialId: 'F-2' }] }));
    expect(r.overall).toBe('FAIL');
  });

  it('invalid limit values, unit mismatch and L7 limits are UNKNOWN (via checkIfraLimit)', () => {
    const bad = (limit: ReturnType<typeof fixture>) => checkIfraCompliance(input({ fragrances: [{ materialId: 'F-1', certificate: { limitsByCategory: { '4': limit } } }] })).results[0]!;
    expect(bad(fixture('abc', '%w/w')).status).toBe('UNKNOWN');
    expect(bad(fixture('20', 'g')).status).toBe('UNKNOWN');
    expect(bad({ ...fixture('20', '%w/w'), validFrom: 'x' }).status).toBe('UNKNOWN');
    expect(bad(fixture('20', '%w/w', 'L7_estimate')).missing[0]?.reason).toBe('confidence.l7_requires_signed_override');
    const warned = checkIfraCompliance(input({ policy: { allowConfidenceBelow: 'L3_standard' }, fragrances: [{ materialId: 'F-1', certificate: { limitsByCategory: { '4': fixture('20', '%w/w', 'L6_community') } } }] }));
    expect(warned.warnings).toEqual(['confidence.below_policy:F-1:ifraLimitPct:L6_community']);
    expect(warned.results[0]?.status).toBe('PASS');
  });

  it('a listed fragrance that is not in the formula is NOT_APPLICABLE; an invalid line makes UNKNOWN', () => {
    const r = checkIfraCompliance(input({ lines: [{ materialId: 'F-1', percentWW: '15' }] }));
    expect(r.results.map((x) => x.status)).toEqual(['PASS', 'NOT_APPLICABLE']);
    expect(r.overall).toBe('PASS');
    const bad = checkIfraCompliance(input({ lines: [{ materialId: 'F-1', percentWW: '1,5' }] }));
    expect(bad.overall).toBe('UNKNOWN');
    expect(bad.results[0]?.missing[0]?.reason).toBe(IFRA_REASONS.FORMULA_LINE_INVALID);
  });

  it('no fragrances => PASS with nothing to check', () => {
    expect(checkIfraCompliance(input({ fragrances: [] })).overall).toBe('PASS');
  });
});

describe('ifraCategoryForFamily', () => {
  it('maps through supplied data; unmapped family is undefined (-> UNKNOWN downstream)', () => {
    const mapping = { test_family_a: '4', test_family_b: '9' };
    expect(ifraCategoryForFamily('test_family_a', mapping)).toBe('4');
    expect(ifraCategoryForFamily('unmapped', mapping)).toBeUndefined();
    expect(ifraCategoryForFamily('constructor', mapping)).toBeUndefined();
  });
});
