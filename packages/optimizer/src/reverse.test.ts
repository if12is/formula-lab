import { describe, expect, it } from 'vitest';
import { reversePricing } from './reverse';

// TEST-FIXTURE numbers (fictional rates, chosen so every step is exact).
describe('J-08 reverse pricing', () => {
  it('case 1, markup on cost: 57.00 -> 50 -> 40 -> 32 -> 30 -> 25 -> 50 EGP/kg', () => {
    // 57 / 1.14 = 50 ; retailer +25 %: 50 / 1.25 = 40 ; wholesaler +25 %: 40 / 1.25 = 32 ; distribution 2: 32 − 2 = 30 ;
    // factory +20 %: 30 / 1.2 = 25 max unit cost ; fill 0.5 kg: 25 / 0.5 = 50 per kg ; non-formula 5: (25 − 5)/0.5 = 40
    const r = reversePricing({ shelfPriceInclVat: '57', vatRatePct: '14', retailerMarginPct: '25', wholesalerMarginPct: '25', distributionCostPerUnit: '2', factoryMarginPct: '20', marginBasis: 'markup_on_cost', fillKg: '0.5', nonFormulaCostPerUnit: '5' });
    expect(r.ok && r.value).toMatchObject({ priceExVat: '50', retailerPurchasePrice: '40', wholesalerPurchasePrice: '32', factoryPrice: '30', maxUnitCost: '25', maxCostPerKg: '50', maxFormulaCostPerKg: '40' });
    expect(r.ok && r.value.steps.map((s) => [s.key.replace('optimizer.reverse.step.', ''), s.value])).toEqual([
      ['shelf_price_incl_vat', '57'], ['price_ex_vat', '50'], ['retailer_purchase_price', '40'], ['wholesaler_purchase_price', '32'],
      ['factory_price', '30'], ['max_unit_cost', '25'], ['max_cost_per_kg', '50'], ['max_formula_cost_per_kg', '40'],
    ]);
  });

  it('case 2, margin on price: 57.00 -> 50 -> 40 -> 36 -> 34.5 -> 25.875 -> 34.5 EGP/kg', () => {
    // 57 / 1.14 = 50 ; retailer 20 % of price: 50 × 0.8 = 40 ; wholesaler 10 %: 40 × 0.9 = 36 ; distribution 1.5: 34.5 ;
    // factory 25 %: 34.5 × 0.75 = 25.875 ; fill 0.75 kg: 25.875 / 0.75 = 34.5 ; no non-formula cost given -> null
    const r = reversePricing({ shelfPriceInclVat: '57', vatRatePct: '14', retailerMarginPct: '20', wholesalerMarginPct: '10', distributionCostPerUnit: '1.5', factoryMarginPct: '25', marginBasis: 'margin_on_price', fillKg: '0.75' });
    expect(r.ok && r.value).toMatchObject({ priceExVat: '50', retailerPurchasePrice: '40', wholesalerPurchasePrice: '36', factoryPrice: '34.5', maxUnitCost: '25.875', maxCostPerKg: '34.5', maxFormulaCostPerKg: null });
  });

  it('case 3, zero VAT and zero margins pass the price straight down (explicit zeros are allowed)', () => {
    // 100 / 1 = 100 ; no margins ; distribution 10 -> factory price 90 = max unit cost ; fill 1 kg -> 90 EGP/kg
    const r = reversePricing({ shelfPriceInclVat: '100', vatRatePct: '0', retailerMarginPct: '0', wholesalerMarginPct: '0', distributionCostPerUnit: '10', factoryMarginPct: '0', marginBasis: 'markup_on_cost', fillKg: '1' });
    expect(r.ok && [r.value.maxUnitCost, r.value.maxCostPerKg]).toEqual(['90', '90']);
  });

  it('missing inputs are listed, never defaulted', () => {
    const r = reversePricing({ shelfPriceInclVat: '57', vatRatePct: '14', marginBasis: 'markup_on_cost' });
    expect(!r.ok && r.missing).toEqual([
      { property: 'reversePricing.retailerMarginPct', reason: 'input.missing' },
      { property: 'reversePricing.wholesalerMarginPct', reason: 'input.missing' },
      { property: 'reversePricing.distributionCostPerUnit', reason: 'input.missing' },
      { property: 'reversePricing.factoryMarginPct', reason: 'input.missing' },
      { property: 'reversePricing.fillKg', reason: 'input.missing' },
    ]);
    expect(!reversePricing({}).ok && reversePricing({}).ok === false).toBe(true);
    const noBasis = reversePricing({ shelfPriceInclVat: '57', vatRatePct: '14', retailerMarginPct: '1', wholesalerMarginPct: '1', distributionCostPerUnit: '1', factoryMarginPct: '1', fillKg: '1' });
    expect(!noBasis.ok && noBasis.missing).toEqual([{ property: 'reversePricing.marginBasis', reason: 'input.missing' }]);
  });

  it('rejects invalid values and a waterfall that goes to zero or below', () => {
    const base = { shelfPriceInclVat: '57', vatRatePct: '14', retailerMarginPct: '25', wholesalerMarginPct: '25', distributionCostPerUnit: '2', factoryMarginPct: '20', marginBasis: 'markup_on_cost' as const, fillKg: '0.5' };
    const bad = reversePricing({ ...base, vatRatePct: '-1', fillKg: '0', retailerMarginPct: 'abc' });
    expect(!bad.ok && bad.missing.map((m) => [m.property, m.reason])).toEqual([
      ['reversePricing.vatRatePct', 'input.out_of_range'], ['reversePricing.retailerMarginPct', 'input.invalid_number'], ['reversePricing.fillKg', 'input.out_of_range'],
    ]);
    const price100 = reversePricing({ ...base, marginBasis: 'margin_on_price', retailerMarginPct: '100' });
    expect(!price100.ok && price100.missing).toEqual([{ property: 'reversePricing.retailerMarginPct', reason: 'input.out_of_range' }]);
    const underwater = reversePricing({ ...base, distributionCostPerUnit: '32' });
    expect(!underwater.ok && underwater.missing).toEqual([{ property: 'reversePricing.factoryPrice', reason: 'optimizer.reverse.non_positive_price' }]);
  });
});
