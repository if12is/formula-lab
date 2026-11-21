import { describe, expect, it } from 'vitest';
import { CONFIDENCE_RANK, parseDec } from './parse';

describe('parseDec', () => {
  it('parses decimal strings', () => {
    expect(parseDec('12.5')?.toFixed()).toBe('12.5');
    expect(parseDec('-3')?.toFixed()).toBe('-3');
  });
  it('returns null for non-decimal input', () => {
    expect(parseDec('1e2')).toBeNull();
    expect(parseDec('')).toBeNull();
    expect(parseDec(1.5)).toBeNull();
    expect(parseDec(undefined)).toBeNull();
  });
});

describe('CONFIDENCE_RANK', () => {
  it('orders L1 best to L7 worst', () => {
    expect(CONFIDENCE_RANK.L1_coa_batch).toBe(1);
    expect(CONFIDENCE_RANK.L7_estimate).toBe(7);
  });
});
