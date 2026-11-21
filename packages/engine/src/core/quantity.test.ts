import { describe, expect, it } from 'vitest';
import {
  currencyOf, dimensionOf, isUnit, Quantity, qty, UNITS, UnitMismatchError,
} from './quantity';
import { D } from './decimal';

describe('Quantity', () => {
  it('is immutable and serializes as strings', () => {
    const q = qty('1.50', 'kg');
    expect(Object.isFrozen(q)).toBe(true);
    expect(q.toJSON()).toEqual({ value: '1.5', unit: 'kg' });
    expect(JSON.stringify(q)).toBe('{"value":"1.5","unit":"kg"}');
    expect(Quantity.fromJSON({ value: '2', unit: 'L' }).unit).toBe('L');
    expect(new Quantity(new D('3'), 'g').value.toFixed()).toBe('3');
  });
  it('accepts D values and rejects numbers and unknown units', () => {
    expect(() => qty(1 as unknown as string, 'g')).toThrow(TypeError);
    expect(() => qty('1', 'furlong' as unknown as 'g')).toThrow(/Unknown unit/);
  });
  it('unit metadata', () => {
    expect(UNITS).toContain('mPa·s');
    expect(isUnit('kg')).toBe(true);
    expect(isUnit('toString')).toBe(false);
    expect(isUnit(3)).toBe(false);
    expect(dimensionOf('mgKOH/g')).toBe('acid-value');
    expect(dimensionOf('%w/v')).toBe('fraction');
    expect(dimensionOf('mPa·s')).toBe('viscosity');
    expect(dimensionOf('kg/L')).toBe('density');
    expect(dimensionOf('pc')).toBe('count');
    expect(qty('1', 'EGP').dimension).toBe('money');
    expect(currencyOf('USD')).toBe('USD');
    expect(currencyOf('kg')).toBeUndefined();
  });
  it('converts inside a dimension exactly', () => {
    expect(qty('1500', 'g').to('kg').value.toFixed()).toBe('1.5');
    expect(qty('2', 'g').to('mg').value.toFixed()).toBe('2000');
    expect(qty('0.25', 'L').to('mL').value.toFixed()).toBe('250');
    expect(qty('1', 'g/mL').to('kg/L').value.toFixed()).toBe('1');
  });
  it('add/sub/compare convert automatically and keep the left unit', () => {
    const sum = qty('1', 'kg').add(qty('500', 'g'));
    expect(sum.toJSON()).toEqual({ value: '1.5', unit: 'kg' });
    expect(qty('1', 'kg').sub(qty('250', 'g')).toJSON()).toEqual({ value: '0.75', unit: 'kg' });
    expect(qty('1', 'kg').compare(qty('1000', 'g'))).toBe(0);
    expect(qty('1', 'kg').compare(qty('999', 'g'))).toBe(1);
    expect(qty('1', 'L').compare(qty('1001', 'mL'))).toBe(-1);
    expect(qty('1', 'kg').equals(qty('1000', 'g'))).toBe(true);
  });
  it('mul/div by dimensionless factors', () => {
    expect(qty('10', 'EGP').mul('1.5').toJSON()).toEqual({ value: '15', unit: 'EGP' });
    expect(qty('10', 'EGP').div(new D('4')).toJSON()).toEqual({ value: '2.5', unit: 'EGP' });
    expect(() => qty('1', 'g').div('0')).toThrow(RangeError);
    expect(() => qty('1', 'g').mul(2 as unknown as string)).toThrow(TypeError);
  });
  it('incompatible operations throw UnitMismatchError', () => {
    const cases: [() => unknown, string][] = [
      [() => qty('1', 'kg').add(qty('1', 'L')), 'add'],
      [() => qty('1', 'kg').sub(qty('1', 'L')), 'sub'],
      [() => qty('1', 'kg').compare(qty('1', 'L')), 'compare'],
      [() => qty('1', 'EGP').add(qty('1', 'USD')), 'add'],
      [() => qty('1', '%w/w').add(qty('1', '%w/v')), 'add'],
      [() => qty('1', 'kg').to('mL' as never), 'convert'],
      [() => qty('1', 'EGP').to('USD'), 'convert'],
    ];
    for (const [fn, op] of cases) {
      expect(fn).toThrow(UnitMismatchError);
      try { fn(); } catch (e) {
        expect((e as UnitMismatchError).operation).toBe(op);
        expect((e as Error).name).toBe('UnitMismatchError');
      }
    }
  });
});
