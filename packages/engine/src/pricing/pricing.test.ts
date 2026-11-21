import fc from 'fast-check';
import { describe, expect, it } from 'vitest';
import { dec } from '../core/decimal';
import { inputLeaf } from '../trace/trace';
import { leavesOf } from '../trace/trace';
import { applyMargin, backwardWaterfall, forwardWaterfall, removeMargin, type ForwardWaterfallInput } from './waterfall';
import { resolveTaxRegime, TAX_REASONS, type TaxRegime } from './tax';

// TEST-FIXTURE numbers (fictional rates, chosen so every step is exact).
const vat14: TaxRegime = { productFamilyId: 'FAM', taxType: 'vat', ratePct: '14', validFrom: '2026-01-01', sourceId: 'TAX-TEST', confidence: 'L4_regulatory_db' };
const tax = { regimes: [vat14], productFamilyId: 'FAM', taxType: 'vat', asOf: '2026-06-01' };
const egp = (v: string) => inputLeaf('Unit cost', v, 'EGP');
const fwd = (over: Partial<ForwardWaterfallInput>): ForwardWaterfallInput => ({
  unitCost: egp('25'), marginBasis: 'markup_on_cost', factoryMarginPct: '20', distributionCostPerUnit: '2',
  wholesalerMarginPct: '25', retailerMarginPct: '25', tax, policy: {}, ...over,
});

describe('I-05 margin primitives', () => {
  it('markup: 100 +25 % = 125 and back; on price: 100 / 0.8 = 125 and back', () => {
    expect(applyMargin(dec('100'), dec('25'), 'markup_on_cost').toFixed()).toBe('125');
    expect(removeMargin(dec('125'), dec('25'), 'markup_on_cost').toFixed()).toBe('100');
    expect(applyMargin(dec('100'), dec('20'), 'margin_on_price').toFixed()).toBe('125');
    expect(removeMargin(dec('125'), dec('20'), 'margin_on_price').toFixed()).toBe('100');
  });
});

