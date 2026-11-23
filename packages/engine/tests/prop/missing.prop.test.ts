/**
 * T-02 properties of the "missing is never zero" rule (CLAUDE.md rule 2): remove any required input of a calculator and it
 * must stop with `missing[]` that names the property, and carry no figure. Magnitudes are random; the removal is the point.
 */
import fc from 'fast-check';
import { describe, expect, it } from 'vitest';
import {
  ENGINE_VERSION,
  activeMatterOfBlend,
  aerosol,
  backwardWaterfall,
  calculate,
  fineFragrance,
  forwardWaterfall,
  neutralize,
  processLoss,
  qty,
  rawMassForTarget,
  roomSpray,
  soapLye,
  waterToReachTarget,
  type EngineInput,
  type Result,
} from '../../src/index';
import { fixture } from '../../src/test-fixtures';
import { inputLeaf } from '../../src/trace/trace';
import { fromScaled, params } from './arbitraries';

/** 1.00 .. 99.00 with two decimals: a valid percentage for every calculator below. */
const pct = fc.integer({ min: 100, max: 9900 }).map((n) => fromScaled(n, 2));
const grams = fc.integer({ min: 1, max: 1_000_000 }).map(String);
const sap = fc.integer({ min: 100, max: 300 }).map((n) => fromScaled(n, 3));

interface Case<V> {
  name: string;
  /** Builds a valid input from the random values with the named field removed (undefined removes nothing). */
  build: (v: V, without: string | undefined) => () => Result<unknown>;
  required: { field: string; property: string }[];
  values: fc.Arbitrary<V>;
}

const drop = <T>(field: string | undefined, name: string, value: T): T | undefined => (field === name ? undefined : value);

const cases: Case<{ p: string; g: string; s: string }>[] = [
  {
    name: 'soapLye',
    values: fc.record({ p: pct, g: grams, s: sap }),
    required: [{ field: 'purity', property: 'purity' }, { field: 'sap', property: 'sap.typ' }],
    build: (v, w) => () =>
      soapLye({
        oils: [{ oilId: 'OIL', mass: qty(v.g, 'g'), sap: { unit: 'gNaOH/g', typ: drop(w, 'sap', fixture(v.s)) as never } }],
        base: 'NaOH',
        purity: drop(w, 'purity', fixture(v.p, '%w/w')) as never,
        superfatPct: '5',
        policy: {},
      }),
  },
  {
    name: 'activeMatterOfBlend',
    values: fc.record({ p: pct, g: grams, s: sap }),
    required: [{ field: 'activeMatter', property: 'activeMatter' }],
    build: (v, w) => () => activeMatterOfBlend({ lines: [{ materialId: 'A', mass: qty(v.g, 'g'), contributesActive: true, activeMatter: drop(w, 'activeMatter', fixture(v.p, '%w/w')) }], policy: {} }),
  },
  {
    name: 'rawMassForTarget',
    values: fc.record({ p: pct, g: grams, s: sap }),
    required: [{ field: 'rawActive', property: 'rawActive' }],
    build: (v, w) => () => rawMassForTarget({ batchMass: qty(v.g, 'g'), targetActivePct: '0.5', rawActive: drop(w, 'rawActive', fixture(v.p, '%w/w')) as never, policy: {} }),
  },
  {
    name: 'waterToReachTarget',
    values: fc.record({ p: pct, g: grams, s: sap }),
    required: [{ field: 'currentActive', property: 'currentActive' }],
    build: (v, w) => () => waterToReachTarget({ existingMass: qty(v.g, 'g'), currentActive: drop(w, 'currentActive', fixture(v.p, '%w/w')), targetActivePct: '0.5', policy: {} }),
  },
  {
    name: 'neutralize',
    values: fc.record({ p: pct, g: grams, s: sap }),
    required: [{ field: 'acidValue', property: 'acidValue' }, { field: 'strength', property: 'purity' }],
    build: (v, w) => () =>
      neutralize({ acidMass: qty(v.g, 'g'), acidValue: drop(w, 'acidValue', fixture(v.s, 'mgKOH/g')) as never, base: 'KOH', form: 'solid', strength: drop(w, 'strength', fixture(v.p, '%w/w')) as never, policy: {} }),
  },
  {
    name: 'fineFragrance',
    values: fc.record({ p: pct, g: grams, s: sap }),
    required: [{ field: 'alcoholSource', property: 'alcoholSource' }],
    build: (v, w) => () => fineFragrance({ fragranceOilPct: '10', alcoholSource: drop(w, 'alcoholSource', fixture('96', '%v/v')), alcoholTargetPctVV: '70', batch: { mass: qty(v.g, 'g') }, policy: {} }),
  },
  {
    name: 'aerosol',
    values: fc.record({ p: pct, g: grams, s: sap }),
    required: [{ field: 'propellantRatio', property: 'propellantRatio' }],
    build: (v, w) => () => aerosol({ propellantRatio: drop(w, 'propellantRatio', fixture('3', 'pc')), ratioBasis: 'tds', fragranceOilPctOfConcentrate: '10', netFillMass: qty(v.g, 'g'), policy: {} }),
  },
  {
    name: 'roomSpray',
    values: fc.record({ p: pct, g: grams, s: sap }),
    required: [{ field: 'solubilizerRatio', property: 'solubilizerRatio' }],
    build: (v, w) => () => roomSpray({ fragranceOilPct: '2', solubilizerRatio: drop(w, 'solubilizerRatio', fixture('2', 'pc')), ratioBasis: 'tds', batchMass: qty(v.g, 'g'), policy: {} }),
  },
];

