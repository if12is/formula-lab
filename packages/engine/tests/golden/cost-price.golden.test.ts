/**
 * T-01 golden cases, family 3: cost (materials with FX, landed price as given, batch plan, packs, per-litre, full unit cost,
 * actual batch cost) and the price waterfall / ladder. The F-02 file (cost-hand.test.ts) keeps the 20 `calculate()` cases;
 * these are different numbers through the same and the neighbouring functions. Fictional prices, exact by hand.
 */
import { describe, expect, it } from 'vitest';
import {
  ENGINE_VERSION,
  actualBatchCost,
  backwardWaterfall,
  calculate,
  computeUnitCost,
  convertMoney,
  costPerLitre,
  forwardWaterfall,
  invoiceCostPerBaseUnit,
  ladderForward,
  ladderReverse,
  massToVolume,
  piecesToCartons,
  piecesToPallets,
  cartonsToPieces,
  palletsToCartons,
  planBatch,
  productCostBreakdown,
  qty,
  roundMoney,
  roundPercent,
  roundToStep,
  volumeToMass,
  type EngineInput,
  type DensityRef,
} from '../../src/index';
import { inputLeaf } from '../../src/trace/trace';
import { bottle, cap, money, scalar, usdRate } from '../../src/costing/test-fixtures';
import { must } from './support';

const vat14 = { productFamilyId: 'FAM', taxType: 'vat', ratePct: '14', validFrom: '2026-01-01', sourceId: 'TAX-TEST', confidence: 'L4_regulatory_db' } as const;
const tax14 = { setting: { mode: 'rate', ratePct: '14' } as const, regimes: [vat14], productFamilyId: 'FAM', taxType: 'vat', asOf: '2026-06-01' };

describe('golden[litre_kilo] per-kg cost, wastage, packs and per-litre through calculate()', () => {
  const sv = (value: string, unit: 'EGP' | 'USD' | 'kg/L', id: string) => ({ value, unit, sourceId: id, confidence: 'L2_supplier_tds' as const, validFrom: '2026-01-01' });
  const input: EngineInput = {
    engineVersion: ENGINE_VERSION,
    datasetVersion: 'golden-v2',
    formula: {
      lines: [{ materialId: 'A', percentWW: '50' }, { materialId: 'B', percentWW: '30' }, { materialId: 'C', percentWW: '20' }],
      productDensity: sv('1.25', 'kg/L', 'LAB-RHO'),
    },
    batch: { size: { value: '80', unit: 'kg' }, wastagePct: '20', packSize: { value: '400', unit: 'g' } },
    prices: { A: sv('20', 'EGP', 'P-A'), B: sv('1.5', 'USD', 'P-B'), C: sv('0.5', 'EGP', 'P-C') },
    packaging: { perPack: sv('1.2', 'EGP', 'P-PACK') },
    pricing: { marginPct: '20' },
    fxRates: [{ from: 'USD', to: 'EGP', rate: '40', date: '2026-02-01', sourceId: 'FX-FICTIONAL', source: 'TEST' }],
    policy: { rounding: 'default' },
  };

  it('A 50 % at 20, B 30 % at 1.5 USD × 40 = 60, C 20 % at 0.5', () => {
    // C_kg = 0.5 × 20 + 0.3 × 60 + 0.2 × 0.5 = 10 + 18 + 0.1 = 28.1 ; wastage 20 %: perKg = 28.1 / 0.8 = 35.125
    // perL = 35.125 × 1.25 = 43.90625 ; net 80 kg => gross 80 / 0.8 = 100 kg ; N = 80000 / 400 = 200 packs, remainder 0
    // materials = 28.1 × 100 = 2810 ; packaging = 200 × 1.2 = 240 ; batch = 3050 ; perPack = 0.4 × 35.125 + 1.2 = 14.05 + 1.2 = 15.25
    // price at margin 20 %: perKg 35.125 / 0.8 = 43.90625 ; perPack 15.25 / 0.8 = 19.0625
    const out = calculate(input);
    expect(out.ok).toBe(true);
    const v = (k: string) => out.results[k]?.value;
    expect([v('cost.materialPerKg'), v('cost.perKg'), v('cost.perL')]).toEqual(['28.1', '35.125', '43.90625']);
    expect([v('batch.grossMass'), v('batch.fullPacks'), v('batch.remainder'), v('batch.packMass')]).toEqual(['100', '200', '0', '0.4']);
    expect([v('cost.materialBatch'), v('cost.packagingBatch'), v('cost.batch'), v('cost.perPack')]).toEqual(['2810', '240', '3050', '15.25']);
    expect([v('price.perKg'), v('price.perPack')]).toEqual(['43.90625', '19.0625']);
  });

  it('a missing price is reported and the cost is absent, never zero (B without a price)', () => {
    const prices = { ...input.prices };
    delete prices.B;
    const out = calculate({ ...input, prices });
    expect(out.ok).toBe(false);
    expect(out.missing.map((m) => m.materialId)).toContain('B');
    expect(out.results['cost.materialPerKg']).toBeUndefined();
    expect(out.results['cost.perKg']).toBeUndefined();
  });
});

