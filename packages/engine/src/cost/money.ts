import type { SourcedValue } from '../contract';
import { dec } from '../core/decimal';
import { convertMoney, isIsoDate, type FxRate } from '../core/fx';
import { missing, ok, type Result } from '../core/result';
import { currencyOf, qty } from '../core/quantity';
import { gateConfidence, type ConfidencePolicy } from '../trace/sourced';
import { referenceLeaf, sourcedLeaf, traced, type Traced } from '../trace/trace';

export interface MoneyContext {
  fxRates?: readonly FxRate[];
  policy: ConfidencePolicy;
}

/**
 * A dated, sourced money value as an EGP trace node. Non-EGP values need an explicit dated
 * rate in ctx.fxRates (no default rates; a rate with `forDate` applies only to prices dated that day); L7 needs a signed override; low confidence warns.
 * Missing/invalid input is reported under `property`, never defaulted to zero.
 */
export function sourcedEgp(
  materialId: string | undefined,
  property: string,
  label: string,
  sv: SourcedValue | undefined,
  ctx: MoneyContext,
): Result<Traced> {
  const gap = (reason: string): Result<Traced> => missing([{ materialId, property, reason }]);
  if (sv === undefined) {
    return gap('cost.price_missing');
  }
  const currency = currencyOf(sv.unit);
  if (currency === undefined) {
    return gap('cost.price_not_money');
  }
  if (dec(sv.value).isNegative()) {
    return gap('cost.price_negative');
  }
  if (!isIsoDate(sv.validFrom)) {
    return gap('cost.price_not_dated');
  }
  const gated = gateConfidence(materialId, property, sv, ctx.policy);
  if (!gated.ok) {
    return gated;
  }
  const leaf = sourcedLeaf(label, sv);
  if (currency === 'EGP') {
    return ok(leaf, gated.warnings);
  }
  const rate = ctx.fxRates?.find((r) => r.from === currency && r.to === 'EGP' && (r.forDate === undefined || r.forDate === sv.validFrom));
  if (rate === undefined) {
    return missing([{ materialId, property: `fx:${currency}->EGP`, reason: 'cost.fx_rate_required' }], gated.warnings);
  }
  const converted = convertMoney(qty(sv.value, currency), 'EGP', rate);
  if (!converted.ok) {
    return converted;
  }
  const rateLeaf = referenceLeaf(`FX ${currency}->EGP ${rate.date} (${rate.source})`, rate.rate, 'EGP', rate.sourceId, rate.confidence);
  return ok(traced('fx', `${label} in EGP`, converted.value.amount.value, 'EGP', [leaf, rateLeaf]), gated.warnings);
}
