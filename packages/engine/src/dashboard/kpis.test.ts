import { describe, expect, it } from 'vitest';
import { buildCostReport } from '../costing/cost-report';
import type { Candidate } from '../data/resolve';
import { ctx, formula, rule, ruleSet } from '../compliance/test-fixtures';
import { costQuality, daysBetween, DASHBOARD_REASONS, formulaCompliance, isLowConfidence, marginBound, priceImpact, stalePrices } from './kpis';

// TEST-FIXTURE numbers: fictional prices, dates and limits chosen so every figure is hand-checkable.

describe('S-15 stalePrices', () => {
  it('daysBetween counts calendar days across a leap day: 2024-02-28 -> 2024-03-01 is 2, 2026-10-01 -> 2026-11-23 is 53', () => {
    expect(daysBetween('2024-02-28', '2024-03-01')).toBe(2);
    expect(daysBetween('2026-10-01', '2026-11-23')).toBe(53);
  });

  it('threshold 30 days on 2026-11-23: a quote of 2026-10-01 (53 days) is stale, 2026-11-10 (13) is not, 2026-10-24 (30) is not, no price is listed apart', () => {
    const r = stalePrices({
      asOf: '2026-11-23',
      thresholdDays: '30',
      materials: [
        { materialId: 'A', latestQuotedOn: '2026-10-01' },
        { materialId: 'B', latestQuotedOn: '2026-11-10' },
        { materialId: 'C', latestQuotedOn: '2026-10-24' },
        { materialId: 'D', latestQuotedOn: null },
      ],
    });
    expect(r.ok && [r.value.staleCount, r.value.checkedCount, r.value.stale, r.value.unpriced]).toEqual(['1', '3', [{ materialId: 'A', quotedOn: '2026-10-01', ageDays: '53' }], ['D']]);
  });

  it('a quote dated after the as-of date is not stale (age 0), and the oldest comes first', () => {
    const r = stalePrices({ asOf: '2026-11-23', thresholdDays: '10', materials: [{ materialId: 'X', latestQuotedOn: '2026-12-01' }, { materialId: 'Y', latestQuotedOn: '2026-11-01' }, { materialId: 'Z', latestQuotedOn: '2026-09-01' }] });
    expect(r.ok && r.value.stale.map((s) => [s.materialId, s.ageDays])).toEqual([['Z', '83'], ['Y', '22']]);
  });

  it('no threshold decided by the owner is missing (never "0 stale")', () => {
    const r = stalePrices({ asOf: '2026-11-23', thresholdDays: null, materials: [{ materialId: 'A', latestQuotedOn: '2020-01-01' }] });
    expect(!r.ok && r.missing).toEqual([{ property: 'dashboard.stalePriceDays', reason: DASHBOARD_REASONS.STALE_THRESHOLD_NOT_SET }]);
  });
});

describe('S-15 costQuality', () => {
  const q = (value: string, confidence: Candidate['confidence']): Candidate => ({ value, unit: 'EGP', sourceId: 'Q', confidence, validFrom: '2026-11-01' });

  it('L5 is fine, L6 and worse are low; the report lists the L6 line and its EGP price', () => {
    expect([isLowConfidence('L1_coa_batch'), isLowConfidence('L5_reference'), isLowConfidence('L6_community'), isLowConfidence('L7_estimate')]).toEqual([false, false, true, true]);
    const quotes = { A: [q('10', 'L5_reference')], B: [q('25', 'L6_community')] };
    const report = buildCostReport({ datasetVersion: 't', lines: [{ materialId: 'A', percentWW: '60' }, { materialId: 'B', percentWW: '40' }], quotes });
    const r = costQuality(report, quotes);
    expect(r.missing).toEqual([]);
    expect(r.lowConfidence).toEqual([{ materialId: 'B', confidence: 'L6_community' }]);
    expect(r.currentPrices).toEqual({ A: '10', B: '25' });
  });

  it('a material with no quote is in the report gaps and has no current price (not 0)', () => {
    const quotes = { A: [q('10', 'L2_supplier_tds')] };
    const report = buildCostReport({ datasetVersion: 't', lines: [{ materialId: 'A', percentWW: '60' }, { materialId: 'B', percentWW: '40' }], quotes });
    const r = costQuality(report, quotes);
    expect(r.missing.map((m) => m.materialId)).toContain('B');
    expect(r.currentPrices).toEqual({ A: '10' });
  });
});

