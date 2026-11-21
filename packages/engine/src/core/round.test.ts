import { describe, expect, it } from 'vitest';
import {
  quantize, roundingResidual, roundMass, roundMoney, roundPercent, roundPhHlb, roundToStep, roundVolume,
} from './round';
import { D } from './decimal';
import { qty, UnitMismatchError } from './quantity';

describe('display rounding', () => {
  it('half-even at each policy step', () => {
    expect(roundMass(qty('12.25', 'g'))).toBe('12.2');
    expect(roundMass(qty('12.35', 'g'))).toBe('12.4');
    expect(roundMass(qty('1.5', 'kg'))).toBe('1500.0');
    expect(roundMass(qty('12.34', 'g'), '0.01')).toBe('12.34');
    expect(roundMass(qty('12.3', 'g'), '0.5')).toBe('12.5');
    expect(roundVolume(qty('2.5', 'mL'))).toBe('2');
    expect(roundVolume(qty('3.5', 'mL'))).toBe('4');
    expect(roundVolume(qty('1.2345', 'L'))).toBe('1234');
    expect(roundPercent('12.3455')).toBe('12.346');
    expect(roundPercent(new D('0.0005'))).toBe('0.000');
    expect(roundMoney(qty('10.005', 'EGP'))).toBe('10.00');
    expect(roundMoney(qty('10.015', 'USD'))).toBe('10.02');
    expect(roundPhHlb('7.25')).toBe('7.2');
    expect(roundPhHlb('7.35')).toBe('7.4');
  });
  it('whole-number steps have no decimals; larger steps work', () => {
    expect(roundToStep('1234.5', '1')).toBe('1234');
    expect(roundToStep('1234', '5')).toBe('1235');
    expect(roundToStep('-2.5', '1')).toBe('-2');
    expect(quantize('2.5', '1').toFixed()).toBe('2');
  });
  it('rejects bad steps, numbers and wrong dimensions', () => {
    expect(() => quantize('1', '0')).toThrow(RangeError);
    expect(() => quantize('1', '-1')).toThrow(RangeError);
    expect(() => quantize(1 as unknown as string, '1')).toThrow(TypeError);
    expect(() => roundMass(qty('1', 'L'))).toThrow(UnitMismatchError);
    expect(() => roundVolume(qty('1', 'g'))).toThrow(UnitMismatchError);
    expect(() => roundMoney(qty('1', 'g'))).toThrow(UnitMismatchError);
  });
});

describe('roundingResidual', () => {
  it('reports the residual when rounded parts do not add up', () => {
    const r = roundingResidual(['33.333', '33.333', '33.334'], '100', '0.01');
    expect(r.roundedParts).toEqual(['33.33', '33.33', '33.33']);
    expect(r.sumOfRoundedParts).toBe('99.99');
    expect(r.roundedTotal).toBe('100.00');
    expect(r.residual).toBe('0.01');
    expect(r.hasResidual).toBe(true);
  });
  it('reports none when they add up, and can go negative', () => {
    const zero = roundingResidual(['1.2', '2.3'], '3.5', '0.1');
    expect(zero.hasResidual).toBe(false);
    expect(zero.residual).toBe('0.0');
    const neg = roundingResidual(['0.06', '0.06'], '0.12', '0.1');
    expect(neg.sumOfRoundedParts).toBe('0.2');
    expect(neg.roundedTotal).toBe('0.1');
    expect(neg.residual).toBe('-0.1');
    expect(neg.hasResidual).toBe(true);
  });
});
