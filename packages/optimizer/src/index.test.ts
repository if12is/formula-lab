import { expect, it } from 'vitest';
import { OPTIMIZER_VERSION } from './index';

it('exposes a version', () => {
  expect(OPTIMIZER_VERSION).toBe('0.1.0');
});
