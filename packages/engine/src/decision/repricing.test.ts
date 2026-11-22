import { describe, expect, it } from 'vitest';
import { maxPriceChange, REPRICING_REASONS } from './repricing';

describe('Y-05 price change since the approved cost', () => {
  it('case 1: 10 -> 10.5 is 5 %; at a 5 % threshold nothing is above it', () => {
    const r = maxPriceChange({ a: '10' }, { a: '10.5' }, '5');
    expect(r.ok && [r.value.maxChangePct, r.value.changedMaterialIds]).toEqual(['5', []]);
  });

  it('case 2: a 100 -> 104 (4 %) and b 40 -> 44 (10 %): max is 10 %, only b is above a 5 % threshold', () => {
    const r = maxPriceChange({ a: '100', b: '40' }, { a: '104', b: '44' }, '5');
    expect(r.ok && [r.value.maxChangePct, r.value.changedMaterialIds, r.value.changesPct]).toEqual(['10', ['b'], { a: '4', b: '10' }]);
  });

  it('case 3: a fall counts as much as a rise: 80 -> 60 is 25 %', () => {
    const r = maxPriceChange({ a: '80' }, { a: '60' });
    expect(r.ok && [r.value.maxChangePct, r.value.changedMaterialIds]).toEqual(['25', ['a']]);
  });

  it('case 4: nothing moved is 0 % and lists no material', () => {
    const r = maxPriceChange({ a: '12.5' }, { a: '12.5' });
    expect(r.ok && [r.value.maxChangePct, r.value.changedMaterialIds]).toEqual(['0', []]);
  });

  it('a price that is gone now is a gap, never 0 %', () => {
    const r = maxPriceChange({ a: '10', b: '5' }, { a: '10' });
    expect(r.ok).toBe(false);
    expect(!r.ok && r.missing).toEqual([{ materialId: 'b', property: 'price', reason: REPRICING_REASONS.CURRENT_MISSING }]);
  });

  it('an approved price that is not positive cannot be compared; a bad threshold is refused', () => {
    const zero = maxPriceChange({ a: '0' }, { a: '1' });
    expect(!zero.ok && zero.missing[0]?.reason).toBe(REPRICING_REASONS.APPROVED_NOT_POSITIVE);
    const bad = maxPriceChange({ a: '1' }, { a: '1' }, '-5');
    expect(!bad.ok && bad.missing[0]?.reason).toBe(REPRICING_REASONS.THRESHOLD_INVALID);
  });
});