describe('golden[litre_kilo] batch plan, density conversion and per-litre cost', () => {
  it('500 kg net at wastage 20 %, density 1.25 kg/L, 750 mL packs', () => {
    // gross = 500 / 0.8 = 625 kg ; net volume = 500 / 1.25 = 400 L ; gross volume = 400 / 0.8 = 500 L
    // pack mass = 0.75 L × 1.25 = 0.9375 kg ; N = floor(400000 mL / 750 mL) = floor(533.33) = 533 ; remainder 400000 − 533 × 750 = 250 mL
    const out = planBatch({
      size: qty('500', 'kg'),
      wastagePct: '20',
      packSize: qty('750', 'mL'),
      productDensity: { value: '1.25', unit: 'kg/L', sourceId: 'LAB', confidence: 'L1_coa_batch', validFrom: '2026-01-01' },
    });
    const v = (k: string) => out.results[k]?.value;
    expect([v('batch.netMass'), v('batch.grossMass'), v('batch.netVolume'), v('batch.grossVolume')]).toEqual(['500', '625', '400', '500']);
    expect([v('batch.packMass'), v('batch.fullPacks'), v('batch.remainder')]).toEqual(['0.9375', '533', '250']);
  });

  it('density both ways: 1000 g at 0.8 g/mL = 1250 mL; 2.5 L at 1.04 g/mL = 2600 g = 2.6 kg', () => {
    const rho = (v: string): DensityRef => ({ value: v, unit: 'g/mL', sourceId: 'LAB', confidence: 'L1_coa_batch' });
    expect(must(massToVolume(qty('1000', 'g'), rho('0.8'))).value.toFixed()).toBe('1250');
    expect(must(volumeToMass(qty('2.5', 'L'), rho('1.04'), 'kg')).value.toFixed()).toBe('2.6');
  });

  it('cost per litre = cost per kg × density: 12.5 EGP/kg × 1.04 kg/L = 13 EGP/L', () => {
    expect(costPerLitre(inputLeaf('Cost per kg', '12.5', 'EGP'), inputLeaf('Density', '1.04', 'kg/L')).value).toBe('13');
  });
});

