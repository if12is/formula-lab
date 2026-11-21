import { describe, expect, it } from 'vitest';
import { COMPLIANCE_REASONS, COMPLIANCE_WARNINGS, evaluateCompliance, overallStatus, type ComplianceReport } from './evaluate';
import { hashRuleSet } from './dsl';
import { COMPOSITION_REASONS } from './composition';
import { comp, ctx, formula, materials, rule, ruleSet, src } from './test-fixtures';
import type { Rule } from './dsl';
import type { FormulaComposition } from './composition';

/*
 * Hand calcs (fictional substances): formula M-A 5%, M-B 10%, M-C 85%.
 *   TEST-0001 = 5 x 10/100 + 10 x 2/100 = 0.5 + 0.2 = 0.7
 *   TEST-0003 = 10 x 98/100 = 9.8        TEST-0002 = 5 x 90/100 + 85 = 4.5 + 85 = 89.5
 */
const run = (rules: Rule[], f: FormulaComposition = formula(), context = ctx, over = {}): ComplianceReport => {
  const r = evaluateCompliance(f, ruleSet(rules, over), context);
  if (!r.ok) {
    throw new Error(JSON.stringify(r.missing));
  }
  return r.value;
};
const only = (rules: Rule[], f?: FormulaComposition, context = ctx) => run(rules, f, context).results[0]!;
const without = (id: string): FormulaComposition => ({
  lines: formula().lines,
  materials: materials().map((m) => (m.materialId === id ? { materialId: id } : m)),
});

describe('limit operators', () => {
  it.each([
    ['<=', '1', 'PASS', '0.3'],
    ['<=', '0.7', 'PASS', '0'],
    ['<=', '0.5', 'FAIL', '-0.2'],
    ['<', '0.7', 'FAIL', '0'],
    ['<', '0.8', 'PASS', '0.1'],
    ['>=', '0.7', 'PASS', '0'],
    ['>=', '0.9', 'FAIL', '-0.2'],
    ['>', '0.7', 'FAIL', '0'],
    ['>', '0.6', 'PASS', '0.1'],
    ['==', '0.7', 'PASS', '0'],
    ['==', '0.6', 'FAIL', '-0.1'],
  ] as const)('0.7 %s %s => %s (margin %s)', (operator, limit, status, margin) => {
    const r = only([rule({ id: 'r', operator, limit })]);
    expect(r.status).toBe(status);
    expect(r.value?.value).toBe('0.7');
    expect(r.valueComplete).toBe(true);
    expect(r.margin?.value).toBe(margin);
    expect(r.limit).toBe(limit);
    expect(r.sourceId).toBe('TEST-SRC');
    expect(r.messageKey).toBe('test.msg');
    expect(r.params).toMatchObject({ ruleId: 'r', target: 'TEST-0001', value: '0.7', limit, margin, unit: '%w/w' });
  });

  it('carries a trace down to sourced composition leaves', () => {
    const r = only([rule({ id: 'r' })]);
    const leaves = JSON.stringify(r.value?.trace);
    expect(leaves).toContain('TEST-FIXTURE');
    expect(r.value?.trace.inputs).toHaveLength(2);
    expect(r.margin?.trace.op).toBe('sub');
  });

  it('is exact decimal arithmetic (no float drift)', () => {
    const f: FormulaComposition = {
      lines: [{ materialId: 'X', percentWW: '0.1' }, { materialId: 'Y', percentWW: '0.2' }],
      materials: [
        { materialId: 'X', composition: comp([{ cas: 'TEST-0001', pctWW: '100' }]) },
        { materialId: 'Y', composition: comp([{ cas: 'TEST-0001', pctWW: '100' }]) },
      ],
    };
    const r = only([rule({ id: 'r', operator: '==', limit: '0.3' })], f);
    expect(r.value?.value).toBe('0.3');
    expect(r.status).toBe('PASS');
  });
});

