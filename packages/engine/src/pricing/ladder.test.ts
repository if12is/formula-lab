import { describe, expect, it } from 'vitest';
import { buildCostReport } from '../costing/cost-report';
import type { Candidate } from '../data/resolve';
import { fxSensitivity, ladderForward, ladderReverse, usdLinkedCost, LADDER_REASONS, type LadderForwardInput } from './ladder';
import type { TaxRegime } from './tax';

// TEST-FIXTURE numbers (fictional rates, chosen so every step is exact).
const vat14: TaxRegime = { productFamilyId: 'FAM', taxType: 'vat', ratePct: '14', validFrom: '2026-01-01', sourceId: 'TAX-TEST', confidence: 'L4_regulatory_db' };
const tax = { setting: { mode: 'rate', ratePct: '14' } as const, regimes: [vat14], productFamilyId: 'FAM', taxType: 'vat', asOf: '2026-06-01' };
const rates = { marginBasis: 'markup_on_cost', factoryMarginPct: '20', distributionCostPerUnit: '2', wholesalerMarginPct: '25', retailerMarginPct: '25' } as const;
const unitCost = (value: string) => ({ value, unit: 'EGP' as const, sourceId: 'COST-SHEET', confidence: 'L2_supplier_tds' as const, validFrom: '2026-05-01' });
const forward = (over: Partial<LadderForwardInput> = {}): LadderForwardInput => ({ ...rates, unitCost: unitCost('25'), tax, ...over });

describe('S-13 ladderForward (cost -> shelf)', () => {
  it('25 EGP unit cost: 30 -> 32 -> 40 -> 50 -> VAT 7 -> 57 (the same waterfall as I-05)', () => {
    const r = ladderForward(forward());
    expect(r.ok && [r.value.factoryPrice.value, r.value.retailerPurchasePrice.value, r.value.priceExVat.value, r.value.shelfPriceInclVat?.value]).toEqual(['30', '40', '50', '57']);
  });

  it('a product with tax switched off has no tax line: the consumer price is the 50 without tax', () => {
    const r = ladderForward(forward({ tax: { ...tax, setting: { mode: 'disabled' } } }));
    expect(r.ok && [r.value.shelfPriceInclVat, r.value.vatAmount, r.value.consumerPrice.value]).toEqual([null, null, '50']);
  });

  it('lists every gap together (no unit cost, no tax setting, no retailer margin), never a default', () => {
    const r = ladderForward({ ...rates, retailerMarginPct: undefined, unitCost: undefined, tax: { ...tax, setting: undefined } });
    expect(r.ok).toBe(false);
    expect(!r.ok && r.missing.map((m) => m.property).sort()).toEqual(['tax:FAM:vat', 'waterfall.retailerMarginPct', 'waterfall.unitCost']);
  });

  it('refuses an estimate (L7) unit cost without a signed override, and keeps the other gaps', () => {
    const r = ladderForward(forward({ unitCost: { ...unitCost('25'), confidence: 'L7_estimate' }, retailerMarginPct: undefined }));
    expect(!r.ok && r.missing.map((m) => `${m.property}:${m.reason}`).sort()).toEqual(['unitCost:confidence.l7_requires_signed_override', 'waterfall.retailerMarginPct:input.missing']);
  });
});

describe('S-13 ladderReverse (shelf -> maximum cost)', () => {
  it('shelf 57 incl. 14 % VAT: 50 -> 40 -> 32 -> factory 30 -> max unit cost 25', () => {
    const r = ladderReverse({ ...rates, shelfPriceInclVat: '57', fillKg: '0.5', nonFormulaCostPerUnit: '5', tax });
    // 57 / 1.14 = 50 ; / 1.25 = 40 ; / 1.25 = 32 ; - 2 = 30 ; / 1.2 = 25 ; per kg 25 / 0.5 = 50 ; formula (25 - 5) / 0.5 = 40
    expect(r.ok && [r.value.priceExVat, r.value.retailerPurchasePrice, r.value.wholesalerPurchasePrice, r.value.factoryPrice, r.value.maxUnitCost, r.value.maxCostPerKg, r.value.maxFormulaCostPerKg]).toEqual(['50', '40', '32', '30', '25', '50', '40']);
    expect(r.ok && [r.value.taxState, r.value.vatRatePct]).toEqual(['applied', '14']);
  });

  it('tax switched off: the shelf price IS the price without tax, so 50 gives the same 25', () => {
    const r = ladderReverse({ ...rates, shelfPriceInclVat: '50', fillKg: '1', tax: { ...tax, setting: { mode: 'disabled' } } });
    expect(r.ok && [r.value.maxUnitCost, r.value.maxFormulaCostPerKg, r.value.taxState, r.value.vatRatePct]).toEqual(['25', null, 'disabled', '0']);
  });

  it('a shelf price below what the ladder takes before the factory is a gap, not a negative cost', () => {
    const r = ladderReverse({ ...rates, shelfPriceInclVat: '3', fillKg: '1', tax });
    // 3 / 1.14 / 1.25 / 1.25 = 1.684..., less the 2 EGP distribution is negative
    expect(!r.ok && r.missing[0]?.reason).toBe('pricing.waterfall.non_positive_price');
  });

  it('a rate with no regime in force on the date is missing (no rate is trusted)', () => {
    const r = ladderReverse({ ...rates, shelfPriceInclVat: '57', fillKg: '1', tax: { ...tax, regimes: [] } });
    expect(!r.ok && r.missing[0]?.property).toBe('tax:FAM:vat');
  });
});