describe('golden[cost] full unit cost: product + packaging + conversion + quality, landed prices with FX', () => {
  it('A 70 % at 8 EGP/kg, B 30 % at 2 USD/kg × 40; wastage 20 %; fill 250 g; bottle 3 EGP / 25 % reject; cap 0.05 USD', () => {
    // C_kg = 0.7 × 8 + 0.3 × (2 × 40) = 5.6 + 24 = 29.6 ; net kg = 29.6 / 0.8 = 37 ; per unit 0.25 × 37 = 9.25
    // packaging: bottle 3 / (1 − 0.25) = 4 ; cap 0.05 × 40 = 2 ; = 6
    // conversion: labour 0.5 EGP/min × 60 min per batch / 500 units = 0.06
    // quality: batch test 200 × 1 / 500 units = 0.4 ; regulatory and one-time: none (explicit empty lists)
    // total = 9.25 + 6 + 0.06 + 0.4 = 15.71
    const rate = { ...usdRate, rate: '40' };
    const product = must(
      productCostBreakdown(
        { lines: [{ materialId: 'A', percentWW: '70' }, { materialId: 'B', percentWW: '30' }], prices: { A: money('8'), B: money('2', 'USD') }, fxRates: [rate], policy: {} },
        '20',
      ),
    );
    expect(product.costPerKg.value).toBe('37');
    expect(product.contributions.map((c) => [c.materialId, c.perKg.value, c.currency])).toEqual([['A', '7', 'EGP'], ['B', '30', 'USD']]);
    const cost = must(
      computeUnitCost({
        policy: {},
        fxRates: [rate],
        product: { costPerKg: product.costPerKg, materials: product.contributions },
        fillActual: { ...money('250'), unit: 'g' },
        packaging: {
          items: [
            { ...bottle(), price: money('3'), rejectPct: scalar('25') },
            { ...cap(), price: money('0.05', 'USD') },
          ],
        },
        conversion: { unitsPerBatch: scalar('500'), lines: [{ id: 'labour', kind: 'labour', rate: money('0.5'), qtyPerBatch: scalar('60') }], overheads: [] },
        quality: { unitsPerBatch: scalar('500'), batchTests: [{ id: 'micro', costPerTest: money('200'), testsPerBatch: scalar('1') }], regulatory: [] },
        oneTime: [],
      }),
    );
    expect(cost.fillKg.value).toBe('0.25');
    expect([cost.sections.product.value, cost.sections.packaging.value, cost.sections.conversion.value, cost.sections.quality.value]).toEqual(['9.25', '6', '0.06', '0.4']);
    expect(cost.total.value).toBe('15.71');
  });

  it('actual batch cost against the computed unit cost', () => {
    // materials: 120000 g = 120 kg × 30 = 3600 ; 40 kg × 10 = 400 => 4000 ; packaging 1000 pc × 4 = 4000 ; conversion 500
    // total 8500 ; 1000 units => 8.5 per unit ; against the computed 8: difference 0.5 = 6.25 %
    const input = {
      materials: [{ id: 'M1', quantity: '120000', quantityUnit: 'g', unitCost: '30', costUnit: 'kg' }, { id: 'M2', quantity: '40', unitCost: '10', costUnit: 'kg' }],
      packaging: [{ id: 'P1', quantity: '1000', unitCost: '4', costUnit: 'pc' }],
      conversion: '500',
      units: '1000',
      computedUnitCost: '8',
    } as const;
    const within = must(actualBatchCost({ ...input, materials: [...input.materials], packaging: [...input.packaging], thresholdPct: '10' }));
    expect([within.materials.value, within.packaging.value, within.total.value, within.perUnit.value]).toEqual(['4000', '4000', '8500', '8.5']);
    expect([within.comparison?.differencePct.value, within.comparison?.verdict]).toEqual(['6.25', 'within']);
    const exceeds = must(actualBatchCost({ ...input, materials: [...input.materials], packaging: [...input.packaging], thresholdPct: '5' }));
    expect(exceeds.comparison?.verdict).toBe('exceeds');
  });

  it('invoice cost per base unit (before tax and discount; NOT landed with freight and duties): 600 per jerrycan × 5 / 100 kg = 30 EGP/kg', () => {
    expect(must(invoiceCostPerBaseUnit({ unitCost: '600', quantity: '5', baseQuantity: '100' })).value).toBe('30');
  });
});

describe('golden[price_waterfall] cost -> shelf and shelf -> maximum cost (I-05)', () => {
  const unit = (v: string) => inputLeaf('Unit cost', v, 'EGP');

  it('markup on cost: 40 -> factory +25 % = 50 -> + 4 distribution = 54 -> wholesaler +10 % = 59.4 -> retailer +20 % = 71.28 -> VAT 14 % = 9.9792 -> shelf 81.2592', () => {
    const r = must(
      forwardWaterfall({ unitCost: unit('40'), marginBasis: 'markup_on_cost', factoryMarginPct: '25', distributionCostPerUnit: '4', wholesalerMarginPct: '10', retailerMarginPct: '20', tax: tax14, policy: {} }),
    );
    expect([r.factoryPrice.value, r.wholesalerPurchasePrice.value, r.retailerPurchasePrice.value, r.priceExVat.value, r.vatAmount?.value, r.shelfPriceInclVat?.value]).toEqual(['50', '54', '59.4', '71.28', '9.9792', '81.2592']);
  });

  it('margin on price: 24 / (1 − 0.20) = 30 -> + 3 = 33 -> / (1 − 0.25) = 44 -> / (1 − 0.20) = 55 -> VAT 7.7 -> 62.7', () => {
    const r = must(
      forwardWaterfall({ unitCost: unit('24'), marginBasis: 'margin_on_price', factoryMarginPct: '20', distributionCostPerUnit: '3', wholesalerMarginPct: '25', retailerMarginPct: '20', tax: tax14, policy: {} }),
    );
    expect([r.factoryPrice.value, r.wholesalerPurchasePrice.value, r.retailerPurchasePrice.value, r.priceExVat.value, r.vatAmount?.value, r.shelfPriceInclVat?.value]).toEqual(['30', '33', '44', '55', '7.7', '62.7']);
  });

  it('backward from the shelf 81.2592 (14 % VAT): / 1.14 = 71.28 -> / 1.2 = 59.4 -> / 1.1 = 54 -> − 4 = 50 -> / 1.25 = 40; fill 0.5 kg, non-formula 3 => per kg 80, formula (40 − 3) / 0.5 = 74', () => {
    const r = must(
      backwardWaterfall({
        shelfPriceInclVat: '81.2592', vatRatePct: '14', retailerMarginPct: '20', wholesalerMarginPct: '10', distributionCostPerUnit: '4', factoryMarginPct: '25',
        marginBasis: 'markup_on_cost', fillKg: '0.5', nonFormulaCostPerUnit: '3',
      }),
    );
    expect([r.priceExVat, r.retailerPurchasePrice, r.wholesalerPurchasePrice, r.factoryPrice, r.maxUnitCost, r.maxCostPerKg, r.maxFormulaCostPerKg]).toEqual(['71.28', '59.4', '54', '50', '40', '80', '74']);
  });

  it('returns 5 % + promo 5 % lift the factory price: 36 × 1.25 = 45 net; 45 / (1 − 0.10) = 50', () => {
    const r = must(
      forwardWaterfall({ unitCost: unit('36'), marginBasis: 'markup_on_cost', factoryMarginPct: '25', distributionCostPerUnit: '0', wholesalerMarginPct: '0', retailerMarginPct: '0', returnsPct: '5', promoPct: '5', tax: { ...tax14, setting: { mode: 'disabled' } }, policy: {} }),
    );
    expect(r.factoryPrice.value).toBe('50');
  });
});