describe('UNKNOWN on missing data (N-05)', () => {
  it('a material without composition makes the concentration UNKNOWN, never PASS', () => {
    const r = only([rule({ id: 'r', limit: '100' })], without('M-C'));
    expect(r.status).toBe('UNKNOWN');
    expect(r.valueComplete).toBe(false);
    expect(r.margin).toBeUndefined();
    expect(r.missing).toEqual([{ materialId: 'M-C', property: 'composition', reason: COMPOSITION_REASONS.MISSING }]);
  });

  it('known part already above an upper limit is FAIL even with unknown material', () => {
    // M-C unknown; known part of TEST-0001 = 0.7 > 0.5
    const r = only([rule({ id: 'r', limit: '0.5' })], without('M-C'));
    expect(r.status).toBe('FAIL');
    expect(r.valueComplete).toBe(false);
  });

  it('unknown for lower-limit operators when data is missing (a lower bound proves nothing)', () => {
    expect(only([rule({ id: 'r', operator: '>=', limit: '0.1' })], without('M-C')).status).toBe('UNKNOWN');
    expect(only([rule({ id: 'r', operator: '==', limit: '0.7' })], without('M-C')).status).toBe('UNKNOWN');
  });

  it('incomplete composition: substance not listed is unknown, listed is exact', () => {
    const f = formula();
    f.materials[2] = { materialId: 'M-C', composition: comp([{ cas: 'TEST-0002', pctWW: '100' }], { complete: false }) };
    const r = only([rule({ id: 'r', limit: '5' })], f);
    expect(r.status).toBe('UNKNOWN');
    expect(r.missing[0]?.reason).toBe(COMPOSITION_REASONS.INCOMPLETE);
    // a substance that IS listed in the incomplete composition is exact for that material
    f.materials[0] = { materialId: 'M-A', composition: comp([{ cas: 'TEST-0001', pctWW: '10' }], { complete: false }) };
    f.materials[1] = { materialId: 'M-B', composition: comp([{ cas: 'TEST-0001', pctWW: '2' }], { complete: false }) };
    f.materials[2] = { materialId: 'M-C', composition: comp([{ cas: 'TEST-0001', pctWW: '0' }], { complete: false }) };
    expect(only([rule({ id: 'r', limit: '1' })], f).status).toBe('PASS');
  });

  it('component with only an INCI name cannot rule a CAS target in or out', () => {
    const f = formula();
    f.materials[2] = { materialId: 'M-C', composition: comp([{ inci: 'AQUA', pctWW: '100' }]) };
    const r = only([rule({ id: 'r', limit: '5' })], f);
    expect(r.status).toBe('UNKNOWN');
    expect(r.missing[0]?.reason).toBe(COMPOSITION_REASONS.AMBIGUOUS);
    // matched by INCI instead: fine
    expect(only([rule({ id: 'r', target: { inci: 'aqua' }, limit: '95' })], f).value?.value).toBe('89.5');
  });

  it('invalid composition data (bad number, sum > 100, no identifier, undated) is unknown', () => {
    const bad = (c: ReturnType<typeof comp>) => {
      const f = formula();
      f.materials[2] = { materialId: 'M-C', composition: c };
      return only([rule({ id: 'r', limit: '5' })], f);
    };
    expect(bad(comp([{ cas: 'TEST-0002', pctWW: '1e2' }])).missing[0]?.reason).toBe(COMPOSITION_REASONS.INVALID);
    expect(bad(comp([{ cas: 'TEST-0002', pctWW: '-1' }])).status).toBe('UNKNOWN');
    expect(bad(comp([{ cas: 'TEST-0002', pctWW: '101' }])).status).toBe('UNKNOWN');
    expect(bad(comp([{ cas: 'TEST-0002', pctWW: '60' }, { inci: 'X', pctWW: '41' }])).status).toBe('UNKNOWN');
    expect(bad(comp([{ pctWW: '10' }])).status).toBe('UNKNOWN');
    expect(bad(comp([{ cas: ' ', pctWW: '10' }])).status).toBe('UNKNOWN');
    expect(bad(comp([{ cas: 'TEST-0002', pctWW: '100' }], { validFrom: 'nope' })).missing[0]?.reason).toBe(COMPOSITION_REASONS.NOT_DATED);
  });

  it('L7 composition needs a signed override; below-policy confidence warns', () => {
    const f = formula();
    f.materials[2] = { materialId: 'M-C', composition: comp([{ cas: 'TEST-0002', pctWW: '100' }], { confidence: 'L7_estimate' }) };
    const blocked = only([rule({ id: 'r', limit: '5' })], f);
    expect(blocked.status).toBe('UNKNOWN');
    expect(blocked.missing[0]?.reason).toBe('confidence.l7_requires_signed_override');
    const policy = { overrides: [{ materialId: 'M-C', property: 'composition', signedBy: 'chem', reason: 'test' }] };
    const ok = run([rule({ id: 'r', limit: '5' })], f, { ...ctx, policy });
    expect(ok.results[0]?.status).toBe('PASS');
    expect(ok.warnings).toContain('confidence.l7_override:M-C:composition');
  });

  it('invalid formula line makes concentrations unknown', () => {
    const f = formula();
    f.lines[0] = { materialId: 'M-A', percentWW: '5%' };
    const r = only([rule({ id: 'r' })], f);
    expect(r.status).toBe('UNKNOWN');
    expect(r.missing[0]).toEqual({ materialId: 'M-A', property: 'percentWW', reason: COMPOSITION_REASONS.FORMULA_LINE_INVALID });
    const g = only([rule({ id: 'r', target: { materialId: 'M-A' }, limit: '10' })], f);
    expect(g.status).toBe('UNKNOWN');
  });

  it('a 0% line with unknown composition does not matter', () => {
    const f = formula();
    f.lines.push({ materialId: 'M-Z', percentWW: '0' });
    expect(only([rule({ id: 'r' })], f).status).toBe('PASS');
  });

  it('a line whose material has no entry in `materials` is unknown', () => {
    const f = formula();
    f.lines.push({ materialId: 'M-Z', percentWW: '1' });
    expect(only([rule({ id: 'r' })], f).missing[0]?.materialId).toBe('M-Z');
  });

  it('duplicate material entries: the first wins', () => {
    const f = formula();
    f.materials.push({ materialId: 'M-C', composition: comp([{ cas: 'TEST-0001', pctWW: '100' }]) });
    expect(only([rule({ id: 'r' })], f).value?.value).toBe('0.7');
  });
});

