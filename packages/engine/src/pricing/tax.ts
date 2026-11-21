/**
 * Dated tax regime lookup (I-04 input shape, plan/02-specs/data-model.sql `tax_regimes`).
 * Rates are INPUTS per product family with a validity window and a source. The engine carries no rate:
 * no matching regime for the as-of date means `missing`, never a default VAT.
 */
import type { Confidence, SourcedValue } from '../contract';
import { dec, isDecimalString } from '../core/decimal';
import { isIsoDate } from '../core/fx';
import { missing, ok, type Result } from '../core/result';
import { gateConfidence, type ConfidencePolicy } from '../trace/sourced';
import { sourcedLeaf, type Traced } from '../trace/trace';

export interface TaxRegime {
  productFamilyId: string;
  /** Free text key, e.g. 'vat'. The caller names the tax it wants; the engine does not know any. */
  taxType: string;
  /** Decimal percent, 0..100. */
  ratePct: string;
  /** ISO date, inclusive. */
  validFrom: string;
  /** ISO date, inclusive. Open-ended when omitted. */
  validTo?: string;
  sourceId: string;
  confidence: Confidence;
  inputVatDeductible?: boolean;
}

export interface TaxQuery {
  productFamilyId: string;
  taxType: string;
  /** ISO date the price is valid on (an input, the engine never reads a clock). */
  asOf: string;
}

export interface ResolvedTax {
  regime: TaxRegime;
  /** Rate leaf (percent) carrying source and confidence. */
  leaf: Traced;
}

export const TAX_REASONS = {
  NO_REGIME: 'tax.regime_missing',
  AMBIGUOUS: 'tax.regime_ambiguous',
  INVALID_REGIME: 'tax.regime_invalid',
  INVALID_AS_OF: 'tax.as_of_invalid',
} as const;

function isWellFormed(r: TaxRegime): boolean {
  return (
    isDecimalString(r.ratePct) &&
    dec(r.ratePct).gte(0) &&
    dec(r.ratePct).lte(100) &&
    isIsoDate(r.validFrom) &&
    (r.validTo === undefined || (isIsoDate(r.validTo) && r.validTo >= r.validFrom))
  );
}

/** The one regime of this family and tax type in force on `asOf`; none, several or a malformed one => missing. */
export function resolveTaxRegime(
  regimes: readonly TaxRegime[] | undefined,
  query: TaxQuery,
  policy: ConfidencePolicy,
): Result<ResolvedTax> {
  const property = `tax:${query.productFamilyId}:${query.taxType}`;
  const gap = (reason: string): Result<ResolvedTax> => missing([{ property, reason }]);
  if (!isIsoDate(query.asOf)) {
    return gap(TAX_REASONS.INVALID_AS_OF);
  }
  const candidates = (regimes ?? []).filter((r) => r.productFamilyId === query.productFamilyId && r.taxType === query.taxType);
  if (candidates.some((r) => !isWellFormed(r))) {
    return gap(TAX_REASONS.INVALID_REGIME);
  }
  const inForce = candidates.filter((r) => r.validFrom <= query.asOf && (r.validTo === undefined || query.asOf <= r.validTo));
  if (inForce.length === 0) {
    return gap(TAX_REASONS.NO_REGIME);
  }
  if (inForce.length > 1) {
    return gap(TAX_REASONS.AMBIGUOUS);
  }
  const regime = inForce[0] as TaxRegime;
  const sv: SourcedValue = {
    value: regime.ratePct,
    unit: '%w/w',
    sourceId: regime.sourceId,
    confidence: regime.confidence,
    validFrom: regime.validFrom,
  };
  const gated = gateConfidence(undefined, property, sv, policy);
  if (!gated.ok) {
    return gated;
  }
  return ok({ regime, leaf: sourcedLeaf(`${query.taxType} rate % (${query.productFamilyId}, ${regime.validFrom})`, sv) }, gated.warnings);
}
