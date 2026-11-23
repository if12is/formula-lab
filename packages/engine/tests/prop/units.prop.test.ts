/**
 * T-02 properties of unit conversion and rounding: round trips, exact count splits, idempotent half-even rounding.
 * (Density and FX round trips live in tests/core.prop.test.ts, 10,000 runs each.)
 */
import fc from 'fast-check';
import { describe, expect, it } from 'vitest';
import {
  D,
  UNITS,
  convertChlorineBasis,
  densityFromWW,
  dimensionOf,
  percentFromWeight,
  piecesToCartons,
  piecesToPallets,
  cartonsToPieces,
  cartonsToPallets,
  palletsToCartons,
  qty,
  quantize,
  roundToStep,
  vvFromWW,
  wwFromVV,
  type Dec,
  type Unit,
} from '../../src/index';
import { fromScaled, params } from './arbitraries';

const decimal = fc.tuple(fc.bigInt({ min: 0n, max: 10n ** 15n }), fc.integer({ min: 0, max: 8 })).map(([n, s]) => fromScaled(n, s));
const sameDimension = (dimension: string): Unit[] => UNITS.filter((u) => ['mass', 'volume'].includes(dimensionOf(u)) && dimensionOf(u) === dimension);
const massUnits = sameDimension('mass');
const volumeUnits = sameDimension('volume');

describe('property: unit conversion round-trips', () => {
  it('every mass unit through every other and back returns the same value (exact)', () => {
    fc.assert(
      fc.property(decimal, fc.constantFrom(...massUnits), fc.constantFrom(...massUnits), (v, a, b) => {
        expect(qty(v, a).to(b).to(a).value.eq(new D(v))).toBe(true);
      }),
      params,
    );
  });

  it('every volume unit through every other and back returns the same value (exact)', () => {
    fc.assert(
      fc.property(decimal, fc.constantFrom(...volumeUnits), fc.constantFrom(...volumeUnits), (v, a, b) => {
        expect(qty(v, a).to(b).to(a).value.eq(new D(v))).toBe(true);
      }),
      params,
    );
  });

  it('three-step chains agree with the direct conversion (kg -> g -> mg equals kg -> mg)', () => {
    fc.assert(
      fc.property(decimal, (v) => {
        expect(qty(v, 'kg').to('g').to('mg').value.eq(qty(v, 'kg').to('mg').value)).toBe(true);
      }),
      params,
    );
  });

  it('percent from weight and back: pct = 100 × a / B, then a = pct × B / 100 (to 25 significant digits)', () => {
    const grams = fc.integer({ min: 1, max: 1_000_000_000 }).map(String);
    const kilos = fc.integer({ min: 1, max: 1_000_000 }).map(String);
    fc.assert(
      fc.property(grams, kilos, (a, kg) => {
        // only amounts that fit in the batch are a valid share
        fc.pre(new D(a).lte(new D(kg).mul(1000)));
        const r = percentFromWeight({ amount: qty(a, 'g'), batchSize: qty(kg, 'kg') });
        if (!r.ok) {
          throw new Error('unexpected missing');
        }
        const back = new D(r.value.value).mul(kg).mul(1000).div(100);
        expect(back.toSignificantDigits(25).eq(new D(a).toSignificantDigits(25))).toBe(true);
      }),
      params,
    );
  });

  it('available chlorine NaOCl -> Cl2 -> NaOCl returns the value (25 significant digits)', () => {
    fc.assert(
      fc.property(decimal, (v) => {
        const back = convertChlorineBasis(convertChlorineBasis(new D(v), 'NaOCl', 'Cl2'), 'Cl2', 'NaOCl');
        expect(back.toSignificantDigits(25).eq(new D(v).toSignificantDigits(25))).toBe(true);
      }),
      params,
    );
  });
});