describe('forbidden and required', () => {
  const forbid = (cas: string) => rule({ id: 'f', target: { substanceCas: cas }, operator: 'forbidden', limit: null });

  it('absent in complete compositions => PASS; present => FAIL', () => {
    const absent = only([forbid('TEST-0009')]);
    expect(absent.status).toBe('PASS');
    expect(absent.value?.value).toBe('0');
    expect(absent.margin).toBeUndefined();
    expect(only([forbid('TEST-0001')]).status).toBe('FAIL');
  });

  it('unknown composition => UNKNOWN; but known presence is still FAIL', () => {
    expect(only([forbid('TEST-0009')], without('M-C')).status).toBe('UNKNOWN');
    expect(only([forbid('TEST-0001')], without('M-C')).status).toBe('FAIL');
  });

  it('required: present PASS, absent FAIL, any missing data UNKNOWN', () => {
    const req = (cas: string) => rule({ id: 'q', target: { substanceCas: cas }, operator: 'required', limit: null });
    expect(only([req('TEST-0001')]).status).toBe('PASS');
    expect(only([req('TEST-0009')]).status).toBe('FAIL');
    expect(only([req('TEST-0009')], without('M-C')).status).toBe('UNKNOWN');
    expect(only([req('TEST-0001')], without('M-C')).status).toBe('UNKNOWN');
  });
});