describe('property: a calculator with a required input removed says what is missing and returns no figure', () => {
  for (const c of cases) {
    it(`${c.name}: complete input is ok; each of [${c.required.map((r) => r.field).join(', ')}] removed is not ok and is named`, () => {
      fc.assert(
        fc.property(c.values, (v) => {
          expect(c.build(v, undefined)().ok).toBe(true);
          for (const { field, property } of c.required) {
            const r = c.build(v, field)();
            expect(r.ok).toBe(false);
            expect(!r.ok && r.missing.map((m) => m.property)).toContain(property);
            expect('value' in r).toBe(false);
          }
        }),
        params,
      );
    });
  }

  it('processLoss: every stage left out is listed; none is read as 0 % loss', () => {
    const stages = ['ageing', 'chilling', 'filtration'] as const;
    fc.assert(
      fc.property(fc.subarray([...stages]), pct, (given, p) => {
        const r = processLoss({ stages: Object.fromEntries(given.map((s) => [s, fixture(p, '%w/w')])), policy: {} });
        if (given.length === 3) {
          expect(r.ok).toBe(true);
        } else {
          expect(r.ok).toBe(false);
          expect(!r.ok && r.missing.map((m) => m.property).sort()).toEqual(stages.filter((s) => !given.includes(s)).map((s) => `loss.${s}`).sort());
        }
      }),
      params,
    );
  });
});

