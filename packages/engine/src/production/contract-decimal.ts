import { type Dec, isDecimalString } from '../core/decimal';
import { missing, ok, type Result } from '../core/result';
import { roundToStep } from '../core/round';

/** The quantity and cost scale of the Sanad contract: 6 decimals (sanad-contract-v1.md section 0). */
export const CONTRACT_STEP = '0.000001';

/** A value as a 6-decimal string (half-even, once), the way Sanad takes quantities and unit costs. */
export function toContractDecimal(value: string | Dec): string {
  return roundToStep(value, CONTRACT_STEP);
}

/** Same, for an input that may not be a decimal: missing, never a guess. */
export function contractDecimal(value: unknown, property: string): Result<string> {
  if (!isDecimalString(value)) {
    return missing([{ property, reason: 'production.not_a_decimal' }]);
  }
  return ok(toContractDecimal(value));
}