describe('group rules (sum of c_i / limit_i <= 1)', () => {
  const g = (l1: string, l3: string) => [
    rule({ id: 'g1', group: 'G', limit: l1 }),
    rule({ id: 'g3', group: 'G', target: { substanceCas: 'TEST-0003' }, limit: l3 }),
  ];

  it('passes when the sum is <= 1 and reports it', () => {
    // 0.7/2 + 9.8/40 = 0.35 + 0.245 = 0.595
    const rep = run(g('2', '40'));
    expect(rep.groups).toEqual([
      { group: 'G', status: 'PASS', sumRatio: '0.595', complete: true, members: [{ ruleId: 'g1', ratio: '0.35', complete: true }, { ruleId: 'g3', ratio: '0.245', complete: true }] },
    ]);
    expect(rep.results.map((r) => r.status)).toEqual(['PASS', 'PASS']);
    expect(rep.results[0]?.params.groupSumRatio).toBe('0.595');
    expect(rep.overall).toBe('PASS');
  });

  it('boundary: sum exactly 1 passes; above fails (each member carries the group status)', () => {
    // 0.7/1.4 + 9.8/19.6 = 0.5 + 0.5 = 1
    expect(run(g('1.4', '19.6')).groups[0]).toMatchObject({ status: 'PASS', sumRatio: '1' });
    // 0.7/1 + 9.8/20 = 0.7 + 0.49 = 1.19
    const rep = run(g('1', '20'));
    expect(rep.groups[0]).toMatchObject({ status: 'FAIL', sumRatio: '1.19' });
    expect(rep.results.map((r) => r.status)).toEqual(['FAIL', 'FAIL']);
    expect(rep.overall).toBe('FAIL');
  });

  it('any unknown member => UNKNOWN, unless the known part already exceeds 1', () => {
    // M-B unknown: TEST-0001 known part = 0.5, TEST-0003 known = 0 => 0.5/1 + 0 = 0.5
    const f = without('M-B');
    const unk = run(g('1', '40'), f);
    expect(unk.groups[0]).toMatchObject({ status: 'UNKNOWN', complete: false, sumRatio: '0.5' });
    expect(unk.overall).toBe('UNKNOWN');
    // limit 0.4 => 0.5/0.4 = 1.25 > 1 => certain FAIL
    expect(run(g('0.4', '40'), f).groups[0]).toMatchObject({ status: 'FAIL', sumRatio: '1.25' });
  });

  it('out-of-scope members are left out of the sum; separate groups are independent', () => {
    const rules = [
      rule({ id: 'g1', group: 'G', limit: '2' }),
      rule({ id: 'g3', group: 'G', target: { substanceCas: 'TEST-0003' }, limit: '1', scope: { productTypes: ['other'] } }),
      rule({ id: 'h1', group: 'A', limit: '0.5' }),
    ];
    const rep = run(rules);
    expect(rep.groups.map((x) => [x.group, x.status, x.sumRatio])).toEqual([['A', 'FAIL', '1.4'], ['G', 'PASS', '0.35']]);
    expect(rep.results.map((r) => r.status)).toEqual(['PASS', 'NOT_APPLICABLE', 'FAIL']);
  });

  it('a pending-source member can never make the group PASS', () => {
    const rules = [rule({ id: 'g1', group: 'G', limit: '2', source: null, pendingSource: true }), rule({ id: 'g3', group: 'G', target: { substanceCas: 'TEST-0003' }, limit: '40' })];
    const rep = run(rules, formula(), ctx, { status: 'draft' });
    expect(rep.groups[0]?.status).toBe('UNKNOWN');
  });
});

