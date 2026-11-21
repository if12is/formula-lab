/** TEST INPUTS ONLY: fictional substances (CAS "TEST-000x"), fictional limits and sources. */
import type { ComplianceLine, FormulaComposition, MaterialComposition, SourcedComposition } from './composition';
import type { Rule, RuleSet } from './dsl';
import type { ProductContext } from './evaluate';

export const comp = (components: SourcedComposition['components'], over: Partial<SourcedComposition> = {}): SourcedComposition => ({
  components,
  complete: true,
  sourceId: 'TEST-FIXTURE',
  confidence: 'L2_supplier_tds',
  validFrom: '2026-01-01',
  ...over,
});

/** M-A: 10% TEST-0001 (+ PRESERVATIVE function) / 90% water. M-B: 2% TEST-0001 / 98% TEST-0003. M-C: water. */
export const materials = (): MaterialComposition[] => [
  {
    materialId: 'M-A',
    composition: comp([
      { cas: 'TEST-0001', inci: 'TESTINOL', pctWW: '10', functions: ['PRESERVATIVE'] },
      { cas: 'TEST-0002', inci: 'AQUA', pctWW: '90', functions: ['SOLVENT'] },
    ]),
  },
  {
    materialId: 'M-B',
    composition: comp([
      { cas: 'TEST-0001', inci: 'TESTINOL', pctWW: '2', functions: ['PRESERVATIVE'] },
      { cas: 'TEST-0003', inci: 'PARFUM', pctWW: '98', functions: ['PERFUMING'] },
    ]),
  },
  { materialId: 'M-C', composition: comp([{ cas: 'TEST-0002', inci: 'AQUA', pctWW: '100', functions: ['SOLVENT'] }]) },
];

export const lines = (): ComplianceLine[] => [
  { materialId: 'M-A', percentWW: '5' },
  { materialId: 'M-B', percentWW: '10' },
  { materialId: 'M-C', percentWW: '85' },
];

/** TEST-0001 = 5*10/100 + 10*2/100 = 0.7 ; TEST-0003 = 10*98/100 = 9.8 ; TEST-0002 = 5*90/100 + 85 = 89.5 */
export const formula = (): FormulaComposition => ({ lines: lines(), materials: materials() });

export const src = { sourceId: 'TEST-SRC', title: 'Fictional source', ref: 'test ref 1' };

export const rule = (over: Partial<Rule> & { id: string }): Rule => ({
  version: '1',
  scope: { productTypes: ['test_product'] },
  target: { substanceCas: 'TEST-0001' },
  operator: '<=',
  limit: '1',
  unit: '%w/w',
  source: src,
  messageKey: 'test.msg',
  ...over,
});

export const ruleSet = (rules: Rule[], over: Partial<RuleSet> = {}): RuleSet => ({
  id: 'test-set',
  version: '1',
  status: 'published',
  rules,
  ...over,
});

export const ctx: ProductContext = { productType: 'test_product' };
