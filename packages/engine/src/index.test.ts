import { describe, expect, it } from 'vitest';
import Decimal from 'decimal.js';
import { ENGINE_VERSION } from './index';

describe('engine sanity', () => {
  it('exposes a version', () => {
    expect(ENGINE_VERSION).toBe('0.1.0');
  });
  it('uses decimal arithmetic without float drift', () => {
    expect(new Decimal('0.1').plus('0.2').toString()).toBe('0.3');
  });
});