describe('S-15 priceImpact and marginBound', () => {
  const lines = [{ materialId: 'A', percentWW: '60' }, { materialId: 'B', percentWW: '40' }];

  it('A 10 -> 11 at 60 % and B 25 -> 25 at 40 %: 16 -> 16.6 EGP/kg, +0.6, +3.75 %', () => {
    const r = priceImpact(lines, { A: '10', B: '25' }, { A: '11', B: '25' });
    expect(r.ok && [r.value.approvedCostPerKg, r.value.currentCostPerKg, r.value.deltaPerKg, r.value.deltaPct]).toEqual(['16', '16.6', '0.6', '3.75']);
  });

  it('a price that fell: B 25 -> 20 gives -2 EGP/kg and -12.5 %', () => {
    const r = priceImpact(lines, { A: '10', B: '25' }, { A: '10', B: '20' });
    expect(r.ok && [r.value.deltaPerKg, r.value.deltaPct]).toEqual(['-2', '-12.5']);
  });

  it('a material with no current price makes the impact missing, and an approved price of 0 is refused', () => {
    const a = priceImpact(lines, { A: '10', B: '25' }, { A: '11' });
    expect(!a.ok && a.missing).toEqual([{ materialId: 'B', property: 'price', reason: DASHBOARD_REASONS.PRICE_MISSING }]);
    const b = priceImpact(lines, { A: '0', B: '25' }, { A: '11', B: '25' });
    expect(!b.ok && b.missing[0]?.reason).toBe(DASHBOARD_REASONS.APPROVED_PRICE_INVALID);
  });

  it('unit cost 20, sell 25 (margin 20 %), materials +3.75 %: at the bound the cost is 20.75, the margin 17 %, 3 points less', () => {
    const r = marginBound({ unitCost: '20', sellPrice: '25', materialDeltaPct: '3.75' });
    expect(r.ok && [r.value.marginNowPct, r.value.marginAtBoundPct, r.value.deltaPoints]).toEqual(['20', '17', '-3']);
  });

  it('materials -12.5 % on cost 20 / sell 25: bound cost 17.5, margin 30 %, +10 points', () => {
    const r = marginBound({ unitCost: '20', sellPrice: '25', materialDeltaPct: '-12.5' });
    expect(r.ok && [r.value.marginAtBoundPct, r.value.deltaPoints]).toEqual(['30', '10']);
  });

  it('no published sell price: the margin effect is missing, not 0', () => {
    const r = marginBound({ unitCost: '20', sellPrice: null, materialDeltaPct: '3.75' });
    expect(!r.ok && r.missing).toEqual([{ property: 'sellPrice', reason: DASHBOARD_REASONS.SELL_PRICE_MISSING }]);
  });
});

describe('S-15 formulaCompliance', () => {
  // formula(): TEST-0001 is 0.7 % of the product.
  it('limit 1 %: PASS; limit 0.5 %: FAIL with the rule named', () => {
    const pass = formulaCompliance({ composition: formula(), ruleSets: [ruleSet([rule({ id: 'r1', limit: '1' })])], context: ctx });
    expect(pass.overall).toBe('PASS');
    const fail = formulaCompliance({ composition: formula(), ruleSets: [ruleSet([rule({ id: 'r1', limit: '0.5' })])], context: ctx });
    expect([fail.overall, fail.failedRuleIds]).toEqual(['FAIL', ['test-set:r1']]);
  });

  it('a material with no composition is UNKNOWN, never PASS; a failing rule beats an unknown one', () => {
    const noComposition = { lines: formula().lines, materials: formula().materials.filter((m) => m.materialId !== 'M-A') };
    const unknown = formulaCompliance({ composition: noComposition, ruleSets: [ruleSet([rule({ id: 'r1', limit: '1' })])], context: ctx });
    expect([unknown.overall, unknown.unknownRuleIds]).toEqual(['UNKNOWN', ['test-set:r1']]);
    const both = formulaCompliance({ composition: formula(), ruleSets: [ruleSet([rule({ id: 'f', limit: '0.5' }), rule({ id: 'g', target: { substanceCas: 'NO-SUCH-CAS' } })])], context: ctx });
    expect(both.overall).toBe('FAIL');
  });

  it('a rule set that names another product type does not apply: NOT_APPLICABLE, which is not a PASS', () => {
    const r = formulaCompliance({ composition: formula(), ruleSets: [ruleSet([rule({ id: 'r1', scope: { productTypes: ['other_product'] } })])], context: ctx });
    expect(r.overall).toBe('NOT_APPLICABLE');
  });
});
