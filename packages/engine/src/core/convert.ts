import type { Confidence } from '../contract';
import type { Dec } from './decimal';
import { missing, ok, type Result } from './result';
import { Quantity, UnitMismatchError, type DensityUnit, type MassUnit, type VolumeUnit } from './quantity';

/** Sourced density (SourcedValue-like). It is never defaulted: callers must supply it. */
export interface DensityRef {
  value: string;
  unit: DensityUnit;
  sourceId: string;
  confidence: Confidence;
}

export interface ConvertOptions {
  materialId?: string;
}

function densityGPerMl(
  density: DensityRef | undefined,
  options: ConvertOptions,
): Result<Dec> {
  if (density === undefined) {
    return missing([{ materialId: options.materialId, property: 'density', reason: 'mass<->volume conversion needs an explicit density' }]);
  }
  // g/mL and kg/L are numerically identical.
  const value = new Quantity(density.value, density.unit).value;
  if (value.lte(0)) {
    return missing([{ materialId: options.materialId, property: 'density', reason: 'density must be positive' }]);
  }
  return ok(value);
}

function requireDimension(q: Quantity, dimension: 'mass' | 'volume', operation: string, expected: 'g' | 'mL'): void {
  if (q.dimension !== dimension) {
    throw new UnitMismatchError(operation, q.unit, expected);
  }
}

/** mass -> volume; Result is missing `density` when none is given. */
export function massToVolume(
  mass: Quantity<MassUnit>,
  density: DensityRef | undefined,
  to: VolumeUnit = 'mL',
  options: ConvertOptions = {},
): Result<Quantity<VolumeUnit>> {
  requireDimension(mass, 'mass', 'massToVolume', 'g');
  const d = densityGPerMl(density, options);
  if (!d.ok) {
    return d;
  }
  return ok(new Quantity(mass.to('g').value.div(d.value), 'mL').to(to));
}

/** volume -> mass; Result is missing `density` when none is given. */
export function volumeToMass(
  volume: Quantity<VolumeUnit>,
  density: DensityRef | undefined,
  to: MassUnit = 'g',
  options: ConvertOptions = {},
): Result<Quantity<MassUnit>> {
  requireDimension(volume, 'volume', 'volumeToMass', 'mL');
  const d = densityGPerMl(density, options);
  if (!d.ok) {
    return d;
  }
  return ok(new Quantity(volume.to('mL').value.mul(d.value), 'g').to(to));
}
