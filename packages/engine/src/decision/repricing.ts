/**
 * Y-05 input: how far each material's price (EGP per kg) has moved since the cost was approved. The change of one material
 * is |now - approved| / approved x 100; the largest of them is what the publish rule compares with the owner's configured
 * threshold. A price that cannot be compared (absent now, or an approved price that is not positive) is a gap, never a 0 %.
 */
import type { MissingData } from '../contract';
import { dec, isDecimalString } from '../core/decimal';
import { missing, ok, type Result } from '../core/result';

export interface PriceChange {
  /** Largest absolute change, percent, decimal string ("0" when nothing moved). */
  maxChangePct: string;
  /** With a threshold: the materials whose change is above it. Without: every material that changed at all. Sorted. */
  changedMaterialIds: string[];
  /** Absolute change of each material, percent. */
  changesPct: Record<string, string>;
}

export const REPRICING_REASONS = {
  APPROVED_NOT_POSITIVE: 'repricing.approved_price_not_positive',
  CURRENT_MISSING: 'repricing.current_price_missing',
  THRESHOLD_INVALID: 'repricing.threshold_invalid',
} as const;

export function maxPriceChange(
  approved: Readonly<Record<string, string>>,
  current: Readonly<Record<string, string | undefined>>,
  thresholdPct?: string,
): Result<PriceChange> {
  if (thresholdPct !== undefined && (!isDecimalString(thresholdPct) || dec(thresholdPct).isNegative())) {
    return missing([{ property: 'repricing.thresholdPct', reason: REPRICING_REASONS.THRESHOLD_INVALID }]);
  }
  const gaps: MissingData[] = [];
  const changes: Record<string, string> = {};
  let max = dec('0');
  for (const id of Object.keys(approved).sort()) {
    const before = approved[id];
    const now = current[id];
    if (before === undefined || !isDecimalString(before) || dec(before).lte(0)) {
      gaps.push({ materialId: id, property: 'price', reason: REPRICING_REASONS.APPROVED_NOT_POSITIVE });
      continue;
    }
    if (now === undefined || !isDecimalString(now)) {
      gaps.push({ materialId: id, property: 'price', reason: REPRICING_REASONS.CURRENT_MISSING });
      continue;
    }
    const pct = dec(now).minus(dec(before)).abs().div(dec(before)).mul(100);
    changes[id] = pct.toFixed();
    if (pct.gt(max)) {
      max = pct;
    }
  }
  if (gaps.length > 0) {
    return missing(gaps);
  }
  const changed = Object.keys(changes).filter((id) => (thresholdPct === undefined ? dec(changes[id] as string).gt(0) : dec(changes[id] as string).gt(dec(thresholdPct))));
  return ok({ maxChangePct: max.toFixed(), changedMaterialIds: changed.sort(), changesPct: changes });
}
