import { describe, expect, it } from 'vitest';
import methanolJson from '../../data/rules/methanol-fragrance.json';
import { validateRuleSet, type RuleSet } from './dsl';
import { evaluateCompliance } from './evaluate';
import { comp } from './test-fixtures';

describe('N-09 methanol rule (data, pending source)', () => {
  it('validates as a draft rule set with pendingSource and no source', () => {
    const r = validateRuleSet(methanolJson);
    expect(r.ok).toBe(true);
    const rule = (methanolJson as RuleSet).rules[0]!;
    expect(rule).toMatchObject({ target: { substanceCas: '67-56-1' }, operator: 'forbidden', limit: null, source: null, pendingSource: true });
    expect((methanolJson as RuleSet).status).toBe('draft');
  });

  it('is rejected if someone publishes it without a source', () => {
    expect(validateRuleSet({ ...methanolJson, status: 'published' }).ok).toBe(false);
  });

  const formula = (cas: string, complete = true) => ({
    lines: [{ materialId: 'ETOH', percentWW: '80' }, { materialId: 'OIL', percentWW: '20' }],
    materials: [
      { materialId: 'ETOH', composition: comp([{ cas: '64-17-5', pctWW: '99' }, { cas, pctWW: '1' }], { complete }) },
      { materialId: 'OIL', composition: comp([{ cas: 'TEST-0003', pctWW: '100' }]) },
    ],
  });
  const run = (f: ReturnType<typeof formula>, productType: string) => {
    const r = evaluateCompliance(f, methanolJson as RuleSet, { productType });
    if (!r.ok) {
      throw new Error('invalid');
    }
    return r.value;
  };

  it('FAILs automatically when methanol is present in a fine fragrance / air freshener', () => {
    expect(run(formula('67-56-1'), 'fine_fragrance').overall).toBe('FAIL');
    expect(run(formula('67-56-1'), 'air_freshener').results[0]?.value?.value).toBe('0.8');
  });

  it('absent methanol can not yield PASS while the rule has no source (UNKNOWN); other products are not applicable', () => {
    const ok = run(formula('TEST-0009'), 'fine_fragrance');
    expect(ok.overall).toBe('UNKNOWN');
    expect(ok.results[0]?.status).toBe('UNKNOWN');
    expect(ok.warnings).toContain('compliance.rule_pending_source:methanol-forbidden-alcoholic-fragrance');
    expect(run(formula('67-56-1'), 'shampoo').results[0]?.status).toBe('NOT_APPLICABLE');
  });
});