describe('property: alcohol table conversions (shipped OIML R 22 table)', () => {
  const pct = fc.integer({ min: 0, max: 100_000 }).map((n) => fromScaled(n, 3));
  const get = (r: { ok: boolean; value?: Dec; missing?: unknown }): Dec => {
    if (!r.ok) {
      throw new Error(`unexpected: ${JSON.stringify(r.missing)}`);
    }
    return r.value as Dec;
  };

  it('%w/w -> %v/v -> %w/w returns the value (each table segment is linear both ways)', () => {
    fc.assert(
      fc.property(pct, (ww) => {
        const back = get(wwFromVV(get(vvFromWW(ww)).toFixed()));
        expect(back.toSignificantDigits(20).minus(new D(ww).toSignificantDigits(20)).abs().lte('1e-17')).toBe(true);
      }),
      params,
    );
  });

  it('%v/v rises with %w/w and density falls as ethanol rises (data sanity)', () => {
    fc.assert(
      fc.property(pct, pct, (a, b) => {
        const [lo, hi] = new D(a).lte(b) ? [a, b] : [b, a];
        expect(get(vvFromWW(lo)).lte(get(vvFromWW(hi)))).toBe(true);
        expect(get(densityFromWW(lo)).gte(get(densityFromWW(hi)))).toBe(true);
      }),
      params,
    );
  });
});

describe('property: counting units split exactly', () => {
  const cfg = fc.record({ unitsPerCarton: fc.integer({ min: 1, max: 500 }).map(String), cartonsPerPallet: fc.integer({ min: 1, max: 200 }).map(String) });
  const count = fc.integer({ min: 0, max: 10_000_000 }).map(String);
  const take = <T>(r: { ok: boolean; value?: T; missing?: unknown }): T => {
    if (!r.ok) {
      throw new Error(`unexpected: ${JSON.stringify(r.missing)}`);
    }
    return r.value as T;
  };

  it('pieces = whole cartons × units per carton + remainder, with 0 ≤ remainder < units per carton', () => {
    fc.assert(
      fc.property(count, cfg, (pieces, c) => {
        const r = take(piecesToCartons(pieces, c));
        const rebuilt = new D(take(cartonsToPieces(r.whole, c))).plus(r.remainder);
        expect(rebuilt.eq(pieces)).toBe(true);
        expect(new D(r.remainder).gte(0) && new D(r.remainder).lt(c.unitsPerCarton)).toBe(true);
      }),
      params,
    );
  });

  it('pieces -> pallets: whole pallets × pieces per pallet + remainder pieces = pieces; cartons -> pallets -> cartons never gains', () => {
    fc.assert(
      fc.property(count, cfg, (pieces, c) => {
        const p = take(piecesToPallets(pieces, c));
        const perPallet = new D(c.unitsPerCarton).mul(c.cartonsPerPallet);
        expect(new D(p.whole).mul(perPallet).plus(p.remainder).eq(pieces)).toBe(true);
        const cartons = take(piecesToCartons(pieces, c)).whole;
        const w = take(cartonsToPallets(cartons, c));
        expect(new D(take(palletsToCartons(w.whole, c))).lte(cartons)).toBe(true);
      }),
      params,
    );
  });
});

describe('property: display rounding', () => {
  const step = fc.constantFrom('0.001', '0.01', '0.1', '0.5', '1', '5');
  const value = fc.tuple(fc.bigInt({ min: 0n, max: 10n ** 12n }), fc.integer({ min: 0, max: 6 })).map(([n, s]) => fromScaled(n, s));

  it('is idempotent, lands on a multiple of the step and is at most half a step away', () => {
    fc.assert(
      fc.property(value, step, (v, s) => {
        const once = roundToStep(v, s);
        expect(roundToStep(once, s)).toBe(once);
        expect(new D(once).mod(s).isZero()).toBe(true);
        expect(new D(once).minus(v).abs().lte(new D(s).div(2))).toBe(true);
        expect(quantize(v, s).eq(once)).toBe(true);
      }),
      params,
    );
  });

  it('is monotonic: a larger value never rounds to a smaller one', () => {
    fc.assert(
      fc.property(value, value, step, (a, b, s) => {
        const [lo, hi] = new D(a).lte(b) ? [a, b] : [b, a];
        expect(new D(roundToStep(lo, s)).lte(roundToStep(hi, s))).toBe(true);
      }),
      params,
    );
  });
});
