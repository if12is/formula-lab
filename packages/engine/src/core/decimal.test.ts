import { describe, expect, it } from 'vitest';
import Decimal from 'decimal.js';
import { D, dec, isDecimalString } from './decimal';

describe('D (configured Decimal)', () => {
  it('0.1 + 0.2 equals exactly 0.3', () => {
    expect(dec('0.1').plus(dec('0.2')).eq(dec('0.3'))).toBe(true);
    expect(dec('0.1').plus('0.2').toString()).toBe('0.3');
  });
  it('has precision 34', () => {
    const third = dec('1').div('3');
    expect(third.precision(true)).toBe(34);
    expect(third.toString()).toBe('0.' + '3'.repeat(34));
  });
  it('rounds half-even', () => {
    expect(dec('2.5').toDecimalPlaces(0).toString()).toBe('2');
    expect(dec('3.5').toDecimalPlaces(0).toString()).toBe('4');
  });
  it('is isolated from the global Decimal config', () => {
    expect(Decimal.precision).toBe(20);
  });
});

describe('dec()', () => {
  it('accepts strings and Decimals (same instance)', () => {
    const x = new D('1.5');
    expect(dec(x)).toBe(x);
    expect(dec('-12.50').toFixed()).toBe('-12.5');
  });
  it('rejects JS numbers at runtime', () => {
    expect(() => dec(0.1 as unknown as string)).toThrow(TypeError);
    expect(() => dec(1 as unknown as string)).toThrow(/number/);
  });
  it('rejects malformed strings', () => {
    for (const bad of ['', 'abc', '1e5', ' 1', '1.', '.5', '--1', 'NaN']) {
      expect(() => dec(bad)).toThrow(TypeError);
    }
  });
  it('re-wraps Decimals from other clones into the engine config', () => {
    const foreign = new Decimal('1');
    const wrapped = dec(foreign as unknown as string);
    expect(wrapped).not.toBe(foreign);
    expect(wrapped.constructor).toBe(D);
    expect(wrapped.div(3).precision(true)).toBe(34);
  });
  it('type-level: numbers do not compile', () => {
    // @ts-expect-error numbers are not accepted
    expect(() => dec(1)).toThrow();
  });
});

describe('isDecimalString', () => {
  it('classifies', () => {
    expect(isDecimalString('12')).toBe(true);
    expect(isDecimalString('-0.5')).toBe(true);
    expect(isDecimalString('1e3')).toBe(false);
    expect(isDecimalString(5)).toBe(false);
    expect(isDecimalString(undefined)).toBe(false);
  });
});