describe('property: the price waterfall lists every missing rate and gives no price', () => {
  const rateFields = ['factoryMarginPct', 'distributionCostPerUnit', 'wholesalerMarginPct', 'retailerMarginPct'] as const;
  const regime = { productFamilyId: 'FAM', taxType: 'vat', ratePct: '14', validFrom: '2026-01-01', sourceId: 'TAX-TEST', confidence: 'L4_regulatory_db' } as const;
  const tax = { setting: { mode: 'rate', ratePct: '14' } as const, regimes: [regime], productFamilyId: 'FAM', taxType: 'vat', asOf: '2026-06-01' };

  it('forward: any subset of the four rates removed => missing exactly those, never a price built on an assumed 0', () => {
    fc.assert(
      fc.property(fc.subarray([...rateFields], { minLength: 1 }), pct, (removed, p) => {
        const full = { unitCost: inputLeaf('Unit cost', '10', 'EGP'), marginBasis: 'markup_on_cost' as const, factoryMarginPct: p, distributionCostPerUnit: '1', wholesalerMarginPct: p, retailerMarginPct: p, tax, policy: {} };
        expect(forwardWaterfall(full).ok).toBe(true);
        const input = { ...full, ...Object.fromEntries(removed.map((f) => [f, undefined])) };
        const r = forwardWaterfall(input);
        expect(r.ok).toBe(false);
        expect(!r.ok && r.missing.map((m) => m.property).sort()).toEqual(removed.map((f) => `waterfall.${f}`).sort());
      }),
      params,
    );
  });

  it('backward: the shelf price and the VAT rate are required; without them no maximum cost is returned', () => {
    fc.assert(
      fc.property(fc.constantFrom('shelfPriceInclVat', 'vatRatePct', 'retailerMarginPct'), pct, (field, p) => {
        const full = { shelfPriceInclVat: '100', vatRatePct: '14', retailerMarginPct: p, wholesalerMarginPct: p, distributionCostPerUnit: '1', factoryMarginPct: p, marginBasis: 'markup_on_cost' as const, fillKg: '1' };
        expect(backwardWaterfall(full).ok).toBe(true);
        const r = backwardWaterfall({ ...full, [field]: undefined });
        expect(r.ok).toBe(false);
        expect(!r.ok && r.missing.map((m) => m.property)).toContain(`waterfall.${field}`);
      }),
      params,
    );
  });
});

describe('property: calculate() never turns a missing input into a zero', () => {
  const sv = (value: string, unit: 'EGP' | 'kg/L') => ({ value, unit, sourceId: 'S', confidence: 'L2_supplier_tds' as const, validFrom: '2026-01-01' });
  const base = (p: string): EngineInput => ({
    engineVersion: ENGINE_VERSION,
    datasetVersion: 'prop',
    formula: { lines: [{ materialId: 'A', percentWW: '60' }, { materialId: 'B', percentWW: '40' }], productDensity: sv('1.1', 'kg/L') },
    batch: { size: { value: '10', unit: 'kg' }, wastagePct: '5', packSize: { value: '500', unit: 'g' } },
    prices: { A: sv(p, 'EGP'), B: sv(p, 'EGP') },
    packaging: { perPack: sv('2', 'EGP') },
    pricing: { marginPct: '20' },
    policy: { rounding: 'default' },
  });
  const priceArb = fc.integer({ min: 1, max: 1_000_000 }).map((n) => fromScaled(n, 2));

  it('a missing price, packaging cost or density leaves the dependent figures out of results and names the gap', () => {
    fc.assert(
      fc.property(priceArb, fc.constantFrom('priceA', 'priceB', 'packaging', 'density'), (p, what) => {
        expect(calculate(base(p)).ok).toBe(true);
        const input = base(p);
        if (what === 'priceA') {
          delete (input.prices as Record<string, unknown>).A;
        } else if (what === 'priceB') {
          delete (input.prices as Record<string, unknown>).B;
        } else if (what === 'packaging') {
          delete input.packaging;
        } else {
          delete input.formula.productDensity;
        }
        const out = calculate(input);
        expect(out.ok).toBe(false);
        expect(out.missing.length).toBeGreaterThan(0);
        // a remainder of 0 packs is a real zero; a cost or a price of 0 would be a missing input read as zero
        for (const [key, r] of Object.entries(out.results)) {
          if (key.startsWith('cost.') || key.startsWith('price.')) {
            expect(r.value).not.toBe('0');
          }
        }
        const dependent: Record<string, string[]> = {
          priceA: ['cost.materialPerKg', 'cost.perKg', 'cost.perPack', 'cost.batch', 'price.perKg'],
          priceB: ['cost.materialPerKg', 'cost.perKg', 'cost.perPack', 'cost.batch', 'price.perKg'],
          packaging: ['cost.perPack', 'cost.batch', 'cost.packagingBatch', 'price.perPack'],
          density: ['cost.perL', 'batch.netVolume', 'price.perL'],
        };
        for (const key of dependent[what]!) {
          expect(out.results[key]).toBeUndefined();
        }
      }),
      params,
    );
  });
});
