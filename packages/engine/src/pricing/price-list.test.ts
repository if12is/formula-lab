import { describe, expect, it } from 'vitest';
import type { SourcedValue } from '../contract';
import { leavesOf } from '../trace/trace';
import { priceListLine, PRICE_LIST_REASONS, type PriceListLineInput, type PriceRuleInput } from './price-list';

// TEST-FIXTURE numbers (fictional margins and costs, chosen so every step is exact).
const egp = (value: string, sourceId = 'SRC-COST'): SourcedValue => ({ value, unit: 'EGP', sourceId, confidence: 'L3_standard', validFrom: '2026-11-21' });
const pct = (value: string): SourcedValue => ({ value, unit: '%w/w', sourceId: 'SRC-RULE', confidence: 'L6_community', validFrom: '2026-11-21' });
const rule = (over: Partial<PriceRuleInput> = {}): PriceRuleInput => ({ key: 'merchants', basis: 'markup_on_cost', marginPct: pct('25'), ...over });
const line = (over: Partial<PriceListLineInput> = {}): PriceListLineInput => ({
  unitCost: egp('40'),
  spec: { mode: 'rule', rule: rule() },
  policy: {},
  ...over,
});

describe('price list line, rule mode', () => {
  it('case 1, markup on cost: 32 + 25 % = 40, + distribution 10 = 50; margin 18 = 56.25 % of cost, 36 % of price', () => {
    // 32 × 1.25 = 40 ; 40 + 10 = 50 ; margin 50 − 32 = 18 ; 18 ÷ 32 = 0.5625 ; 18 ÷ 50 = 0.36
    const r = priceListLine(line({ unitCost: egp('32'), spec: { mode: 'rule', rule: rule({ distributionCostPerUnit: egp('10', 'SRC-RULE') }) } }));
    expect(r.ok && [r.value.publishedPrice, r.value.marginAmount.value, r.value.markupOnCostPct, r.value.marginOnPricePct, r.value.belowCost]).toEqual(['50.00', '18', '56.250', '36.000', false]);
  });

  it('case 2, margin on price: 40 ÷ (1 − 0.20) = 50; margin 10 = 25 % of cost, 20 % of price', () => {
    const r = priceListLine(line({ spec: { mode: 'rule', rule: rule({ basis: 'margin_on_price', marginPct: pct('20') }) } }));
    expect(r.ok && [r.value.publishedPrice, r.value.marginAmount.value, r.value.markupOnCostPct, r.value.marginOnPricePct]).toEqual(['50.00', '10', '25.000', '20.000']);
  });

  it('case 3, the published price is the exact price rounded half-even to 0.01, margins taken from the published one', () => {
    // 0.10 × 1.25 = 0.125 exactly: a tie, half-even gives 0.12 ; margin 0.12 − 0.10 = 0.02 = 20 % of cost, 16.667 % of price
    const r = priceListLine(line({ unitCost: egp('0.10'), spec: { mode: 'rule', rule: rule() } }));
    expect(r.ok && [r.value.exactPrice.value, r.value.publishedPrice, r.value.marginAmount.value, r.value.markupOnCostPct, r.value.marginOnPricePct]).toEqual(['0.125', '0.12', '0.02', '20.000', '16.667']);
  });

  it('a zero margin passes the cost through (an explicit zero is a value, not a gap)', () => {
    const r = priceListLine(line({ spec: { mode: 'rule', rule: rule({ marginPct: pct('0') }) } }));
    expect(r.ok && [r.value.publishedPrice, r.value.marginAmount.value, r.value.belowCost]).toEqual(['40.00', '0', false]);
  });

  it('the trace reaches the cost source, the rule margin source and the distribution', () => {
    const r = priceListLine(line({ spec: { mode: 'rule', rule: rule({ distributionCostPerUnit: egp('10', 'SRC-RULE') }) } }));
    if (!r.ok) {
      throw new Error('expected ok');
    }
    const leaves = leavesOf(r.value.price.trace);
    expect(leaves.map((l) => l.sourceId)).toEqual(expect.arrayContaining(['SRC-COST', 'SRC-RULE']));
    expect(leaves.map((l) => l.label)).toEqual(expect.arrayContaining(['Unit cost (EGP per unit)', 'Margin % (rule merchants)', 'Distribution cost per unit (rule merchants)']));
  });
});

