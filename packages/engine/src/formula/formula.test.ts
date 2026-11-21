import { describe, expect, it } from 'vitest';
import { qty } from '../core/quantity';
import { balanceQs } from './qs';
import { scaleFormula } from './scale';
import { draftSummary, validateFormula } from './validate';

const L = (materialId: string, percentWW: string) => ({ materialId, percentWW });

describe('validateFormula', () => {
  it('accepts Σ = 100 and Σ within ±0.001', () => {
    expect(validateFormula([L('A', '60'), L('B', '40')]).ok).toBe(true);
    expect(validateFormula([L('A', '60.001'), L('B', '40')]).ok).toBe(true);
    expect(validateFormula([L('A', '59.999'), L('B', '40')]).ok).toBe(true);
    const r = validateFormula([L('A', '33.333'), L('B', '33.333'), L('C', '33.334')]);
    expect(r.ok && r.value).toEqual({ sum: '100', deviation: '0', withinTolerance: true });
  });

  it('rejects just outside tolerance with sum and deviation, as a message key', () => {
    const r = validateFormula([L('A', '60.0011'), L('B', '40')]);
    expect(r.ok).toBe(false);
    if (!r.ok) {
      expect(r.errors).toEqual([
        { code: 'SUM_NOT_100', messageKey: 'formula.sum_not_100', detail: { sum: '100.0011', deviation: '0.0011' } },
      ]);
    }
  });

  it('rejects empty, zero, negative, duplicate and unparseable, all at once', () => {
    expect(validateFormula([])).toMatchObject({ ok: false, errors: [{ code: 'EMPTY', messageKey: 'formula.empty' }] });
    const r = validateFormula([L('A', '0'), L('B', '-5'), L('C', '105'), L('C', '0.5'), L('D', 'x')]);
    expect(r.ok).toBe(false);
    if (!r.ok) {
      expect(r.errors.map((e) => [e.code, e.materialId])).toEqual([
        ['NON_POSITIVE_PERCENT', 'A'],
        ['NON_POSITIVE_PERCENT', 'B'],
        ['INVALID_PERCENT', 'D'],
        ['DUPLICATE_MATERIAL', 'C'],
      ]);
    }
  });

  it('reports a duplicate only once even with three copies', () => {
    const r = validateFormula([L('A', '40'), L('A', '30'), L('A', '30')]);
    expect(!r.ok && r.errors.filter((e) => e.code === 'DUPLICATE_MATERIAL')).toHaveLength(1);
  });
});

describe('draftSummary', () => {
  it('reports sum and deviation without failing', () => {
    expect(draftSummary([L('A', '30'), L('B', '20.5')])).toEqual({
      sum: '50.5', deviation: '-49.5', withinTolerance: false, issues: [],
    });
    const d = draftSummary([L('A', '30'), L('A', '-1'), L('B', 'z')]);
    expect(d.issues.map((i) => i.code)).toEqual(['NON_POSITIVE_PERCENT', 'INVALID_PERCENT', 'DUPLICATE_MATERIAL']);
    expect(d.sum).toBe('29');
  });
});

describe('scaleFormula', () => {
  it('mᵢ = M × pᵢ/100 and Σmᵢ = M exactly when Σp = 100', () => {
    const r = scaleFormula([L('A', '33.333'), L('B', '33.333'), L('C', '33.334')], qty('1', 'kg'));
    expect(r.ok).toBe(true);
    if (r.ok) {
      expect(r.value.lines.map((l) => l.mass.value)).toEqual(['0.33333', '0.33333', '0.33334']);
      expect(r.value.total.value).toBe('1');
      expect(r.value.residual).toBe('0');
      expect(r.warnings).toEqual([]);
      expect(r.value.lines[0]?.mass.trace.inputs.map((i) => i.op)).toEqual(['input', 'input']);
      expect(r.value.lines[0]?.mass.unit).toBe('kg');
    }
  });

  it('keeps the batch unit and flags the unallocated residual in the tolerance case', () => {
    const r = scaleFormula([L('A', '60.001'), L('B', '40')], qty('1000', 'g'));
    expect(r.ok).toBe(true);
    if (r.ok) {
      expect(r.value.total.value).toBe('1000.01');
      expect(r.value.residual).toBe('-0.01');
      expect(r.warnings).toEqual(['formula.scaled_sum_not_exact']);
    }
  });

  it('refuses invalid formulas and non-mass batch', () => {
    expect(scaleFormula([L('A', '50')], qty('1', 'kg')).ok).toBe(false);
    expect(() => scaleFormula([L('A', '100')], qty('1', 'L'))).toThrow();
  });
});

describe('balanceQs', () => {
  const lines = [
    { materialId: 'SLES', percentWW: '10' },
    { materialId: 'CAPB', percentWW: '5.5' },
    { materialId: 'WATER', qs: true, percentWW: '50' },
    { materialId: 'SALT', percentWW: '1' },
  ];

  it('balances only the qs line and records the event; result passes strict validation', () => {
    const r = balanceQs(lines);
    expect(r.ok).toBe(true);
    if (r.ok) {
      expect(r.value.balanceEvent).toEqual({ materialId: 'WATER', before: '50', after: '83.5' });
      expect(r.value.lines.map((l) => l.percentWW)).toEqual(['10', '5.5', '83.5', '1']);
      expect(validateFormula(r.value.lines).ok).toBe(true);
    }
  });

  it('leaves every other line untouched so active matter is unchanged', () => {
    const active: Record<string, string> = { SLES: '0.7', CAPB: '0.3', SALT: '1' };
    const r = balanceQs(lines);
    if (!r.ok) {
      throw new Error('expected ok');
    }
    const activeOf = (ls: { materialId: string; percentWW?: string }[]) =>
      ls
        .filter((l) => l.materialId in active)
        .map((l) => [l.materialId, l.percentWW, active[l.materialId]]);
    expect(activeOf(r.value.lines)).toEqual(activeOf(lines));
  });

  it('before is null when the qs line has no percent', () => {
    const r = balanceQs([{ materialId: 'A', percentWW: '40' }, { materialId: 'W', qs: true }]);
    expect(r.ok && r.value.balanceEvent).toEqual({ materialId: 'W', before: null, after: '60' });
  });

  it('errors: none, more than one, balance <= 0, bad others, duplicate', () => {
    expect(balanceQs([{ materialId: 'A', percentWW: '100' }])).toMatchObject({ ok: false, errors: [{ code: 'QS_MISSING' }] });
    const two = balanceQs([{ materialId: 'A', qs: true }, { materialId: 'B', qs: true }]);
    expect(!two.ok && two.errors.map((e) => [e.code, e.materialId])).toEqual([['QS_MULTIPLE', 'A'], ['QS_MULTIPLE', 'B']]);
    const full = balanceQs([{ materialId: 'A', percentWW: '100' }, { materialId: 'W', qs: true }]);
    expect(!full.ok && full.errors[0]).toMatchObject({ code: 'QS_NON_POSITIVE_BALANCE', messageKey: 'formula.qs_balance_not_positive', materialId: 'W' });
    expect(balanceQs([{ materialId: 'A', percentWW: '120' }, { materialId: 'W', qs: true }]).ok).toBe(false);
    const bad = balanceQs([{ materialId: 'A', percentWW: '-1' }, { materialId: 'A', percentWW: '5' }, { materialId: 'W', qs: true, percentWW: 'q' }]);
    expect(!bad.ok && bad.errors.map((e) => e.code)).toEqual(['NON_POSITIVE_PERCENT', 'DUPLICATE_MATERIAL', 'INVALID_PERCENT']);
  });
});
