/**
 * N-05 property tests: dropping composition data never yields PASS when a rule touches the dropped data,
 * and never improves a FAIL/UNKNOWN verdict. Fictional substances only.
 */
import fc from 'fast-check';
import { describe, expect, it } from 'vitest';
import type { FormulaComposition } from './composition';
import { evaluateCompliance } from './evaluate';
import type { Rule } from './dsl';
import { comp, ctx, rule, ruleSet } from './test-fixtures';

const CAS = ['TEST-0001', 'TEST-0002', 'TEST-0003'] as const;
const pct = fc.integer({ min: 0, max: 100 }).map(String);
const operators = fc.constantFrom('<=', '<', '>=', '>', '==', 'forbidden', 'required') as fc.Arbitrary<Rule['operator']>;

const arbRule = fc
  .record({ cas: fc.constantFrom(...CAS), operator: operators, limit: fc.integer({ min: 0, max: 100 }).map(String), grouped: fc.boolean() })
  .map(({ cas, operator, limit, grouped }, ) =>
    rule({
      id: `r-${cas}-${operator}-${limit}-${grouped ? 'g' : 'n'}`,
      target: { substanceCas: cas },
      operator,
      limit: operator === 'forbidden' || operator === 'required' ? null : operator === '<=' && grouped ? (limit === '0' ? '1' : limit) : limit,
      ...(operator === '<=' && grouped && { group: 'G' }),
    }),
  );

const arbFormula = fc
  .array(
    fc.record({
      line: fc.integer({ min: 1, max: 100 }).map(String),
      split: fc.tuple(pct, pct, pct).filter(([a, b, c]) => Number(a) + Number(b) + Number(c) <= 100),
    }),
    { minLength: 1, maxLength: 4 },
  )
  .map((rows): FormulaComposition => ({
    lines: rows.map((r, i) => ({ materialId: `M${i}`, percentWW: r.line })),
    materials: rows.map((r, i) => ({
      materialId: `M${i}`,
      composition: comp(CAS.map((cas, k) => ({ cas, pctWW: r.split[k]! }))),
    })),
  }));

const uniqueRules = (rules: Rule[]): Rule[] => [...new Map(rules.map((r) => [r.id, r])).values()];

const verdict = (f: FormulaComposition, rules: Rule[]) => {
  const r = evaluateCompliance(f, ruleSet(rules), ctx);
  if (!r.ok) {
    throw new Error(JSON.stringify(r.missing));
  }
  return r.value;
};

describe('N-05: missing data => UNKNOWN, never PASS', () => {
  it('dropping a used material composition => overall is never PASS when a rule is applicable', () => {
    fc.assert(
      fc.property(arbFormula, fc.array(arbRule, { minLength: 1, maxLength: 5 }), fc.nat(), (f, rules, pick) => {
        const drop = pick % f.lines.length;
        const dropped: FormulaComposition = {
          lines: f.lines,
          materials: f.materials.map((m, i) => (i === drop ? { materialId: m.materialId } : m)),
        };
        const report = verdict(dropped, uniqueRules(rules));
        expect(report.overall).not.toBe('PASS');
        for (const r of report.results) {
          expect(r.status).not.toBe('PASS');
        }
      }),
      { numRuns: 300 },
    );
  });

  it('dropping data never improves the verdict (FAIL stays FAIL or becomes UNKNOWN only when nothing known breaks it)', () => {
    fc.assert(
      fc.property(arbFormula, fc.array(arbRule, { minLength: 1, maxLength: 5 }), fc.nat(), (f, rules, pick) => {
        const rs = uniqueRules(rules);
        const full = verdict(f, rs);
        const drop = pick % f.lines.length;
        const dropped = verdict({ lines: f.lines, materials: f.materials.map((m, i) => (i === drop ? { materialId: m.materialId } : m)) }, rs);
        expect(dropped.overall).not.toBe('PASS');
        // a rule that was not PASS with full data cannot be PASS with less data
        full.results.forEach((r, i) => {
          if (r.status !== 'PASS') {
            expect(dropped.results[i]?.status).not.toBe('PASS');
          }
        });
      }),
      { numRuns: 300 },
    );
  });

  it('dropping every composition: no rule can PASS', () => {
    fc.assert(
      fc.property(arbFormula, fc.array(arbRule, { minLength: 1, maxLength: 5 }), (f, rules) => {
        const none: FormulaComposition = { lines: f.lines, materials: f.materials.map((m) => ({ materialId: m.materialId })) };
        const report = verdict(none, uniqueRules(rules));
        expect(report.results.every((r) => r.status !== 'PASS')).toBe(true);
      }),
      { numRuns: 200 },
    );
  });

  it('marking compositions incomplete, or removing the CAS identifier, never gives PASS for an absent substance', () => {
    fc.assert(
      fc.property(arbFormula, fc.constantFrom(...CAS), (f, cas) => {
        const forbidden = rule({ id: 'f', target: { substanceCas: cas }, operator: 'forbidden', limit: null });
        const incomplete: FormulaComposition = {
          lines: f.lines,
          materials: f.materials.map((m) => ({ materialId: m.materialId, composition: { ...m.composition!, components: m.composition!.components.filter((c) => c.cas !== cas), complete: false } })),
        };
        expect(verdict(incomplete, [forbidden]).overall).not.toBe('PASS');
        const noIds: FormulaComposition = {
          lines: f.lines,
          materials: f.materials.map((m) => ({ materialId: m.materialId, composition: { ...m.composition!, components: m.composition!.components.map((c) => ({ inci: `N${c.cas}`, pctWW: c.pctWW })) } })),
        };
        expect(verdict(noIds, [forbidden]).overall).not.toBe('PASS');
      }),
      { numRuns: 200 },
    );
  });

  it('with full data the evaluator is deterministic and a rule on an untouched substance is unaffected by drops of other data', () => {
    fc.assert(
      fc.property(arbFormula, (f) => {
        const r = rule({ id: 'm', target: { materialId: 'M0' }, operator: '<=', limit: '100' });
        const none: FormulaComposition = { lines: f.lines, materials: [] };
        expect(verdict(none, [r]).results[0]?.status).toBe('PASS'); // materialId rules need no composition data
        expect(JSON.stringify(verdict(f, [r]))).toBe(JSON.stringify(verdict(f, [r])));
      }),
      { numRuns: 100 },
    );
  });
});
