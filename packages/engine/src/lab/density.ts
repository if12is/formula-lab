/**
 * Density for the litre/kilo conversion (O-04): the density measured in the lab on a version of the formula wins over an
 * estimated one (a material's registered density, a reference figure). The chosen value keeps its source and confidence in
 * the trace, and `basis` says which one was used. A measured density that is present but unusable is NOT silently replaced
 * by the estimate: it is reported missing. Neither side present => missing. Pure.
 */
import type { SourcedValue } from '../contract';
import { missing, ok, type Result } from '../core/result';
import { resolveDensity, type ConfidencePolicy, type ResolvedDensity } from '../trace/sourced';

export type DensityBasis = 'measured' | 'estimated';

export interface ChosenDensity extends ResolvedDensity {
  basis: DensityBasis;
}

export const DENSITY_REASONS = {
  NONE: 'density.required_for_conversion',
  ESTIMATE_USED: 'density.estimated_used',
} as const;

export function preferMeasuredDensity(
  measured: SourcedValue | undefined,
  estimated: SourcedValue | undefined,
  policy: ConfidencePolicy = {},
  materialId?: string,
  property = 'productDensity',
): Result<ChosenDensity> {
  if (measured !== undefined) {
    const r = resolveDensity(materialId, property, measured, policy, 'Measured product density (lab)');
    return r.ok ? ok({ ...r.value, basis: 'measured' }, r.warnings) : r;
  }
  if (estimated === undefined) {
    return missing([{ materialId, property, reason: DENSITY_REASONS.NONE }]);
  }
  const r = resolveDensity(materialId, property, estimated, policy, 'Estimated density');
  return r.ok ? ok({ ...r.value, basis: 'estimated' }, [...r.warnings, DENSITY_REASONS.ESTIMATE_USED]) : r;
}
