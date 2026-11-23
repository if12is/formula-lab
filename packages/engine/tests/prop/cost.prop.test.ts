/**
 * T-02 properties of cost and price: cost is monotonic (and linear) in price, wastage and margin only ever raise it,
 * and the price ladder is monotonic and invertible.
 */
import fc from 'fast-check';
import { describe, expect, it } from 'vitest';
import {
  D,
  ENGINE_VERSION,
  backwardWaterfall,
  calculate,
  costPerNetKg,
  forwardWaterfall,
  materialCostPerKg,
  priceByMargin,
  type EngineInput,
  type SourcedValue,
} from '../../src/index';
import { inputLeaf } from '../../src/trace/trace';
import { delta, formulaLines, fromScaled, params, price } from './arbitraries';

const egp = (value: string, id = 'P'): SourcedValue => ({ value, unit: 'EGP', sourceId: id, confidence: 'L2_supplier_tds', validFrom: '2026-01-01' });
const must = <T>(r: { ok: boolean; value?: T; missing?: unknown }): T => {
  if (!r.ok) {
    throw new Error(`expected ok: ${JSON.stringify(r.missing)}`);
  }
  return r.value as T;
};

/** formula lines with one price each; the list of prices has one entry per line. */
const priced = formulaLines.chain((lines) => fc.tuple(fc.constant(lines), fc.array(price, { minLength: lines.length, maxLength: lines.length })));

describe('property: cost is monotonic in price', () => {
  it('raising one material price by d raises the cost per kg by exactly (p/100) × d, so never lowers it', () => {
    fc.assert(
      fc.property(priced, fc.nat(7), delta, ([lines, prices], at, d) => {
        const j = at % lines.length;
        const cost = (ps: string[]) => new D(must(materialCostPerKg({ lines, prices: Object.fromEntries(lines.map((l, i) => [l.materialId, egp(ps[i]!)])), policy: {} })).value);
        const raised = prices.map((p, i) => (i === j ? new D(p).plus(d).toFixed() : p));
        const before = cost(prices);
        const after = cost(raised);
        expect(after.gte(before)).toBe(true);
        expect(after.minus(before).eq(new D(lines[j]!.percentWW).div(100).mul(d))).toBe(true);
        if (new D(d).gt(0)) {
          expect(after.gt(before)).toBe(true);
        }
      }),
      params,
    );
  });

  it('scaling every price by k scales the cost per kg by exactly k', () => {
    fc.assert(
      fc.property(priced, fc.integer({ min: 1, max: 1000 }), ([lines, prices], k) => {
        const cost = (ps: string[]) => new D(must(materialCostPerKg({ lines, prices: Object.fromEntries(lines.map((l, i) => [l.materialId, egp(ps[i]!)])), policy: {} })).value);
        const scaled = prices.map((p) => new D(p).mul(k).toFixed());
        expect(cost(scaled).eq(cost(prices).mul(k))).toBe(true);
      }),
      params,
    );
  });

  it('through calculate(): a higher price never lowers cost.perKg, cost.perL, cost.batch or price.perPack', () => {
    const sku = (pA: string, pB: string): EngineInput => ({
      engineVersion: ENGINE_VERSION,
      datasetVersion: 'prop',
      formula: { lines: [{ materialId: 'A', percentWW: '60' }, { materialId: 'B', percentWW: '40' }], productDensity: { value: '1.02', unit: 'kg/L', sourceId: 'LAB', confidence: 'L1_coa_batch', validFrom: '2026-01-01' } },
      batch: { size: { value: '50', unit: 'kg' }, wastagePct: '5', packSize: { value: '500', unit: 'g' } },
      prices: { A: egp(pA, 'PA'), B: egp(pB, 'PB') },
      packaging: { perPack: egp('2.5', 'PK') },
      pricing: { marginPct: '25' },
      policy: { rounding: 'default' },
    });
    fc.assert(
      fc.property(price, price, delta, delta, (pA, pB, dA, dB) => {
        const lo = calculate(sku(pA, pB));
        const hi = calculate(sku(new D(pA).plus(dA).toFixed(), new D(pB).plus(dB).toFixed()));
        expect(lo.ok && hi.ok).toBe(true);
        for (const key of ['cost.perKg', 'cost.perL', 'cost.perPack', 'cost.batch', 'price.perKg', 'price.perPack']) {
          expect(new D(hi.results[key]!.value).gte(lo.results[key]!.value)).toBe(true);
        }
      }),
      params,
    );
  });
});

