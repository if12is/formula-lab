import { describe, expect, it } from 'vitest';
import {
  cartonsToPallets, cartonsToPieces, palletsToCartons, palletsToPieces, piecesToCartons, piecesToPallets,
} from './count-units';
import { D } from './decimal';

const cfg = { unitsPerCarton: '12', cartonsPerPallet: new D('50') };

describe('count units', () => {
  it('pieces -> cartons floors with remainder', () => {
    const r = piecesToCartons('100', cfg);
    expect(r.ok && r.value).toEqual({ whole: '8', remainder: '4', remainderUnit: 'piece' });
    const exact = piecesToCartons('24', cfg);
    expect(exact.ok && exact.value).toEqual({ whole: '2', remainder: '0', remainderUnit: 'piece' });
    const zero = piecesToCartons('5', cfg);
    expect(zero.ok && zero.value.whole).toBe('0');
  });
  it('cartons <-> pieces/pallets', () => {
    expect(cartonsToPieces('3', cfg)).toMatchObject({ ok: true, value: '36' });
    const p = cartonsToPallets('120', cfg);
    expect(p.ok && p.value).toEqual({ whole: '2', remainder: '20', remainderUnit: 'carton' });
    expect(palletsToCartons('2', cfg)).toMatchObject({ ok: true, value: '100' });
  });
  it('pieces <-> pallets', () => {
    const p = piecesToPallets('1300', cfg);
    expect(p.ok && p.value).toEqual({ whole: '2', remainder: '100', remainderUnit: 'piece' });
    expect(palletsToPieces('2', cfg)).toMatchObject({ ok: true, value: '1200' });
  });
  it('missing config => Result missing (all gaps reported)', () => {
    for (const r of [
      piecesToCartons('1', {}), cartonsToPieces('1', {}), cartonsToPallets('1', {}), palletsToCartons('1', {}),
    ]) {
      expect(r.ok).toBe(false);
    }
    const both = piecesToPallets('1', {});
    expect(!both.ok && both.missing.map((m) => m.property)).toEqual(['unitsPerCarton', 'cartonsPerPallet']);
    const onlyPallet = palletsToPieces('1', { unitsPerCarton: '12' });
    expect(!onlyPallet.ok && onlyPallet.missing.map((m) => m.property)).toEqual(['cartonsPerPallet']);
    const onlyCarton = palletsToPieces('1', { cartonsPerPallet: '12' });
    expect(!onlyCarton.ok && onlyCarton.missing.map((m) => m.property)).toEqual(['unitsPerCarton']);
    const onlyCarton2 = piecesToPallets('1', { cartonsPerPallet: '12' });
    expect(!onlyCarton2.ok && onlyCarton2.missing.map((m) => m.property)).toEqual(['unitsPerCarton']);
    const onlyPallet2 = piecesToPallets('1', { unitsPerCarton: '12' });
    expect(!onlyPallet2.ok && onlyPallet2.missing.map((m) => m.property)).toEqual(['cartonsPerPallet']);
  });
  it('rejects non-integers, negatives and non-positive config', () => {
    expect(() => piecesToCartons('1.5', cfg)).toThrow(RangeError);
    expect(() => piecesToCartons('-1', cfg)).toThrow(RangeError);
    expect(() => piecesToCartons('1', { unitsPerCarton: '0' })).toThrow(RangeError);
    expect(() => piecesToCartons('1', { unitsPerCarton: '2.5' })).toThrow(RangeError);
    expect(() => piecesToCartons(1 as unknown as string, cfg)).toThrow(TypeError);
  });
});