describe('I-05 forward waterfall', () => {
  it('case 1, markup on cost: 25 -> 30 -> 32 -> 40 -> 50 -> VAT 7 -> 57', () => {
    // factory +20 %: 25 × 1.2 = 30 ; distribution 2: 32 ; wholesaler +25 %: 40 ; retailer +25 %: 50 ; VAT 14 %: 7 ; shelf 57
    const r = forwardWaterfall(fwd({}));
    expect(r.ok && [r.value.factoryPrice.value, r.value.wholesalerPurchasePrice.value, r.value.retailerPurchasePrice.value, r.value.priceExVat.value, r.value.vatAmount.value, r.value.shelfPriceInclVat.value]).toEqual(['30', '32', '40', '50', '7', '57']);
    expect(r.ok && r.value.steps.map((s) => s.key)).toEqual(['unit_cost', 'factory_price', 'wholesaler_purchase_price', 'retailer_purchase_price', 'price_ex_vat', 'shelf_price_incl_vat']);
  });

  it('case 2, margin on price: 25.875 -> 34.5 -> 36 -> 40 -> 50 -> 57', () => {
    // factory 25 % of price: 25.875 / 0.75 = 34.5 ; distribution 1.5: 36 ; wholesaler 10 %: 36 / 0.9 = 40 ; retailer 20 %: 40 / 0.8 = 50 ; × 1.14 = 57
    const r = forwardWaterfall(fwd({ unitCost: egp('25.875'), marginBasis: 'margin_on_price', factoryMarginPct: '25', distributionCostPerUnit: '1.5', wholesalerMarginPct: '10', retailerMarginPct: '20' }));
    expect(r.ok && [r.value.factoryPrice.value, r.value.priceExVat.value, r.value.shelfPriceInclVat.value]).toEqual(['34.5', '50', '57']);
  });

  it('case 3, zero margins and zero VAT pass the cost through (explicit zeros are values)', () => {
    // 90 + 0 % = 90 ; + distribution 10 = 100 ; no margins ; VAT 0 -> 100
    const zero = { ...vat14, ratePct: '0' };
    const r = forwardWaterfall(fwd({ unitCost: egp('90'), factoryMarginPct: '0', wholesalerMarginPct: '0', retailerMarginPct: '0', distributionCostPerUnit: '10', tax: { ...tax, regimes: [zero] } }));
    expect(r.ok && r.value.shelfPriceInclVat.value).toBe('100');
  });

  it('case 4, returns 7.5 % + promo 2.5 % lift the factory price: 22.5 -> 27 -> 30 -> ... -> 57', () => {
    // factory +20 %: 22.5 × 1.2 = 27 net ; kept share 1 − 0.10 = 0.9 : 27 / 0.9 = 30 ; then as case 1 -> 57
    const r = forwardWaterfall(fwd({ unitCost: egp('22.5'), returnsPct: '7.5', promoPct: '2.5' }));
    expect(r.ok && [r.value.factoryPrice.value, r.value.shelfPriceInclVat.value]).toEqual(['30', '57']);
    expect(r.ok && r.value.steps.map((s) => s.key)).toContain('factory_net_price');
  });

  it('the trace reaches the VAT source and every margin leaf', () => {
    const r = forwardWaterfall(fwd({}));
    if (!r.ok) {
      throw new Error('expected ok');
    }
    const leaves = leavesOf(r.value.shelfPriceInclVat.trace);
    expect(leaves.find((l) => l.sourceId === 'TAX-TEST')?.value).toBe('14');
    expect(leaves.map((l) => l.label)).toEqual(expect.arrayContaining(['Factory margin %', 'Wholesaler margin %', 'Retailer margin %']));
  });

  it('lists EVERY gap: no tax regime, no cost, no basis, bad margin, bad returns', () => {
    const r = forwardWaterfall({ policy: {}, wholesalerMarginPct: 'abc', factoryMarginPct: '-1', returnsPct: '60', promoPct: '50' });
    expect(!r.ok && r.missing.map((m) => [m.property, m.reason])).toEqual([
      ['waterfall.tax', 'input.missing'],
      ['waterfall.retailerMarginPct', 'input.missing'],
      ['waterfall.wholesalerMarginPct', 'input.invalid_number'],
      ['waterfall.distributionCostPerUnit', 'input.missing'],
      ['waterfall.factoryMarginPct', 'input.out_of_range'],
      ['waterfall.unitCost', 'input.missing'],
      ['waterfall.marginBasis', 'input.missing'],
      ['waterfall.returnsPct', 'input.out_of_range'],
    ]);
  });

  it('rejects a non-EGP unit cost, an unknown basis, a 100 % margin on price and an out-of-force VAT regime', () => {
    const usd = forwardWaterfall(fwd({ unitCost: inputLeaf('c', '25', 'USD') }));
    expect(!usd.ok && usd.missing).toEqual([{ property: 'waterfall.unitCost', reason: 'pricing.waterfall.unit_cost_not_egp' }]);
    const basis = forwardWaterfall(fwd({ marginBasis: 'bogus' as never }));
    expect(!basis.ok && basis.missing).toEqual([{ property: 'waterfall.marginBasis', reason: 'pricing.waterfall.invalid_margin_basis' }]);
    const full = forwardWaterfall(fwd({ marginBasis: 'margin_on_price', retailerMarginPct: '100' }));
    expect(!full.ok && full.missing).toEqual([{ property: 'waterfall.retailerMarginPct', reason: 'input.out_of_range' }]);
    const early = forwardWaterfall(fwd({ tax: { ...tax, asOf: '2025-12-31' } }));
    expect(!early.ok && early.missing).toEqual([{ property: 'tax:FAM:vat', reason: TAX_REASONS.NO_REGIME }]);
  });

  it('carries a below-policy VAT confidence as a warning', () => {
    const weak = { ...vat14, confidence: 'L5_reference' as const };
    const r = forwardWaterfall(fwd({ tax: { ...tax, regimes: [weak] }, policy: { allowConfidenceBelow: 'L3_standard' } }));
    expect(r.ok && r.warnings).toEqual(['confidence.below_policy:-:tax:FAM:vat:L5_reference']);
  });
});