describe('scope and not_applicable', () => {
  it('productType mismatch => NOT_APPLICABLE, not counted in overall', () => {
    const rep = run([rule({ id: 'a', limit: '0.1', scope: { productTypes: ['other'] } }), rule({ id: 'b', limit: '1' })]);
    expect(rep.results.map((r) => r.status)).toEqual(['NOT_APPLICABLE', 'PASS']);
    expect(rep.results[0]?.value).toBeUndefined();
    expect(rep.overall).toBe('PASS');
  });

  it('only not_applicable rules => PASS (nothing to check)', () => {
    expect(run([rule({ id: 'a', scope: { productTypes: ['other'] } })]).overall).toBe('PASS');
    expect(run([]).overall).toBe('PASS');
  });

  it('rinseOff and ifraCategory: mismatch => NOT_APPLICABLE, absent context => UNKNOWN', () => {
    const r = rule({ id: 'a', scope: { productTypes: ['test_product'], rinseOff: true, ifraCategory: '9' } });
    expect(only([r], formula(), { ...ctx, rinseOff: false, ifraCategory: '9' }).status).toBe('NOT_APPLICABLE');
    expect(only([r], formula(), { ...ctx, rinseOff: true, ifraCategory: '4' }).status).toBe('NOT_APPLICABLE');
    expect(only([r], formula(), { ...ctx, rinseOff: true, ifraCategory: '9' }).status).toBe('PASS');
    const unknown = only([r], formula(), ctx);
    expect(unknown.status).toBe('UNKNOWN');
    expect(unknown.missing.map((m) => m.property)).toEqual(['rinseOff', 'ifraCategory']);
    expect(unknown.missing[0]?.reason).toBe(COMPLIANCE_REASONS.SCOPE_UNKNOWN);
    // a definite mismatch on one attribute wins over an unknown other attribute
    expect(only([r], formula(), { ...ctx, rinseOff: false }).status).toBe('NOT_APPLICABLE');
  });
});

describe('other targets', () => {
  it('materialId target = the material share of the formula', () => {
    const r = only([rule({ id: 'm', target: { materialId: 'M-B' }, limit: '10' })]);
    expect(r.value?.value).toBe('10');
    expect(r.status).toBe('PASS');
    expect(only([rule({ id: 'm', target: { materialId: 'M-B' }, limit: '9' })]).status).toBe('FAIL');
    expect(only([rule({ id: 'm', target: { materialId: 'M-NONE' }, operator: 'forbidden', limit: null })]).status).toBe('PASS');
  });

  it('function target sums components with that function; unknown if functions are not declared', () => {
    // PRESERVATIVE = TEST-0001 = 0.7
    const r = only([rule({ id: 'p', target: { function: 'PRESERVATIVE' }, limit: '1' })]);
    expect(r.value?.value).toBe('0.7');
    expect(r.status).toBe('PASS');
    const f = formula();
    f.materials[2] = { materialId: 'M-C', composition: comp([{ cas: 'TEST-0002', pctWW: '100' }]) };
    expect(only([rule({ id: 'p', target: { function: 'PRESERVATIVE' }, limit: '1' })], f).status).toBe('UNKNOWN');
    // incomplete composition cannot give a function total
    f.materials[2] = { materialId: 'M-C', composition: comp([{ cas: 'TEST-0002', pctWW: '100', functions: ['SOLVENT'] }], { complete: false }) };
    expect(only([rule({ id: 'p', target: { function: 'PRESERVATIVE' }, limit: '1' })], f).status).toBe('UNKNOWN');
  });

  it('field target uses sourced product-level values; missing/invalid/unit mismatch => UNKNOWN', () => {
    const r = rule({ id: 'f', target: { field: 'pH' }, operator: '<=', limit: '9', unit: '%w/w' });
    const field = (v: object) => only([r], formula(), { ...ctx, fields: { pH: { sourceId: 'TEST-FIXTURE', confidence: 'L1_coa_batch', validFrom: '2026-01-01', ...v } as never } });
    expect(field({ value: '8.5' }).status).toBe('PASS');
    expect(field({ value: '8.5' }).margin?.value).toBe('0.5');
    expect(field({ value: '9.5' }).status).toBe('FAIL');
    expect(field({ value: '8.5', unit: '%w/w' }).status).toBe('PASS');
    expect(field({ value: '8.5', unit: 'g' }).missing[0]?.reason).toBe(COMPLIANCE_REASONS.FIELD_INVALID);
    expect(field({ value: 'abc' }).status).toBe('UNKNOWN');
    expect(field({ value: '8.5', validFrom: 'x' }).status).toBe('UNKNOWN');
    expect(only([r]).missing[0]).toEqual({ property: 'pH', reason: COMPLIANCE_REASONS.FIELD_MISSING });
    expect(field({ value: '8.5', confidence: 'L7_estimate' }).missing[0]?.reason).toBe('confidence.l7_requires_signed_override');
    const withWarning = run([r], formula(), { ...ctx, fields: { pH: { value: '8.5', sourceId: 'S', confidence: 'L6_community', validFrom: '2026-01-01' } }, policy: { allowConfidenceBelow: 'L2_supplier_tds' } });
    expect(withWarning.warnings.some((w) => w.startsWith('confidence.below_policy'))).toBe(true);
  });
});

