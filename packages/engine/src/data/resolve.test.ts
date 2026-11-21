import { describe, expect, it } from 'vitest';
import { resolveValue, type Candidate, type ManualOverride } from './resolve';

const c = (o: Partial<Candidate> & Pick<Candidate, 'sourceId'>): Candidate => ({
  value: '1',
  unit: 'g/mL',
  confidence: 'L5_reference',
  validFrom: '2026-01-01',
  ...o,
});

function chosenOf(r: ReturnType<typeof resolveValue>): Candidate {
  if (!r.ok || r.value.chosenBy !== 'rule') {
    throw new Error('expected rule resolution');
  }
  return r.value.chosen;
}

describe('resolveValue ordering', () => {
  it('highest confidence wins; lower ones are rejected with a reason', () => {
    const r = resolveValue('density', [
      c({ sourceId: 'ref', confidence: 'L5_reference', value: '0.90' }),
      c({ sourceId: 'coa', confidence: 'L1_coa_batch', value: '0.91' }),
      c({ sourceId: 'tds', confidence: 'L2_supplier_tds', value: '0.92' }),
    ]);
    expect(chosenOf(r).sourceId).toBe('coa');
    expect(r.ok && r.value.rejected.map((x) => [x.candidate.sourceId, x.reason])).toEqual([
      ['tds', 'lower confidence'],
      ['ref', 'lower confidence'],
    ]);
    expect(r.ok && r.value.conflict).toBeNull();
  });
  it('same confidence: latest validFrom wins, older rejected as "older"', () => {
    const r = resolveValue('density', [
      c({ sourceId: 'a', validFrom: '2025-06-01', value: '0.90' }),
      c({ sourceId: 'b', validFrom: '2026-03-01', value: '0.90' }),
    ]);
    expect(chosenOf(r).sourceId).toBe('b');
    expect(r.ok && r.value.rejected[0]?.reason).toBe('older');
  });
  it('same confidence and date: sourceId ascending', () => {
    const r = resolveValue('density', [c({ sourceId: 'zeta' }), c({ sourceId: 'alpha' })]);
    expect(chosenOf(r).sourceId).toBe('alpha');
    expect(r.ok && r.value.rejected[0]?.reason).toBe('tie broken by sourceId');
  });
  it('lotNo is the final deterministic tie-break; input order is irrelevant', () => {
    const list = [c({ sourceId: 's', lotNo: 'B2' }), c({ sourceId: 's', lotNo: 'A1' }), c({ sourceId: 's' })];
    const a = resolveValue('p', list);
    const b = resolveValue('p', [...list].reverse());
    expect(chosenOf(a).lotNo).toBeUndefined(); // '' sorts first
    expect(a).toEqual(b);
  });
});

describe('conflicts', () => {
  it('top-confidence candidates that disagree are flagged, newest still chosen', () => {
    const r = resolveValue('density', [
      c({ sourceId: 'a', validFrom: '2025-01-01', value: '0.90' }),
      c({ sourceId: 'b', validFrom: '2026-01-01', value: '0.95' }),
      c({ sourceId: 'low', confidence: 'L6_community', value: '0.5' }),
    ]);
    expect(chosenOf(r).sourceId).toBe('b');
    expect(r.ok && r.value.conflict?.candidates.map((x) => x.sourceId)).toEqual(['b', 'a']);
    expect(r.ok && r.value.conflict?.confidence).toBe('L5_reference');
    expect(r.warnings[0]).toContain('conflict');
  });
  it('numerically equal values ("1.0" vs "1") do not conflict', () => {
    const r = resolveValue('p', [c({ sourceId: 'a', value: '1.0' }), c({ sourceId: 'b', value: '1' })]);
    expect(r.ok && r.value.conflict).toBeNull();
  });
  it('disagreement only at a lower confidence is not a conflict', () => {
    const r = resolveValue('p', [
      c({ sourceId: 'a', confidence: 'L1_coa_batch' }),
      c({ sourceId: 'b', confidence: 'L6_community', value: '9' }),
      c({ sourceId: 'c', confidence: 'L6_community', value: '8' }),
    ]);
    expect(r.ok && r.value.conflict).toBeNull();
  });
  it('different units or ranges count as disagreement', () => {
    const u = resolveValue('p', [c({ sourceId: 'a', unit: 'g/mL' }), c({ sourceId: 'b', unit: 'kg/L' })]);
    expect(u.ok && u.value.conflict).not.toBeNull();
    const rg = resolveValue('p', [
      c({ sourceId: 'a', value: undefined, min: '1', max: '2' }),
      c({ sourceId: 'b', value: undefined, min: '1', max: '3' }),
    ]);
    expect(rg.ok && rg.value.conflict).not.toBeNull();
    const one = resolveValue('p', [c({ sourceId: 'a', value: undefined, min: '1' }), c({ sourceId: 'b', value: undefined, max: '1' })]);
    expect(one.ok && one.value.conflict).not.toBeNull();
    const same = resolveValue('p', [
      c({ sourceId: 'a', value: undefined, min: '1', max: '2' }),
      c({ sourceId: 'b', value: undefined, min: '1.0', max: '2' }),
    ]);
    expect(same.ok && same.value.conflict).toBeNull();
  });
});

