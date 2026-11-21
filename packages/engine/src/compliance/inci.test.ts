import { describe, expect, it } from 'vitest';
import { buildInciList, INCI_REASONS } from './inci';
import { comp, ctx, formula, materials, rule, ruleSet } from './test-fixtures';
import type { FormulaComposition } from './composition';

/*
 * formula M-A 5 / M-B 10 / M-C 85 (see test-fixtures):
 *   AQUA = 5 x 90/100 + 85 = 89.5   PARFUM = 10 x 98/100 = 9.8   TESTINOL = 5 x 10/100 + 10 x 2/100 = 0.7
 */
const list = (f: FormulaComposition = formula(), options = {}) => {
  const r = buildInciList(f, options);
  if (!r.ok) {
    throw new Error(JSON.stringify(r.missing));
  }
  return r.value;
};

describe('buildInciList', () => {
  it('orders descending by concentration, merges same INCI, marks the 1% boundary', () => {
    const l = list();
    expect(l.items.map((i) => [i.inci, i.pct.value, i.belowOnePct])).toEqual([
      ['AQUA', '89.5', false],
      ['PARFUM', '9.8', false],
      ['TESTINOL', '0.7', true],
    ]);
    expect(l.belowOnePctStartIndex).toBe(2);
    expect(l.labelWarnings).toEqual([]);
    expect(l.items[0]?.pct.trace.inputs).toHaveLength(2); // merged from M-A and M-C
  });

  it('breaks ties by INCI name; exactly 1% is not below the boundary; no boundary when all >= 1%', () => {
    const f: FormulaComposition = {
      lines: [{ materialId: 'X', percentWW: '100' }],
      materials: [
        {
          materialId: 'X',
          composition: comp([
            { inci: 'zeta', pctWW: '49.5' },
            { inci: ' Alpha ', pctWW: '49.5' },
            { inci: 'Mid', pctWW: '1' },
          ]),
        },
      ],
    };
    const l = list(f);
    expect(l.items.map((i) => i.inci)).toEqual(['ALPHA', 'ZETA', 'MID']);
    expect(l.items.every((i) => !i.belowOnePct)).toBe(true);
    expect(l.belowOnePctStartIndex).toBeNull();
  });

  it('sub-1% ingredients keep a deterministic order (descending, then name)', () => {
    const f: FormulaComposition = {
      lines: [{ materialId: 'X', percentWW: '100' }],
      materials: [{ materialId: 'X', composition: comp([{ inci: 'AQUA', pctWW: '98' }, { inci: 'B', pctWW: '0.5' }, { inci: 'A', pctWW: '0.5' }, { inci: 'C', pctWW: '1' }]) }],
    };
    const l = list(f);
    expect(l.items.map((i) => i.inci)).toEqual(['AQUA', 'C', 'A', 'B']);
    expect(l.belowOnePctStartIndex).toBe(2);
  });

  it('drops zero-concentration entries and zero-percent lines', () => {
    const f = formula();
    f.lines.push({ materialId: 'M-Z', percentWW: '0' });
    f.materials[2] = { materialId: 'M-C', composition: comp([{ inci: 'AQUA', pctWW: '100' }, { inci: 'NONE', pctWW: '0' }]) };
    expect(list(f).items.map((i) => i.inci)).toEqual(['AQUA', 'PARFUM', 'TESTINOL']);
  });

  it('unknown composition => missing, listing every such material', () => {
    const f = formula();
    f.materials = materials().filter((m) => m.materialId === 'M-A');
    const r = buildInciList(f);
    expect(r.ok).toBe(false);
    if (!r.ok) {
      expect(r.missing).toEqual([
        { materialId: 'M-B', property: 'composition', reason: INCI_REASONS.COMPOSITION_MISSING },
        { materialId: 'M-C', property: 'composition', reason: INCI_REASONS.COMPOSITION_MISSING },
      ]);
    }
  });

  it('incomplete or invalid composition, missing INCI name, invalid line => missing', () => {
    const reasonsOf = (f: FormulaComposition) => {
      const r = buildInciList(f);
      return r.ok ? [] : r.missing.map((m) => m.reason);
    };
    const f = formula();
    f.materials[2] = { materialId: 'M-C', composition: comp([{ inci: 'AQUA', pctWW: '100' }], { complete: false }) };
    expect(reasonsOf(f)).toEqual([INCI_REASONS.COMPOSITION_INCOMPLETE]);
    f.materials[2] = { materialId: 'M-C', composition: comp([{ inci: 'AQUA', pctWW: '101' }]) };
    expect(reasonsOf(f)).toEqual([INCI_REASONS.COMPOSITION_INVALID]);
    f.materials[2] = { materialId: 'M-C', composition: comp([{ cas: 'TEST-0002', pctWW: '100' }]) };
    expect(reasonsOf(f)).toEqual([INCI_REASONS.NAME_MISSING]);
    f.materials[2] = { materialId: 'M-C', composition: comp([{ inci: 'AQUA', pctWW: '100' }], { confidence: 'L7_estimate' }) };
    expect(reasonsOf(f)).toEqual([INCI_REASONS.COMPOSITION_INVALID]);
    const g = formula();
    g.lines[0] = { materialId: 'M-A', percentWW: 'x' };
    expect(reasonsOf(g)).toEqual([INCI_REASONS.FORMULA_LINE_INVALID]);
  });

  it('keeps policy warnings on success', () => {
    const f = formula();
    f.materials[2] = { materialId: 'M-C', composition: comp([{ inci: 'AQUA', pctWW: '100' }], { confidence: 'L6_community' }) };
    const r = buildInciList(f, { policy: { allowConfidenceBelow: 'L2_supplier_tds' } });
    expect(r.ok && r.warnings).toEqual(['confidence.below_policy:M-C:composition:L6_community']);
  });

  describe('Annex III style label-warning hook', () => {
    const rs = ruleSet([
      rule({ id: 'w1', operator: '<=', limit: '5', labelWarningKey: 'label.contains_testinol' }),
      rule({ id: 'w2', target: { inci: 'parfum' }, labelWarningKey: 'label.contains_parfum', limit: '20' }),
      rule({ id: 'w3', target: { substanceCas: 'TEST-0009' }, labelWarningKey: 'label.contains_absent', limit: '1' }),
      rule({ id: 'w4', labelWarningKey: 'label.other_product', scope: { productTypes: ['other'] } }),
      rule({ id: 'w5' }),
    ]);

    it('adds the key for present substances only, honouring scope', () => {
      const l = list(formula(), { ruleSet: rs, productContext: ctx });
      expect(l.labelWarnings.map((w) => [w.key, w.ruleId, w.substance, w.pct.value, w.sourceId])).toEqual([
        ['label.contains_testinol', 'w1', 'TEST-0001', '0.7', 'TEST-SRC'],
        ['label.contains_parfum', 'w2', 'parfum', '9.8', 'TEST-SRC'],
      ]);
    });

    it('a pending-source rule keeps sourceId null; an invalid rule set is reported', () => {
      const pending = ruleSet([rule({ id: 'w1', labelWarningKey: 'k', source: null, pendingSource: true })], { status: 'draft' });
      expect(list(formula(), { ruleSet: pending, productContext: ctx }).labelWarnings[0]?.sourceId).toBeNull();
      const bad = buildInciList(formula(), { ruleSet: ruleSet([rule({ id: 'x', source: null })]), productContext: ctx });
      expect(bad.ok).toBe(false);
    });

    it('a CAS-targeted warning that cannot be decided is reported, never skipped', () => {
      const f = formula();
      f.materials[2] = { materialId: 'M-C', composition: comp([{ inci: 'AQUA', pctWW: '100' }]) };
      f.materials[0] = { materialId: 'M-A', composition: comp([{ inci: 'AQUA', pctWW: '100' }]) };
      f.materials[1] = { materialId: 'M-B', composition: comp([{ inci: 'PARFUM', pctWW: '100' }]) };
      const r = buildInciList(f, { ruleSet: ruleSet([rule({ id: 'w1', labelWarningKey: 'k' })]), productContext: ctx });
      expect(r.ok).toBe(false);
      if (!r.ok) {
        expect(r.missing).toEqual([{ property: 'w1', reason: INCI_REASONS.LABEL_WARNING_UNDECIDABLE }]);
      }
    });
  });
});