describe('golden[price_ladder] ladder forward / reverse with a sourced unit cost (S-13)', () => {
  const rates = { marginBasis: 'markup_on_cost', factoryMarginPct: '25', distributionCostPerUnit: '4', wholesalerMarginPct: '10', retailerMarginPct: '20' } as const;
  const cost = { value: '40', unit: 'EGP', sourceId: 'COST-SHEET', confidence: 'L2_supplier_tds', validFrom: '2026-05-01' } as const;

  it('unit cost 40 -> shelf 81.2592 (same ladder as the waterfall golden)', () => {
    expect(must(ladderForward({ ...rates, unitCost: cost, tax: tax14 })).shelfPriceInclVat?.value).toBe('81.2592');
  });

  it('shelf 81.2592 -> max unit cost 40, taxState applied at 14 %', () => {
    const r = must(ladderReverse({ ...rates, shelfPriceInclVat: '81.2592', fillKg: '1', tax: tax14 }));
    expect([r.maxUnitCost, r.maxCostPerKg, r.taxState, r.vatRatePct]).toEqual(['40', '40', 'applied', '14']);
  });

  it('tax switched off: the ladder stops at 71.28 and there is no VAT line', () => {
    const r = must(ladderForward({ ...rates, unitCost: cost, tax: { ...tax14, setting: { mode: 'disabled' } } }));
    expect([r.shelfPriceInclVat, r.vatAmount, r.consumerPrice.value]).toEqual([null, null, '71.28']);
  });

  it('a unit cost with no price on file is a gap, never 0 EGP', () => {
    const r = ladderForward({ ...rates, unitCost: undefined, tax: tax14 });
    expect(!r.ok && r.missing.map((m) => m.property)).toContain('waterfall.unitCost');
  });
});

describe('golden[count_units] pieces / cartons / pallets and display rounding', () => {
  it('1000 pieces at 12 per carton: 83 cartons (996), 4 pieces left; 20 cartons per pallet: 240 pieces per pallet => 4 pallets, 40 pieces left', () => {
    const cfg = { unitsPerCarton: '12', cartonsPerPallet: '20' };
    expect(must(piecesToCartons('1000', cfg))).toMatchObject({ whole: '83', remainder: '4' });
    expect(must(piecesToPallets('1000', cfg))).toMatchObject({ whole: '4', remainder: '40' });
    expect(must(cartonsToPieces('7', cfg))).toBe('84');
    expect(must(palletsToCartons('3', cfg))).toBe('60');
  });

  it('rounding is half-even, once, at display: 0.25 -> 0.2 and 0.35 -> 0.4 at step 0.1; 2.5 -> 2 and 3.5 -> 4 at step 1', () => {
    expect([roundToStep('0.25', '0.1'), roundToStep('0.35', '0.1'), roundToStep('2.5', '1'), roundToStep('3.5', '1')]).toEqual(['0.2', '0.4', '2', '4']);
  });

  it('money 1.005 -> 1.00 and 1.015 -> 1.02 (the digit before the half decides); percent 12.3455 -> 12.346 and 12.3445 -> 12.344', () => {
    expect([roundMoney(qty('1.005', 'EGP')), roundMoney(qty('1.015', 'EGP'))]).toEqual(['1.00', '1.02']);
    expect([roundPercent('12.3455'), roundPercent('12.3445')]).toEqual(['12.346', '12.344']);
  });

  it('FX with a dated rate: 10 USD × 48.5 = 485 EGP', () => {
    const r = must(convertMoney(qty('10', 'USD'), 'EGP', { from: 'USD', to: 'EGP', rate: '48.5', date: '2026-02-01', sourceId: 'FX-TEST', source: 'TEST' }));
    expect(r.amount.value.toFixed()).toBe('485');
  });
});
