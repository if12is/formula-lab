import type { SourcedValue } from '../contract';
import type { Dec } from '../core/decimal';
import { massToVolume, volumeToMass } from '../core/convert';
import type { Result } from '../core/result';
import type { MassUnit, Quantity, VolumeUnit } from '../core/quantity';
import { resolveDensity, type ConfidencePolicy, type ResolvedDensity } from '../trace/sourced';
import { decOf, emptyOutcome, inputLeaf, traced, type Outcome, type Traced } from '../trace/trace';
import { yieldFraction } from './wastage';

export interface BatchInput {
  /** Net batch size: mass (kg/g) or volume (L/mL). */
  size: Quantity;
  wastagePct: string;
  /** Pack size: volume (mL/L) or mass (g/kg). Omit to skip the pack split. */
  packSize?: Quantity;
  /** Measured product density. Without it the plan is kg-only and reports `productDensity` missing. */
  productDensity?: SourcedValue;
  policy?: ConfidencePolicy;
}

const DENSITY_PROPERTY = 'productDensity';

function failure(property: string, reason: string): Outcome {
  return { ...emptyOutcome(), missing: [{ property, reason }] };
}

function sizeProblem(q: Quantity, property: string): Outcome | undefined {
  if (q.dimension !== 'mass' && q.dimension !== 'volume') {
    return failure(property, 'batch.unit_must_be_mass_or_volume');
  }
  return q.value.lte(0) ? failure(property, 'batch.size_not_positive') : undefined;
}

function valueOrUndefined<T>(r: Result<T>): T | undefined {
  return r.ok ? r.value : undefined;
}

interface Net {
  mass?: Quantity<MassUnit>;
  volume?: Quantity<VolumeUnit>;
}

/** Net mass and volume; the side that needs the density is absent when density is missing. */
function netAmounts(size: Quantity, density: Result<ResolvedDensity>): Net {
  const ref = valueOrUndefined(density)?.ref;
  if (size.dimension === 'mass') {
    const mass = size as Quantity<MassUnit>;
    return { mass, volume: ref && valueOrUndefined(massToVolume(mass, ref, 'L')) };
  }
  const volume = size as Quantity<VolumeUnit>;
  return { volume, mass: ref && valueOrUndefined(volumeToMass(volume, ref, 'kg')) };
}

function grossOf(net: Traced, yieldF: Dec, label: string): Traced {
  const factor = inputLeaf('Yield fraction = 1 − wastage/100', yieldF, '%w/w');
  return traced('div', label, decOf(net).div(yieldF), net.unit, [net, factor]);
}

/**
 * Batch plan: gross mass for wastage, L<->kg by the MEASURED density, and whole packs
 * N = floor(net / pack) with the remainder reported separately (never rounded up).
 * Result keys: batch.netMass, batch.grossMass, batch.netVolume, batch.grossVolume,
 * batch.packMass, batch.fullPacks, batch.remainder. Net size (not gross) is what is packed.
 */
export function planBatch(input: BatchInput): Outcome {
  const bad = sizeProblem(input.size, 'batchSize') ?? (input.packSize && sizeProblem(input.packSize, 'packSize'));
  if (bad) {
    return bad;
  }
  const density = resolveDensity(undefined, DENSITY_PROPERTY, input.productDensity, input.policy ?? {}, 'Product density (measured)');
  const yieldF = yieldFraction(input.wastagePct);
  const out = emptyOutcome();
  out.warnings.push(...density.warnings);
  if (!density.ok) {
    out.missing.push(...density.missing);
  }
  if (!yieldF.ok) {
    out.missing.push(...yieldF.missing);
  }

  const net = netAmounts(input.size, density);
  const densityLeaf = valueOrUndefined(density)?.leaf;
  const netMass = net.mass && netNode('Net mass', net.mass.to('kg'), input.size, densityLeaf);
  const netVolume = net.volume && netNode('Net volume', net.volume.to('L'), input.size, densityLeaf);
  const results = out.results;
  if (netMass) {
    results['batch.netMass'] = netMass;
  }
  if (netVolume) {
    results['batch.netVolume'] = netVolume;
  }
  if (yieldF.ok) {
    if (netMass) {
      results['batch.grossMass'] = grossOf(netMass, yieldF.value, 'Gross mass = M / (1 − w/100)');
    }
    if (netVolume) {
      results['batch.grossVolume'] = grossOf(netVolume, yieldF.value, 'Gross volume = V / (1 − w/100)');
    }
  }
  if (input.packSize) {
    addPacks(out, input.packSize, net, density);
  }
  return out;
}

/** The side the user gave is an input leaf; the converted side is derived from it and the density. */
function netNode(label: string, value: Quantity, given: Quantity, densityLeaf: Traced | undefined): Traced {
  if (value.dimension === given.dimension || !densityLeaf) {
    return inputLeaf(label, value.value, value.unit);
  }
  const source = inputLeaf('Batch size (given)', given.value, given.unit);
  return traced('density', `${label} via measured density`, value.value, value.unit, [source, densityLeaf]);
}

function addPacks(out: Outcome, pack: Quantity, net: Net, density: Result<ResolvedDensity>): void {
  const byMass = pack.dimension === 'mass';
  const packBase = byMass ? pack.to('g') : pack.to('mL');
  const packLeaf = inputLeaf('Pack size', packBase.value, packBase.unit);
  const ref = valueOrUndefined(density);
  if (byMass) {
    out.results['batch.packMass'] = traced('convert', 'Pack mass', pack.to('kg').value, 'kg', [packLeaf]);
  } else if (ref) {
    const m = valueOrUndefined(volumeToMass(pack as Quantity<VolumeUnit>, ref.ref, 'kg'));
    if (m) {
      out.results['batch.packMass'] = traced('mul', 'Pack mass = V_pack × ρ', m.value, 'kg', [packLeaf, ref.leaf]);
    }
  }
  const netBase = byMass ? net.mass?.to('g') : net.volume?.to('mL');
  if (!netBase) {
    return;
  }
  const netLeaf = inputLeaf('Net batch in pack unit', netBase.value, netBase.unit);
  const whole = netBase.value.div(packBase.value).floor();
  const fullPacks = traced('floor', 'Full packs N = floor(net / pack)', whole, 'pc', [netLeaf, packLeaf]);
  out.results['batch.fullPacks'] = fullPacks;
  out.results['batch.remainder'] = traced(
    'sub',
    'Remainder = net − N × pack',
    netBase.value.minus(whole.mul(packBase.value)),
    packBase.unit,
    [netLeaf, packLeaf, fullPacks],
  );
}
