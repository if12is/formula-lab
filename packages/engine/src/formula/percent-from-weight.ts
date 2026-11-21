import type { SourcedValue } from '../contract';
import { dec } from '../core/decimal';
import { volumeToMass } from '../core/convert';
import { all, missing, ok, type Result } from '../core/result';
import type { Quantity } from '../core/quantity';
import { resolveDensity, type ConfidencePolicy } from '../trace/sourced';
import { inputLeaf, traced, type Traced } from '../trace/trace';

export interface PercentFromWeightInput {
  materialId?: string;
  /** Weight (g/kg/mg) or volume (mL/L) of the material. */
  amount: Quantity;
  /** Batch size as mass or volume. */
  batchSize: Quantity;
  /** Needed only when `amount` is a volume. */
  materialDensity?: SourcedValue;
  /** Measured product density; needed only when `batchSize` is a volume. */
  productDensity?: SourcedValue;
  policy?: ConfidencePolicy;
}

/** Mass of a quantity in grams, converting volumes with the given (optional) density. */
function massInGrams(
  q: Quantity,
  label: string,
  materialId: string | undefined,
  densityProperty: string,
  density: SourcedValue | undefined,
  policy: ConfidencePolicy,
): Result<Traced> {
  if (q.dimension === 'mass') {
    return ok(inputLeaf(label, q.to('g').value, 'g'));
  }
  if (q.dimension !== 'volume') {
    return missing([{ materialId, property: label, reason: 'percent_from_weight.unit_must_be_mass_or_volume' }]);
  }
  const d = resolveDensity(materialId, densityProperty, density, policy, `${label} density`);
  if (!d.ok) {
    return d;
  }
  const grams = volumeToMass(q as Quantity<'mL'>, d.value.ref, 'g', { materialId });
  if (!grams.ok) {
    return grams;
  }
  const volume = inputLeaf(`${label} (volume)`, q.to('mL').value, 'mL');
  return ok(
    traced('mul', `${label} mass = V × ρ`, grams.value.value, 'g', [volume, d.value.leaf]),
    d.warnings,
  );
}

/**
 * %w/w of a material = 100 × material mass / batch mass, with explicit units.
 * Volumes need an explicit sourced density (material density for the material, measured
 * product density for the batch); a missing density is reported, never defaulted.
 */
export function percentFromWeight(input: PercentFromWeightInput): Result<Traced> {
  const { materialId, amount, batchSize } = input;
  const policy = input.policy ?? {};
  if (amount.value.isNegative()) {
    return missing([{ materialId, property: 'amount', reason: 'percent_from_weight.amount_negative' }]);
  }
  if (batchSize.value.lte(0)) {
    return missing([{ property: 'batchSize', reason: 'percent_from_weight.batch_not_positive' }]);
  }
  const material = massInGrams(amount, 'Material amount', materialId, 'density', input.materialDensity, policy);
  const batch = massInGrams(batchSize, 'Batch size', undefined, 'productDensity', input.productDensity, policy);
  const both = all([material, batch] as const);
  if (!both.ok) {
    return both;
  }
  const [m, b] = both.value;
  const percent = dec(m.value).mul(100).div(b.value);
  const warnings = percent.gt(100) ? [...both.warnings, 'percent_from_weight.exceeds_100'] : both.warnings;
  return ok(traced('percent', 'Percent w/w = 100 × m_material / m_batch', percent, '%w/w', [m, b]), warnings);
}