describe('override', () => {
  const ov: ManualOverride = { value: '0.93', unit: 'g/mL', reason: 'measured in-house', by: 'chemist1', at: '2026-11-21', sourceId: 'lab:1' };
  it('override wins, is recorded, all candidates listed as overridden', () => {
    const r = resolveValue('density', [c({ sourceId: 'coa', confidence: 'L1_coa_batch' })], { override: ov });
    expect(r.ok).toBe(true);
    if (r.ok && r.value.chosenBy === 'override') {
      expect(r.value.chosen).toEqual(ov);
      expect(r.value.reason).toBe('measured in-house');
      expect(r.value.rejected).toMatchObject([{ reason: 'overridden', detail: 'measured in-house' }]);
      expect(r.value.conflict).toBeNull();
    } else {
      throw new Error('expected override');
    }
  });
  it('override works with no candidates; accepts ISO datetime', () => {
    expect(resolveValue('p', [], { override: { ...ov, at: '2026-11-21T10:00:00Z' } }).ok).toBe(true);
  });
  it('invalid override (blank reason, bad value, bad date, no by/source) => missing', () => {
    for (const bad of [{ reason: '  ' }, { value: 'x' }, { at: 'yesterday' }, { by: '' }, { sourceId: '' }]) {
      const r = resolveValue('p', [c({ sourceId: 'a' })], { override: { ...ov, ...bad } });
      expect(!r.ok && r.missing[0]?.reason).toContain('invalid_override');
    }
  });
});

describe('asOf, validation, missing', () => {
  it('asOf ignores future-dated values but reports them', () => {
    const r = resolveValue('p', [
      c({ sourceId: 'new', validFrom: '2026-06-01', confidence: 'L1_coa_batch' }),
      c({ sourceId: 'old', validFrom: '2025-06-01', confidence: 'L5_reference' }),
    ], { asOf: '2026-01-01' });
    expect(chosenOf(r).sourceId).toBe('old');
    expect(r.ok && r.value.rejected[0]).toMatchObject({ reason: 'after asOf' });
  });
  it('asOf is inclusive; invalid asOf => missing', () => {
    expect(chosenOf(resolveValue('p', [c({ sourceId: 'a', validFrom: '2026-01-01' })], { asOf: '2026-01-01' })).sourceId).toBe('a');
    expect(resolveValue('p', [c({ sourceId: 'a' })], { asOf: '2026/01/01' }).ok).toBe(false);
  });
  it('no candidates / all filtered => missing with material id', () => {
    const none = resolveValue('density', [], {}, 'mat1');
    expect(!none.ok && none.missing[0]).toEqual({ materialId: 'mat1', property: 'density', reason: 'no_candidates' });
    const filtered = resolveValue('density', [c({ sourceId: 'a', validFrom: '2030-01-01' })], { asOf: '2026-01-01' });
    expect(!filtered.ok && filtered.missing[0]?.reason).toBe('no_eligible_candidates');
  });
  it('malformed candidates are rejected, not chosen', () => {
    const r = resolveValue('p', [
      c({ sourceId: 'novalue', value: undefined }),
      c({ sourceId: 'badnum', value: '1e3' }),
      c({ sourceId: 'baddate', validFrom: '01/01/2026' }),
      c({ sourceId: '' }),
      c({ sourceId: 'good' }),
    ]);
    expect(chosenOf(r).sourceId).toBe('good');
    expect(r.ok && r.value.rejected.map((x) => x.detail)).toEqual([
      'no value, min or max',
      'non-decimal number',
      'validFrom is not an ISO date',
      'missing sourceId',
    ]);
    const onlyBad = resolveValue('p', [c({ sourceId: 'x', value: undefined })]);
    expect(onlyBad.ok).toBe(false);
  });
});
