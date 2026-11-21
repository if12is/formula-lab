import { D, dec, type Dec } from '@fl/engine';

/** Percent grid of the engine's display step (rounding-policy: 0.001 %). All reported percents live on it. */
export const PERCENT_STEP = '0.001';

/** Half of PERCENT_STEP: the largest error of rounding to nearest. */
export const HALF_STEP = '0.0005';

/**
 * Converts a solver double to a Decimal. This is the ONLY place a JS number enters the optimizer; it is
 * used for HiGHS output (HiGHS computes in IEEE doubles) and never for inputs. 15 significant digits
 * is the most a double round-trips reliably.
 */
export function fromDouble(x: number): Dec {
  if (!Number.isFinite(x)) {
    throw new RangeError('fromDouble(): solver returned a non-finite value');
  }
  return new D(x.toPrecision(15));
}

/** Plain-notation decimal string (never an exponent), with -0 normalised. */
export function str(x: Dec): string {
  return x.isZero() ? '0' : x.toFixed();
}

export function ceilToGrid(x: Dec, step: string = PERCENT_STEP): Dec {
  return x.div(step).ceil().mul(step);
}

export function floorToGrid(x: Dec, step: string = PERCENT_STEP): Dec {
  return x.div(step).floor().mul(step);
}

export function nearestOnGrid(x: Dec, step: string = PERCENT_STEP): Dec {
  return x.div(step).toDecimalPlaces(0, D.ROUND_HALF_EVEN).mul(step);
}

/** Stable ordering by code unit (locale independent). */
export function byCodeUnit(a: string, b: string): number {
  return a < b ? -1 : a > b ? 1 : 0;
}

export { D, dec };
export type { Dec };
