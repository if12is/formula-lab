/**
 * Shared input resolution for calculation modules (active, neutralize, soap): turns an optional
 * SourcedScalar / Quantity into a validated Decimal plus a trace leaf, or into `missing` items.
 * Reason keys are message keys (the UI maps them to Arabic); nothing is defaulted to zero.
 */
import type { SourcedValue } from './contract';
import { dec, type Dec } from './core/decimal';
import { isIsoDate } from './core/fx';
import { parseDec, type SourcedScalar } from './core/parse';
import { dimensionOf, type Quantity, type Unit } from './core/quantity';
import { missing, ok, type Result } from './core/result';
import { gateConfidence, type ConfidencePolicy } from './trace/sourced';
import { inputLeaf, sourcedLeaf, type Traced } from './trace/trace';

export const INPUT_REASONS = {
  MISSING: 'input.missing',
  INVALID_NUMBER: 'input.invalid_number',
  UNIT_MISMATCH: 'input.unit_mismatch',
  NOT_DATED: 'input.not_dated',
  OUT_OF_RANGE: 'input.out_of_range',
  INVALID_MASS: 'input.invalid_mass',
  INVALID_VOLUME: 'input.invalid_volume',
} as const;

export interface SourcedNumberSpec {
  materialId?: string;
  property: string;
  label: string;
  /** Unit the trace leaf carries; a declared unit on the input must equal it. */
  unit: Unit;
  /** Inclusive lower bound unless `minExclusive`. Decimal string. */
  min: string;
  minExclusive?: boolean;
  /** Inclusive upper bound. Decimal string. */
  max?: string;
}

export interface ResolvedNumber {
  value: Dec;
  leaf: Traced;
}

function inRange(value: Dec, spec: SourcedNumberSpec): boolean {
  const aboveMin = spec.minExclusive === true ? value.gt(spec.min) : value.gte(spec.min);
  return aboveMin && (spec.max === undefined || value.lte(spec.max));
}

/** A number typed by the user as a requirement (target, superfat, ratio): validated, no source or confidence. */
export function resolveUserNumber(value: string, spec: SourcedNumberSpec): Result<ResolvedNumber> {
  const gap = (reason: string): Result<ResolvedNumber> =>
    missing([{ materialId: spec.materialId, property: spec.property, reason }]);
  const parsed = parseDec(value);
  if (parsed === null) {
    return gap(INPUT_REASONS.INVALID_NUMBER);
  }
  return inRange(parsed, spec) ? ok({ value: parsed, leaf: inputLeaf(spec.label, parsed, spec.unit) }) : gap(INPUT_REASONS.OUT_OF_RANGE);
}

/** Validates a sourced number (present, decimal, unit, dated, in range) and applies the confidence policy. */
export function resolveSourcedNumber(
  sv: SourcedScalar | undefined,
  spec: SourcedNumberSpec,
  policy: ConfidencePolicy,
): Result<ResolvedNumber> {
  const gap = (reason: string): Result<ResolvedNumber> =>
    missing([{ materialId: spec.materialId, property: spec.property, reason }]);
  if (sv === undefined) {
    return gap(INPUT_REASONS.MISSING);
  }
  const value = parseDec(sv.value);
  if (value === null) {
    return gap(INPUT_REASONS.INVALID_NUMBER);
  }
  if (sv.unit !== undefined && sv.unit !== spec.unit) {
    return gap(INPUT_REASONS.UNIT_MISMATCH);
  }
  if (!isIsoDate(sv.validFrom)) {
    return gap(INPUT_REASONS.NOT_DATED);
  }
  if (!inRange(value, spec)) {
    return gap(INPUT_REASONS.OUT_OF_RANGE);
  }
  const normalized: SourcedValue = { ...sv, unit: spec.unit };
  const gated = gateConfidence(spec.materialId, spec.property, normalized, policy);
  if (!gated.ok) {
    return gated;
  }
  return ok({ value, leaf: sourcedLeaf(spec.label, normalized) }, gated.warnings);
}

/** A strictly positive mass as a grams trace leaf. Non-mass units and non-positive values are reported. */
export function resolveMassG(
  materialId: string | undefined,
  property: string,
  label: string,
  mass: Quantity,
): Result<ResolvedNumber> {
  if (dimensionOf(mass.unit) !== 'mass' || mass.value.lte(0)) {
    return missing([{ materialId, property, reason: INPUT_REASONS.INVALID_MASS }]);
  }
  const grams = dec(mass.to('g').value);
  return ok({ value: grams, leaf: inputLeaf(label, grams, 'g') });
}

/** A strictly positive volume as a millilitres trace leaf. Non-volume units and non-positive values are reported. */
export function resolveVolumeMl(
  materialId: string | undefined,
  property: string,
  label: string,
  volume: Quantity,
): Result<ResolvedNumber> {
  if (dimensionOf(volume.unit) !== 'volume' || volume.value.lte(0)) {
    return missing([{ materialId, property, reason: INPUT_REASONS.INVALID_VOLUME }]);
  }
  const ml = dec(volume.to('mL').value);
  return ok({ value: ml, leaf: inputLeaf(label, ml, 'mL') });
}