describe('S-13 USD sensitivity', () => {
  const quote = (value: string, unit: 'USD' | 'EGP'): Candidate => ({ value, unit, sourceId: 'Q', confidence: 'L2_supplier_tds', validFrom: '2026-05-01' });
  const quotes = { A: [quote('2', 'USD')], B: [quote('10', 'EGP')] };
  const fx = [{ from: 'USD' as const, to: 'EGP' as const, rate: '50', date: '2026-05-01', sourceId: 'FX', source: 'fixture', confidence: 'L2_supplier_tds' as const, forDate: '2026-05-01' }];
  const report = buildCostReport({ datasetVersion: 'test', lines: [{ materialId: 'A', percentWW: '50' }, { materialId: 'B', percentWW: '50' }], quotes, fxRates: fx });

  it('the USD-priced half costs 2 USD x 50 = 100 EGP/kg x 50 % = 50 of a 55 EGP/kg material cost: 90.909...% linked', () => {
    const r = usdLinkedCost(report, quotes);
    expect(r.ok && [r.value.usdLinkedPerKg, r.value.materialCostPerKg]).toEqual(['50', '55']);
    expect(r.ok && r.value.usdSharePct.startsWith('90.9090909')).toBe(true);
  });

  it('a cost report with a gap has no USD share: missing, never a share of zero', () => {
    const gappy = buildCostReport({ datasetVersion: 'test', lines: [{ materialId: 'A', percentWW: '50' }, { materialId: 'B', percentWW: '50' }], quotes, fxRates: [] });
    const r = usdLinkedCost(gappy, quotes);
    expect(!r.ok && r.missing.some((m) => m.reason === 'cost.fx_rate_required')).toBe(true);
  });

  it('dollar +10 % on 2 EGP/kg x 0.5 kg = 1 EGP linked: unit cost 25 -> 25.1, shelf 57 -> 57.21375 (+0.375 %)', () => {
    // 25.1 x 1.2 = 30.12 ; + 2 = 32.12 ; x 1.25 = 40.15 ; x 1.25 = 50.1875 ; x 1.14 = 57.21375
    const r = fxSensitivity({ forward: forward(), usdLinkedPerKg: '2', fillKg: '0.5', changesPct: ['10', '-10'] });
    expect(r.ok && [r.value.usdLinkedPerUnit, r.value.baseUnitCost, r.value.baseConsumerPrice]).toEqual(['1', '25', '57']);
    expect(r.ok && [r.value.rows[0]?.unitCost, r.value.rows[0]?.consumerPrice, r.value.rows[0]?.deltaConsumerPrice, r.value.rows[0]?.deltaConsumerPct]).toEqual(['25.1', '57.21375', '0.21375', '0.375']);
    // -10 %: 24.9 x 1.2 = 29.88 ; + 2 = 31.88 ; x 1.25 = 39.85 ; x 1.25 = 49.8125 ; x 1.14 = 56.78625
    expect(r.ok && [r.value.rows[1]?.consumerPrice, r.value.rows[1]?.deltaConsumerPct]).toEqual(['56.78625', '-0.375']);
  });

  it('with the rate in force (50 EGP per USD) each move says what it means in pounds: +10 % -> 55, -10 % -> 45', () => {
    const r = fxSensitivity({ forward: forward(), usdLinkedPerKg: '2', fillKg: '0.5', changesPct: ['10', '-10'], usdRate: '50' });
    expect(r.ok && r.value.rows.map((row) => row.usdRateAfter)).toEqual(['55', '45']);
  });

  it('without the fill (kg per unit) the unit-level effect is missing, not guessed', () => {
    const r = fxSensitivity({ forward: forward(), usdLinkedPerKg: '2', changesPct: ['10'] });
    expect(!r.ok && r.missing.map((m) => m.property)).toContain('fx.fillKg');
  });

  it('a move of -100 % or worse is refused on its row and the other rows still show', () => {
    const r = fxSensitivity({ forward: forward(), usdLinkedPerKg: '2', fillKg: '0.5', changesPct: ['-100', '10'] });
    expect(r.ok && r.value.rows[0]?.missing[0]?.reason).toBe(LADDER_REASONS.INVALID_CHANGE);
    expect(r.ok && r.value.rows[1]?.consumerPrice).toBe('57.21375');
  });
});