describe('property: wastage and margin only ever raise a figure', () => {
  const wastage = fc.integer({ min: 0, max: 9999 }).map((n) => fromScaled(n, 2));

  it('cost per net kg ≥ cost per kg, and rises with wastage', () => {
    fc.assert(
      fc.property(price, wastage, wastage, (p, w1, w2) => {
        const base = inputLeaf('Material cost', p, 'EGP');
        const a = new D(must(costPerNetKg(base, w1)).value);
        const b = new D(must(costPerNetKg(base, w2)).value);
        expect(a.gte(p)).toBe(true);
        expect(new D(w1).lte(w2) ? a.lte(b) : a.gte(b)).toBe(true);
      }),
      params,
    );
  });

  it('price by margin ≥ cost, and rises with the margin (margins below 100 %)', () => {
    fc.assert(
      fc.property(price, wastage, wastage, (p, m1, m2) => {
        const base = inputLeaf('Unit cost', p, 'EGP');
        const a = new D(must(priceByMargin(base, m1)).value);
        const b = new D(must(priceByMargin(base, m2)).value);
        expect(a.gte(p)).toBe(true);
        expect(new D(m1).lte(m2) ? a.lte(b) : a.gte(b)).toBe(true);
      }),
      params,
    );
  });
});

describe('property: the price ladder is monotonic and invertible', () => {
  const pct = fc.integer({ min: 0, max: 9000 }).map((n) => fromScaled(n, 2));
  const basis = fc.constantFrom('markup_on_cost' as const, 'margin_on_price' as const);
  const regime = { productFamilyId: 'FAM', taxType: 'vat', ratePct: '14', validFrom: '2026-01-01', sourceId: 'TAX-TEST', confidence: 'L4_regulatory_db' } as const;
  const tax = { setting: { mode: 'rate', ratePct: '14' } as const, regimes: [regime], productFamilyId: 'FAM', taxType: 'vat', asOf: '2026-06-01' };
  const rates = fc.record({ marginBasis: basis, factoryMarginPct: pct, wholesalerMarginPct: pct, retailerMarginPct: pct, distributionCostPerUnit: delta });
  const forward = (unit: string, r: { marginBasis: 'markup_on_cost' | 'margin_on_price'; factoryMarginPct: string; wholesalerMarginPct: string; retailerMarginPct: string; distributionCostPerUnit: string }) =>
    must(forwardWaterfall({ unitCost: inputLeaf('Unit cost', unit, 'EGP'), ...r, tax, policy: {} }));

  it('a higher unit cost never gives a lower shelf price', () => {
    fc.assert(
      fc.property(price, delta, rates, (c, d, r) => {
        const lo = new D(forward(c, r).shelfPriceInclVat!.value);
        const hi = new D(forward(new D(c).plus(d).toFixed(), r).shelfPriceInclVat!.value);
        expect(hi.gte(lo)).toBe(true);
      }),
      params,
    );
  });

  it('a higher retailer margin never gives a lower shelf price', () => {
    fc.assert(
      fc.property(price, rates, pct, (c, r, m) => {
        const a = new D(forward(c, r).shelfPriceInclVat!.value);
        const b = new D(forward(c, { ...r, retailerMarginPct: m }).shelfPriceInclVat!.value);
        expect(new D(r.retailerMarginPct).lte(m) ? a.lte(b) : a.gte(b)).toBe(true);
      }),
      params,
    );
  });

  it('shelf -> maximum cost recovers the cost that produced the shelf (to 20 significant digits)', () => {
    fc.assert(
      fc.property(price, rates, (c, r) => {
        const shelf = forward(c, r).shelfPriceInclVat!.value;
        const back = must(
          backwardWaterfall({ shelfPriceInclVat: shelf, vatRatePct: '14', ...r, fillKg: '1' }),
        );
        expect(new D(back.maxUnitCost!).toSignificantDigits(20).eq(new D(c).toSignificantDigits(20))).toBe(true);
      }),
      params,
    );
  });
});
