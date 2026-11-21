import { describe, expect, it } from 'vitest';
import { canonicalHash } from './canonical-hash';
import { checkKeys, containsJsNumber, isRecord, nonEmptyString, type Issues } from './validate';

describe('validate helpers', () => {
  it('isRecord / nonEmptyString', () => {
    expect(isRecord({})).toBe(true);
    expect(isRecord([])).toBe(false);
    expect(isRecord(null)).toBe(false);
    expect(nonEmptyString('a')).toBe(true);
    expect(nonEmptyString('  ')).toBe(false);
    expect(nonEmptyString(1)).toBe(false);
  });

  it('containsJsNumber finds numbers at any depth', () => {
    expect(containsJsNumber(1)).toBe(true);
    expect(containsJsNumber({ a: [{ b: 2 }] })).toBe(true);
    expect(containsJsNumber({ a: ['1', true, null] })).toBe(false);
  });

  it('checkKeys reports unknown keys with the given reason', () => {
    const issues: Issues = [];
    checkKeys({ a: '1', z: '2' }, ['a'], '$', issues, 'x.unknown');
    expect(issues).toEqual([{ property: '$.z', reason: 'x.unknown' }]);
  });

  it('canonicalHash ignores key order', () => {
    expect(canonicalHash({ a: '1', b: ['x'] })).toBe(canonicalHash({ b: ['x'], a: '1' }));
    expect(canonicalHash({ a: '1' })).toMatch(/^[0-9a-f]{64}$/);
  });
});
