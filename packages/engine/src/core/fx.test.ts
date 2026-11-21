import { describe, expect, it } from 'vitest';
import { convertMoney, type FxRate } from './fx';
import { qty, UnitMismatchError } from './quantity';

const usdEgp: FxRate = { from: 'USD', to: 'EGP', rate: '48.5', date: '2026-11-20', sourceId: 'cbe:2026-11-20', source: 'CBE' };

describe('convertMoney', () => {
  it('converts and carries date, source and sourceId', () => {
    const r = convertMoney(qty('10', 'USD'), 'EGP', usdEgp);
    expect(r.ok).toBe(true);
    if (r.ok) {
      expect(r.value.amount.toJSON()).toEqual({ value: '485', unit: 'EGP' });
      expect(r.value).toMatchObject({ date: '2026-11-20', sourceId: 'cbe:2026-11-20', source: 'CBE', rate: '48.5' });
    }
  });
  it('missing rate => Result missing (no default rate)', () => {
    const r = convertMoney(qty('10', 'USD'), 'EGP', undefined);
    expect(r.ok).toBe(false);
    expect(!r.ok && r.missing[0]?.property).toBe('fx:USD->EGP');
  });
  it('currency mismatch throws', () => {
    expect(() => convertMoney(qty('10', 'EGP'), 'EGP', usdEgp)).toThrow(UnitMismatchError);
    expect(() => convertMoney(qty('10', 'USD'), 'USD', usdEgp)).toThrow(UnitMismatchError);
    expect(() => convertMoney(qty('10', 'kg'), 'EGP', usdEgp)).toThrow(UnitMismatchError);
  });
  it('rejects malformed rates and dates', () => {
    expect(() => convertMoney(qty('1', 'USD'), 'EGP', { ...usdEgp, rate: '0' })).toThrow(RangeError);
    expect(() => convertMoney(qty('1', 'USD'), 'EGP', { ...usdEgp, rate: '1e2' })).toThrow(RangeError);
    expect(() => convertMoney(qty('1', 'USD'), 'EGP', { ...usdEgp, date: '20-11-2026' })).toThrow(RangeError);
    expect(() => convertMoney(qty('1', 'USD'), 'EGP', { ...usdEgp, date: '2026-13-01' })).toThrow(RangeError);
  });
});