describe('I-05 backward waterfall (shared with the optimizer)', () => {
  const back = { vatRatePct: '14', retailerMarginPct: '25', wholesalerMarginPct: '25', distributionCostPerUnit: '2', factoryMarginPct: '20', marginBasis: 'markup_on_cost' as const, fillKg: '0.5' };

  it('case 1: shelf 57 -> max unit cost 25 -> 50 EGP/kg -> formula 40 with 5 non-formula', () => {
    const r = backwardWaterfall({ ...back, shelfPriceInclVat: '57', nonFormulaCostPerUnit: '5' });
    expect(r.ok && r.value).toMatchObject({ priceExVat: '50', factoryPrice: '30', maxUnitCost: '25', maxCostPerKg: '50', maxFormulaCostPerKg: '40' });
    expect(r.ok && r.value.steps[0]).toEqual({ key: 'pricing.waterfall.step.shelf_price_incl_vat', value: '57' });
  });

  it('case 2: with returns 7.5 % + promo 2.5 %: factory 30 -> net 27 -> max unit cost 22.5', () => {
    const r = backwardWaterfall({ ...back, shelfPriceInclVat: '57', returnsPct: '7.5', promoPct: '2.5' });
    expect(r.ok && [r.value.factoryPrice, r.value.maxUnitCost]).toEqual(['30', '22.5']);
    expect(r.ok && r.value.steps.map((s) => s.key.split('.').pop())).toContain('factory_net_price');
  });

  it('case 3: a waterfall that goes to zero uses the supplied reason and prefix', () => {
    const r = backwardWaterfall({ ...back, shelfPriceInclVat: '57', distributionCostPerUnit: '32' }, { propertyPrefix: 'x.', nonPositiveReason: 'my.reason' });
    expect(!r.ok && r.missing).toEqual([{ property: 'x.factoryPrice', reason: 'my.reason' }]);
  });

  it('returns + promo of 100 % or more are out of range', () => {
    const r = backwardWaterfall({ ...back, shelfPriceInclVat: '57', returnsPct: '60', promoPct: '40' });
    expect(!r.ok && r.missing).toEqual([{ property: 'waterfall.returnsPct', reason: 'input.out_of_range' }]);
  });

  it('property: forward then backward returns the same unit cost, both bases', () => {
    const pct = fc.integer({ min: 0, max: 90 }).map(String);
    fc.assert(
      fc.property(fc.integer({ min: 1, max: 100000 }).map((n) => (n / 100).toFixed(2)), pct, pct, pct, fc.integer({ min: 0, max: 500 }).map((n) => (n / 100).toFixed(2)), fc.constantFrom('markup_on_cost' as const, 'margin_on_price' as const), (cost, fm, wm, rm, dist, basis) => {
        const f = forwardWaterfall(fwd({ unitCost: egp(cost), factoryMarginPct: fm, wholesalerMarginPct: wm, retailerMarginPct: rm, distributionCostPerUnit: dist, marginBasis: basis }));
        if (!f.ok) {
          throw new Error('forward must succeed');
        }
        const b = backwardWaterfall({ shelfPriceInclVat: f.value.shelfPriceInclVat.value, vatRatePct: '14', retailerMarginPct: rm, wholesalerMarginPct: wm, distributionCostPerUnit: dist, factoryMarginPct: fm, marginBasis: basis, fillKg: '1' });
        expect(b.ok && dec(b.value.maxUnitCost).minus(cost).abs().lt('1e-25')).toBe(true);
      }),
    );
  });
});

describe('I-04/I-05 tax regime lookup (no default rate)', () => {
  const q = { productFamilyId: 'FAM', taxType: 'vat', asOf: '2026-06-01' };
  it('picks the regime in force on the date; window ends are inclusive', () => {
    // 14 % from 2026-01-01 to 2026-05-31, 15 % from 2026-06-01 (fictional): 2026-05-31 -> 14, 2026-06-01 -> 15
    const old: TaxRegime = { ...vat14, validTo: '2026-05-31' };
    const next: TaxRegime = { ...vat14, ratePct: '15', validFrom: '2026-06-01' };
    const a = resolveTaxRegime([old, next], { ...q, asOf: '2026-05-31' }, {});
    const b = resolveTaxRegime([old, next], q, {});
    expect([a.ok && a.value.regime.ratePct, b.ok && b.value.regime.ratePct]).toEqual(['14', '15']);
  });
  it('no regime, other family, expired, overlapping, malformed, bad date, L7 all give missing', () => {
    const reason = (r: ReturnType<typeof resolveTaxRegime>) => !r.ok && r.missing[0]?.reason;
    expect(reason(resolveTaxRegime(undefined, q, {}))).toBe(TAX_REASONS.NO_REGIME);
    expect(reason(resolveTaxRegime([{ ...vat14, productFamilyId: 'OTHER' }], q, {}))).toBe(TAX_REASONS.NO_REGIME);
    expect(reason(resolveTaxRegime([{ ...vat14, validTo: '2026-03-01' }], q, {}))).toBe(TAX_REASONS.NO_REGIME);
    expect(reason(resolveTaxRegime([vat14, { ...vat14, ratePct: '5' }], q, {}))).toBe(TAX_REASONS.AMBIGUOUS);
    expect(reason(resolveTaxRegime([{ ...vat14, ratePct: '140' }], q, {}))).toBe(TAX_REASONS.INVALID_REGIME);
    expect(reason(resolveTaxRegime([{ ...vat14, validTo: '2025-01-01' }], q, {}))).toBe(TAX_REASONS.INVALID_REGIME);
    expect(reason(resolveTaxRegime([vat14], { ...q, asOf: 'June' }, {}))).toBe(TAX_REASONS.INVALID_AS_OF);
    expect(reason(resolveTaxRegime([{ ...vat14, confidence: 'L7_estimate' }], q, {}))).toBe('confidence.l7_requires_signed_override');
  });
});
