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
import { decOf, inputLeaf, sourcedLeaf, traced, type Traced } from '../trace/trace';

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
  /** The product has no tax setting yet: neither disabled nor a rate. Never read as 0 %. */
  SETTING_MISSING: 'tax.setting_missing',
  SETTING_INVALID: 'tax.setting_invalid',
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

/**
 * The tax choice made on one product (owner decision 2026-11-21): tax switched off, or a rate. `disabled` is not 0 %:
 * a disabled product has no tax line at all, while `{ mode: 'rate', ratePct: '0' }` shows a tax line of zero.
 * Which rates may be chosen is the caller's configuration; the engine only checks the rate against a dated regime.
 */
export type TaxSetting = { mode: 'disabled' } | { mode: 'rate'; ratePct: string };

export type ProductTax = { state: 'disabled' } | ({ state: 'applied' } & ResolvedTax);

/** What a price result says about tax, without the regime internals. */
export type ProductTaxState = { state: 'disabled' } | { state: 'applied'; ratePct: string };

export function productTaxState(tax: ProductTax): ProductTaxState {
  return tax.state === 'disabled' ? { state: 'disabled' } : { state: 'applied', ratePct: tax.regime.ratePct };
}

/**
 * The tax of a product: `disabled` needs no regime; a rate needs the dated regime of exactly that rate in force on
 * `asOf` (none => missing, never the rate on trust); no setting => missing (never 0 %).
 */
export function resolveProductTax(
  setting: TaxSetting | undefined,
  regimes: readonly TaxRegime[] | undefined,
  query: TaxQuery,
  policy: ConfidencePolicy,
): Result<ProductTax> {
  const property = `tax:${query.productFamilyId}:${query.taxType}`;
  if (setting === undefined) {
    return missing([{ property, reason: TAX_REASONS.SETTING_MISSING }]);
  }
  if (setting.mode === 'disabled') {
    return ok({ state: 'disabled' });
  }
  if (setting.mode !== 'rate' || !isDecimalString(setting.ratePct) || dec(setting.ratePct).lt(0) || dec(setting.ratePct).gt(100)) {
    return missing([{ property, reason: TAX_REASONS.SETTING_INVALID }]);
  }
  const chosen = dec(setting.ratePct);
  // A malformed regime stays in so the resolver reports it instead of it being skipped silently.
  const ofRate = (regimes ?? []).filter((r) => !isDecimalString(r.ratePct) || dec(r.ratePct).eq(chosen));
  const resolved = resolveTaxRegime(ofRate, query, policy);
  return resolved.ok ? ok({ state: 'applied', ...resolved.value }, resolved.warnings) : resolved;
}

export interface TaxApplication {
  /** The tax line; null when tax is disabled (there is no line, not a zero one). */
  taxAmount: Traced | null;
  /** Price with the tax added (the same price when tax is disabled). */
  priceWithTax: Traced;
}

/** Adds a product's tax to a price excluding tax. The one implementation: the price waterfall and the tax preview both use it. */
export function applyProductTax(priceExTax: Traced, tax: ProductTax): TaxApplication {
  if (tax.state === 'disabled') {
    return { taxAmount: null, priceWithTax: priceExTax };
  }
  const taxAmount = traced('mul', 'VAT amount = price excl. VAT × VAT/100', decOf(priceExTax).mul(dec(tax.regime.ratePct).div(100)), 'EGP', [priceExTax, tax.leaf]);
  const priceWithTax = traced('add', 'Shelf price = price excl. VAT + VAT', decOf(priceExTax).plus(decOf(taxAmount)), 'EGP', [priceExTax, taxAmount]);
  return { taxAmount, priceWithTax };
}

/** A price typed as an input leaf in EGP (for the tax preview). */
export const priceLeaf = (label: string, value: string): Traced => inputLeaf(label, value, 'EGP');