describe('price list line, override mode', () => {
  it('case 4, an agreed price below cost is a result flagged belowCost: 40 against cost 50 = margin −10, −20 % of cost, −25 % of price', () => {
    const r = priceListLine(line({ unitCost: egp('50'), spec: { mode: 'override', price: egp('40', 'SRC-QUOTE') } }));
    expect(r.ok && [r.value.publishedPrice, r.value.marginAmount.value, r.value.markupOnCostPct, r.value.marginOnPricePct, r.value.belowCost]).toEqual(['40.00', '-10', '-20.000', '-25.000', true]);
  });

  it('a price equal to cost is not below cost', () => {
    const r = priceListLine(line({ unitCost: egp('40'), spec: { mode: 'override', price: egp('40', 'SRC-QUOTE') } }));
    expect(r.ok && [r.value.marginAmount.value, r.value.belowCost]).toEqual(['0', false]);
  });

  it('keeps the quote source on the published price trace', () => {
    const r = priceListLine(line({ spec: { mode: 'override', price: egp('55', 'SRC-QUOTE') } }));
    expect(r.ok && leavesOf(r.value.price.trace).some((l) => l.sourceId === 'SRC-QUOTE')).toBe(true);
  });
});

describe('price list line, missing data is never a number', () => {
  it('lists EVERY gap: no unit cost and no spec', () => {
    const r = priceListLine({ policy: {} });
    expect(!r.ok && r.missing.map((m) => [m.property, m.reason])).toEqual([
      ['priceList.unitCost', 'input.missing'],
      ['priceList.spec', PRICE_LIST_REASONS.SPEC_MISSING],
    ]);
  });

  it('a rule without margin and basis, and an override without a price, are gaps', () => {
    const noMargin = priceListLine(line({ spec: { mode: 'rule', rule: { key: 'merchants' } } }));
    expect(!noMargin.ok && noMargin.missing.map((m) => m.property)).toEqual(['priceList.marginBasis', 'priceList.marginPct']);
    const noPrice = priceListLine(line({ spec: { mode: 'override' } }));
    expect(!noPrice.ok && noPrice.missing.map((m) => [m.property, m.reason])).toEqual([['priceList.overridePrice', 'input.missing']]);
  });

  it('rejects a cost of zero, a non-EGP cost, a margin on price of 100 % and an unknown basis', () => {
    expect(priceListLine(line({ unitCost: egp('0') })).ok).toBe(false);
    expect(priceListLine(line({ unitCost: { ...egp('40'), unit: 'USD' } })).ok).toBe(false);
    const full = priceListLine(line({ spec: { mode: 'rule', rule: rule({ basis: 'margin_on_price', marginPct: pct('100') }) } }));
    expect(!full.ok && full.missing.map((m) => [m.property, m.reason])).toEqual([['priceList.marginPct', 'input.out_of_range']]);
    const basis = priceListLine(line({ spec: { mode: 'rule', rule: { ...rule(), basis: 'nonsense' as never } } }));
    expect(!basis.ok && basis.missing.map((m) => [m.property, m.reason])).toEqual([['priceList.marginBasis', PRICE_LIST_REASONS.INVALID_BASIS]]);
  });

  it('an L7 estimate for the margin is stopped by the confidence policy, an L6 one passes', () => {
    const estimate: SourcedValue = { ...pct('25'), confidence: 'L7_estimate' };
    expect(priceListLine(line({ spec: { mode: 'rule', rule: rule({ marginPct: estimate }) } })).ok).toBe(false);
    expect(priceListLine(line()).ok).toBe(true);
  });
});
