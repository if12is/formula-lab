/**
 * Shared helpers of the golden suite (T-01). TEST INPUTS ONLY: every number in tests/golden is fictional
 * and chosen so the hand arithmetic in the comments is exact. None of it is engine or market data.
 */
import { D } from '../../src/index';
import type { OimlTable } from '../../src/core/alcohol';
import type { Result } from '../../src/core/result';

export { fixture } from '../../src/test-fixtures';

/** Unwraps an ok Result; a missing one fails the test with the full `missing[]` so the cause is visible. */
export function must<T>(r: Result<T>): T {
  if (!r.ok) {
    throw new Error(`expected ok, got missing: ${JSON.stringify(r.missing)}`);
  }
  return r.value;
}

/** Rounds a decimal string to 9 places: for results that carry a division by a non-terminating constant. */
export const r9 = (value: string | undefined): string | undefined => (value === undefined ? undefined : new D(value).toDecimalPlaces(9).toFixed());

/**
 * A three-row alcohol table so the OIML interpolation can be done by hand (piecewise linear between rows).
 * FICTIONAL: it is not OIML R 22. Rows are (%v/v, %w/w, density kg/m3):
 *   (0, 0, 1000) - (50, 40, 950) - (100, 100, 800)
 * so %v/v = %w/w x 50/40 on [0, 40] and 50 + (%w/w - 40) x 50/60 on [40, 100].
 */
export const HAND_TABLE: OimlTable = {
  rows: [
    { pctVV: '0', pctWW: '0', densityKgM3: '1000' },
    { pctVV: '50', pctWW: '40', densityKgM3: '950' },
    { pctVV: '100', pctWW: '100', densityKgM3: '800' },
  ],
};

/** Same as `must` for the formula family, whose failures are `errors[]` (formula issues) rather than `missing[]`. */
export function mustFormula<T>(r: { ok: true; value: T } | { ok: false; errors: unknown[] }): T {
  if (!r.ok) {
    throw new Error(`expected ok, got formula errors: ${JSON.stringify(r.errors)}`);
  }
  return r.value;
}
