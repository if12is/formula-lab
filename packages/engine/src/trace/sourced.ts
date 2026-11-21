import type { Confidence, ConfidenceOverride, SourcedValue } from '../contract';
import { dec } from '../core/decimal';
import type { DensityRef } from '../core/convert';
import { missing, ok, type Result } from '../core/result';
import { dimensionOf } from '../core/quantity';
import { sourcedLeaf, type Traced } from './trace';

/** Best (L1) to worst (L7). */
export const CONFIDENCE_ORDER: readonly Confidence[] = [
  'L1_coa_batch',
  'L2_supplier_tds',
  'L3_standard',
  'L4_regulatory_db',
  'L5_reference',
  'L6_community',
  'L7_estimate',
];

export const DEFAULT_ALLOW_CONFIDENCE_BELOW: Confidence = 'L6_community';

export type { ConfidenceOverride };

export interface ConfidencePolicy {
  allowConfidenceBelow?: Confidence;
  overrides?: readonly ConfidenceOverride[];
}

/** True when `a` is less trustworthy than `than`. */
export function isWorseConfidence(a: Confidence, than: Confidence): boolean {
  return CONFIDENCE_ORDER.indexOf(a) > CONFIDENCE_ORDER.indexOf(than);
}

function hasSignedOverride(
  materialId: string | undefined,
  property: string,
  overrides: readonly ConfidenceOverride[],
): boolean {
  return overrides.some(
    (o) =>
      o.materialId === materialId &&
      o.property === property &&
      o.signedBy.trim() !== '' &&
      o.reason.trim() !== '',
  );
}

/**
 * Applies the confidence policy to one sourced value:
 * L7 => missing unless a signed override exists; worse than allowConfidenceBelow => warning.
 * Warning strings are message keys with `:`-separated details.
 */
export function gateConfidence(
  materialId: string | undefined,
  property: string,
  sv: SourcedValue,
  policy: ConfidencePolicy,
): Result<SourcedValue> {
  const subject = `${materialId ?? '-'}:${property}`;
  if (sv.confidence === 'L7_estimate') {
    return hasSignedOverride(materialId, property, policy.overrides ?? [])
      ? ok(sv, [`confidence.l7_override:${subject}`])
      : missing([{ materialId, property, reason: 'confidence.l7_requires_signed_override' }]);
  }
  const floor = policy.allowConfidenceBelow ?? DEFAULT_ALLOW_CONFIDENCE_BELOW;
  return isWorseConfidence(sv.confidence, floor)
    ? ok(sv, [`confidence.below_policy:${subject}:${sv.confidence}`])
    : ok(sv);
}

export interface ResolvedDensity {
  ref: DensityRef;
  leaf: Traced;
}

/** Density as a gated, positive SourcedValue; absent/invalid => missing under `property`. */
export function resolveDensity(
  materialId: string | undefined,
  property: string,
  sv: SourcedValue | undefined,
  policy: ConfidencePolicy,
  label: string,
): Result<ResolvedDensity> {
  if (sv === undefined) {
    return missing([{ materialId, property, reason: 'density.required_for_conversion' }]);
  }
  if (dimensionOf(sv.unit) !== 'density' || dec(sv.value).lte(0)) {
    return missing([{ materialId, property, reason: 'density.must_be_positive_density_unit' }]);
  }
  const gated = gateConfidence(materialId, property, sv, policy);
  if (!gated.ok) {
    return gated;
  }
  const ref: DensityRef = {
    value: sv.value,
    unit: sv.unit as DensityRef['unit'],
    sourceId: sv.sourceId,
    confidence: sv.confidence,
  };
  return ok({ ref, leaf: sourcedLeaf(label, sv) }, gated.warnings);
}
