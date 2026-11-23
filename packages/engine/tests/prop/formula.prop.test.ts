/**
 * T-02 properties of the formula arithmetic: scaling never changes ratios, percentages add up to 100 or the engine says so.
 */
import fc from 'fast-check';
import { describe, expect, it } from 'vitest';
import { D, ENGINE_VERSION, balanceQs, calculate, qty, scaleFormula, validateFormula } from '../../src/index';
import { formulaLines, fromScaled, params, percentList, price } from './arbitraries';

const batchGrams = fc.integer({ min: 1, max: 1_000_000_000 });
const factor = fc.integer({ min: 1, max: 1000 });

describe('property: scaling a formula never changes its ratios', () => {
  it('mass_i × p_j = mass_j × p_i for every pair of lines, and Σ mass = batch mass exactly', () => {
    fc.assert(
      fc.property(formulaLines, batchGrams, (lines, grams) => {
        const r = scaleFormula(lines, qty(String(grams), 'g'));
        if (!r.ok) {
          throw new Error(`unexpected: ${JSON.stringify(r.errors)}`);
        }
        const masses = r.value.lines.map((l) => new D(l.mass.value));
        for (let i = 0; i < lines.length; i += 1) {
          for (let j = i + 1; j < lines.length; j += 1) {
            expect(masses[i]!.mul(lines[j]!.percentWW).eq(masses[j]!.mul(lines[i]!.percentWW))).toBe(true);
          }
        }
        expect(r.value.total.value).toBe(String(grams));
        expect(r.value.residual).toBe('0');
      }),
      params,
    );
  });

  it('multiplying the batch by k multiplies every line mass by exactly k (so each share of the total is unchanged)', () => {
    fc.assert(
      fc.property(formulaLines, batchGrams, factor, (lines, grams, k) => {
        const a = scaleFormula(lines, qty(String(grams), 'g'));
        const b = scaleFormula(lines, qty(String(grams * k), 'g'));
        if (!a.ok || !b.ok) {
          throw new Error('unexpected failure');
        }
        a.value.lines.forEach((l, i) => {
          expect(new D(b.value.lines[i]!.mass.value).eq(new D(l.mass.value).mul(k))).toBe(true);
        });
      }),
      params,
    );
  });

  it('the unit of the batch does not matter: n kg and n × 1000 g give the same masses in grams', () => {
    fc.assert(
      fc.property(formulaLines, fc.integer({ min: 1, max: 1_000_000 }), (lines, kg) => {
        const inKg = scaleFormula(lines, qty(String(kg), 'kg'));
        const inG = scaleFormula(lines, qty(String(kg * 1000), 'g'));
        if (!inKg.ok || !inG.ok) {
          throw new Error('unexpected failure');
        }
        inKg.value.lines.forEach((l, i) => {
          expect(qty(l.mass.value, 'kg').to('g').value.eq(inG.value.lines[i]!.mass.value)).toBe(true);
        });
      }),
      params,
    );
  });
});

describe('property: percentages sum to 100 or the engine reports it', () => {
  it('generated lists add up to exactly 100 and validate', () => {
    fc.assert(
      fc.property(formulaLines, (lines) => {
        const r = validateFormula(lines);
        expect(r.ok && [r.value.sum, r.value.deviation, r.value.withinTolerance]).toEqual(['100', '0', true]);
      }),
      params,
    );
  });

  it('moving one line by more than 0.001 makes the formula invalid with SUM_NOT_100 (never accepted)', () => {
    fc.assert(
      fc.property(percentList, fc.integer({ min: 2, max: 5000 }), (ps, thousandths) => {
        const lines = ps.map((percentWW, i) => ({ materialId: `M${i}`, percentWW }));
        lines[0] = { materialId: 'M0', percentWW: new D(ps[0]!).plus(fromScaled(thousandths, 3)).toFixed() };
        const r = validateFormula(lines);
        expect(r.ok).toBe(false);
        expect(!r.ok && r.errors.map((e) => e.messageKey)).toContain('formula.sum_not_100');
      }),
      params,
    );
  });

  it('a deviation of exactly 0.001 is inside the documented tolerance', () => {
    fc.assert(
      fc.property(percentList, (ps) => {
        const lines = ps.map((percentWW, i) => ({ materialId: `M${i}`, percentWW }));
        lines[0] = { materialId: 'M0', percentWW: new D(ps[0]!).plus('0.001').toFixed() };
        expect(validateFormula(lines).ok).toBe(true);
      }),
      params,
    );
  });

  it('q.s. balancing: the balanced line makes the sum exactly 100 and leaves every other line untouched, or fails (no room left)', () => {
    fc.assert(
      fc.property(fc.array(fc.integer({ min: 1, max: 40_000 }), { minLength: 1, maxLength: 6 }), (thousandths) => {
        const others = thousandths.map((t, i) => ({ materialId: `M${i}`, percentWW: fromScaled(t, 3) }));
        const total = thousandths.reduce((a, b) => a + b, 0);
        const r = balanceQs([...others, { materialId: 'W', qs: true }]);
        if (total < 100_000) {
          if (!r.ok) {
            throw new Error('unexpected failure');
          }
          expect(r.value.lines.reduce((acc, l) => acc.plus(l.percentWW), new D(0)).eq(100)).toBe(true);
          expect(r.value.lines.slice(0, -1)).toEqual(others);
        } else {
          expect(r.ok).toBe(false);
        }
      }),
      params,
    );
  });

  it('calculate() on a formula that does not add up reports it in missing[] and returns no figure at all', () => {
    fc.assert(
      fc.property(percentList, fc.integer({ min: 2, max: 5000 }), price, (ps, thousandths, p) => {
        const lines = ps.map((percentWW, i) => ({ materialId: `M${i}`, percentWW }));
        lines[0] = { materialId: 'M0', percentWW: new D(ps[0]!).plus(fromScaled(thousandths, 3)).toFixed() };
        const prices = Object.fromEntries(lines.map((l) => [l.materialId, { value: p, unit: 'EGP' as const, sourceId: 'P', confidence: 'L2_supplier_tds' as const, validFrom: '2026-01-01' }]));
        const out = calculate({ engineVersion: ENGINE_VERSION, datasetVersion: 'prop', formula: { lines }, prices, policy: { rounding: 'default' } });
        expect(out.ok).toBe(false);
        expect(out.missing.length).toBeGreaterThan(0);
        expect(Object.keys(out.results)).toEqual([]);
      }),
      params,
    );
  });
});