describe('rule set handling', () => {
  it('pendingSource in a draft set: PASS is downgraded to UNKNOWN, FAIL stays FAIL, with warnings', () => {
    const pending = (over: Partial<Rule>) => rule({ id: 'p', source: null, pendingSource: true, ...over });
    const pass = run([pending({ limit: '1' })], formula(), ctx, { status: 'draft' });
    expect(pass.results[0]?.status).toBe('UNKNOWN');
    expect(pass.results[0]?.sourceId).toBeNull();
    expect(pass.results[0]?.missing).toEqual([{ property: 'ruleSource', reason: COMPLIANCE_REASONS.RULE_PENDING_SOURCE }]);
    expect(pass.warnings).toEqual([COMPLIANCE_WARNINGS.RULESET_DRAFT, `${COMPLIANCE_WARNINGS.RULE_PENDING_SOURCE}:p`]);
    expect(pass.overall).toBe('UNKNOWN');
    expect(run([pending({ limit: '0.1' })], formula(), ctx, { status: 'draft' }).overall).toBe('FAIL');
  });

  it('an invalid rule set gives missing, not a report', () => {
    const r = evaluateCompliance(formula(), ruleSet([rule({ id: 'p', source: null })]), ctx);
    expect(r.ok).toBe(false);
    if (!r.ok) {
      expect(r.missing).toEqual([{ property: 'ruleSet.rules[0].source', reason: `${COMPLIANCE_REASONS.RULESET_INVALID}:dsl.source_missing` }]);
    }
  });

  it('records the rule set id, version and hash; rule source and version are echoed', () => {
    const rs = ruleSet([rule({ id: 'a', version: '7' })]);
    const rep = run(rs.rules);
    expect(rep.ruleSetId).toBe('test-set');
    expect(rep.ruleSetVersion).toBe('1');
    expect(rep.ruleSetHash).toBe(hashRuleSet(rs));
    expect(rep.results[0]?.ruleVersion).toBe('7');
    expect(src.sourceId).toBe(rep.results[0]?.sourceId);
  });

  it('is deterministic: same inputs give identical reports; rule key order does not change the hash', () => {
    const rules = [rule({ id: 'a' }), rule({ id: 'b', group: 'G', limit: '3' })];
    expect(JSON.stringify(run(rules))).toBe(JSON.stringify(run(rules)));
    const shuffled = JSON.parse(JSON.stringify(rules.map((r) => Object.fromEntries(Object.entries(r).reverse())))) as Rule[];
    expect(run(shuffled).ruleSetHash).toBe(run(rules).ruleSetHash);
    // changing a limit changes the recorded hash
    expect(run([rule({ id: 'a', limit: '2' })]).ruleSetHash).not.toBe(run([rule({ id: 'a' })]).ruleSetHash);
  });
});

describe('overallStatus', () => {
  it('FAIL beats UNKNOWN beats PASS; NOT_APPLICABLE is ignored', () => {
    expect(overallStatus(['PASS', 'UNKNOWN', 'FAIL'])).toBe('FAIL');
    expect(overallStatus(['PASS', 'UNKNOWN', 'NOT_APPLICABLE'])).toBe('UNKNOWN');
    expect(overallStatus(['PASS', 'NOT_APPLICABLE'])).toBe('PASS');
    expect(overallStatus([])).toBe('PASS');
  });
});
