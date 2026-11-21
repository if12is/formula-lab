import Decimal from 'decimal.js';

/**
 * The engine's only Decimal constructor: precision 34, ROUND_HALF_EVEN
 * (ADR 001). Cloned so that nothing else can change its global config.
 */
export const D = Decimal.clone({ precision: 34, rounding: Decimal.ROUND_HALF_EVEN });

/** Instance type of the engine Decimal. */
export type Dec = InstanceType<typeof D>;

const DECIMAL_STRING = /^-?\d+(\.\d+)?$/;

/** True for plain decimal strings such as "12", "-0.5", "3.14" (no exponent, no spaces). */
export function isDecimalString(x: unknown): x is string {
  return typeof x === 'string' && DECIMAL_STRING.test(x);
}

/**
 * Builds a Decimal from a decimal string or an existing Decimal.
 * JS numbers are rejected (type-level and at runtime) so floats never enter the engine.
 */
export function dec(x: string | Dec): Dec {
  if (x instanceof D) {
    // Re-wrap Decimals from other clones so later arithmetic always uses the engine config.
    return x.constructor === D ? x : new D(x);
  }
  if (isDecimalString(x)) {
    return new D(x);
  }
  throw new TypeError(
    `dec(): expected a decimal string or Decimal, got ${typeof x === 'string' ? JSON.stringify(x) : typeof x}`,
  );
}
