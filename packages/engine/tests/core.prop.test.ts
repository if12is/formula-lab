import { describe, expect, it } from 'vitest';
import fc from 'fast-check';
import { D, qty, massToVolume, volumeToMass, convertMoney, type DensityRef, type Dec } from '../src/index';

// Deterministic CI: fixed seed, 10,000 runs per property.
fc.configureGlobal({ seed: 42, numRuns: 10000 });

/** Decimal strings with up to 20 integer digits and up to 12 fractional digits. */
const decimalString = (opts: { positive?: boolean } = {}) =>
  fc
    .tuple(
      fc.bigInt({ min: opts.positive ? 1n : 0n, max: 10n ** 20n }),
      fc.integer({ min: 0, max: 12 }),
    )
    .map(([n, scale]) => {
      const s = n.toString().padStart(scale + 1, '0');
      return scale === 0 ? s : `${s.slice(0, -scale)}.${s.slice(-scale)}`;
    })
    .filter((s) => !opts.positive || !new D(s).isZero());

/** Densities 0.5000-2.0000 g/mL, 4 decimals. */
const density = fc
  .integer({ min: 5000, max: 20000 })
  .map((n) => `${Math.floor(n / 10000)}.${String(n % 10000).padStart(4, '0')}`);

/**
 * mass -> volume -> mass divides then multiplies by an arbitrary density, so the result is only
 * exact up to Decimal's 34-digit precision. Equality is asserted after rounding both sides to 30
 * significant digits, which leaves 4 guard digits for the (at most 1 ulp each) intermediate errors.
 */
const sig30 = (x: Dec): string => x.toSignificantDigits(30).toFixed();

describe('property: conversions round trip', () => {
  it('g -> kg -> g is exact', () => {
    fc.assert(
      fc.property(decimalString(), (v) => {
        const back = qty(v, 'g').to('kg').to('g');
        expect(back.value.eq(new D(v))).toBe(true);
      }),
    );
  });

  it('mL -> L -> mL is exact', () => {
    fc.assert(
      fc.property(decimalString(), (v) => {
        const back = qty(v, 'mL').to('L').to('mL');
        expect(back.value.eq(new D(v))).toBe(true);
      }),
    );
  });

  it('mg -> g -> kg -> mg is exact', () => {
    fc.assert(
      fc.property(decimalString(), (v) => {
        expect(qty(v, 'mg').to('g').to('kg').to('mg').value.eq(new D(v))).toBe(true);
      }),
    );
  });

  it('mass -> volume -> mass with random density matches to 30 significant digits', () => {
    fc.assert(
      fc.property(decimalString({ positive: true }), density, (v, d) => {
        const ref: DensityRef = { value: d, unit: 'g/mL', sourceId: 'prop', confidence: 'L3_standard' };
        const vol = massToVolume(qty(v, 'g'), ref);
        if (!vol.ok) {
          throw new Error('unexpected missing');
        }
        const back = volumeToMass(vol.value, ref);
        if (!back.ok) {
          throw new Error('unexpected missing');
        }
        expect(sig30(back.value.value)).toBe(sig30(new D(v)));
      }),
    );
  });

  it('fx A -> B -> A with rate r and its reciprocal, only where 1/r is exact (r = 2^a * 5^b)', () => {
    // Reciprocal of 2^a*5^b is a terminating decimal, so the round trip is mathematically exact.
    const rate = fc
      .tuple(fc.integer({ min: 0, max: 6 }), fc.integer({ min: 0, max: 6 }))
      .map(([a, b]) => new D(2).pow(a).mul(new D(5).pow(b)).toFixed());
    fc.assert(
      fc.property(decimalString(), rate, (v, r) => {
        const rec = new D(1).div(r).toFixed();
        const fwd = convertMoney(qty(v, 'USD'), 'EGP', {
          from: 'USD', to: 'EGP', rate: r, date: '2026-11-20', sourceId: 'p', source: 'CBE',
        });
        if (!fwd.ok) {
          throw new Error('unexpected missing');
        }
        const back = convertMoney(fwd.value.amount, 'USD', {
          from: 'EGP', to: 'USD', rate: rec, date: '2026-11-20', sourceId: 'p', source: 'CBE',
        });
        if (!back.ok) {
          throw new Error('unexpected missing');
        }
        // v has <=12 decimals and rate <= 15625*64 digits: well inside 34 digits, so exact.
        expect(back.value.amount.value.eq(new D(v))).toBe(true);
      }),
    );
  });
});
