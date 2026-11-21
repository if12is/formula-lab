import { dec, isDecimalString, type Dec } from '../core/decimal';
import { missing, ok, type Result } from '../core/result';

/** Yield fraction (1 − w/100) for a wastage percent in [0, 100); otherwise missing (never ÷0). */
export function yieldFraction(wastagePct: string): Result<Dec> {
  if (!isDecimalString(wastagePct)) {
    return missing([{ property: 'wastagePct', reason: 'batch.wastage_invalid' }]);
  }
  const w = dec(wastagePct);
  if (w.isNegative() || w.gte(100)) {
    return missing([{ property: 'wastagePct', reason: 'batch.wastage_out_of_range' }]);
  }
  return ok(dec('1').minus(w.div(100)));
}
