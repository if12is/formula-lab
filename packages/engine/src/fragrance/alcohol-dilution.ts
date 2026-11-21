/**
 * Ethanol dilution by MASS balance with OIML R 22 (L-03). Pure, decimal.js only.
 *   w_S = %w/w of the source, w_T = %w/w of the target (both from OIML R 22 via core/alcohol)
 *   m_source = M x w_T / w_S          (ethanol is conserved: m_source x w_S = M x w_T)
 *   m_water  = M - m_source
 *   M        = m_target                              (target given as a mass), or
 *   M        = V_target x rho_T(w_T)                 (target given as a volume; rho from the table)
 * Volumes are never subtracted: mixing contracts, and the table (rho as a function of strength)
 * already contains the contraction, so volume enters only through densities.
 * The source strength comes from a COA (explicit %v/v or %w/w basis); nothing is defaulted.
 */
import { densityFromWW, vvFromWW, wwFromVV, type OimlTable } from '../core/alcohol';
import type { Dec } from '../core/decimal';
import type { SourcedScalar } from '../core/parse';
import type { Quantity } from '../core/quantity';
import { all, flatMap, map, missing, ok, type Result } from '../core/result';
import {
  resolveMassG,
  resolveSourcedNumber,
  resolveUserNumber,
  resolveVolumeMl,
  type ResolvedNumber,
  type SourcedNumberSpec,
} from '../sourced-input';
import type { ConfidencePolicy } from '../trace/sourced';
import { decOf, traced, type Traced } from '../trace/trace';

export const ALCOHOL_REASONS = {
  BASIS_REQUIRED: 'alcohol.basis_required',
  TARGET_EXCEEDS_SOURCE: 'alcohol.target_exceeds_source',
} as const;

/** One ethanol strength expressed on both bases; the derived one is a traced OIML R 22 lookup. */
export interface EthanolStrength {
  pctWW: Traced;
  pctVV: Traced;
}

type Basis = '%v/v' | '%w/w';

const STRENGTH_RANGE = { min: '0', minExclusive: true, max: '100' } as const;

/** Adds the other basis (OIML R 22 lookup) to a strength resolved on `basis`. */
function withBothBases(resolved: Result<ResolvedNumber>, basis: Basis, table?: OimlTable): Result<EthanolStrength> {
  return flatMap(resolved, ({ value, leaf }) => {
    const toWW = basis === '%v/v';
    const other = toWW ? wwFromVV(value, table) : vvFromWW(value, table);
    return map(other, (o): EthanolStrength => {
      const derived = traced('oiml', toWW ? 'Ethanol %w/w (OIML R 22)' : 'Ethanol %v/v (OIML R 22)', o, toWW ? '%w/w' : '%v/v', [leaf]);
      return toWW ? { pctVV: leaf, pctWW: derived } : { pctWW: leaf, pctVV: derived };
    });
  });
}

export interface StrengthSpec {
  materialId?: string;
  property: string;
  label: string;
}

/**
 * Ethanol strength from a COA/TDS value. The basis is the value's own unit and must be stated:
 * '%v/v' or '%w/w'. A value without a unit is `alcohol.basis_required`, never guessed.
 */
export function resolveEthanolSource(
  sv: SourcedScalar | undefined,
  spec: StrengthSpec,
  policy: ConfidencePolicy,
  table?: OimlTable,
): Result<EthanolStrength> {
  if (sv !== undefined && sv.unit === undefined) {
    return missing([{ materialId: spec.materialId, property: spec.property, reason: ALCOHOL_REASONS.BASIS_REQUIRED }]);
  }
  const basis: Basis = sv?.unit === '%v/v' ? '%v/v' : '%w/w';
  return withBothBases(resolveSourcedNumber(sv, { ...spec, ...STRENGTH_RANGE, unit: basis }, policy), basis, table);
}

/** A target strength typed by the user, in %v/v (the trade basis for fine fragrance and disinfectants). */
export function resolveTargetPctVV(value: string, spec: StrengthSpec, table?: OimlTable): Result<EthanolStrength> {
  const numberSpec: SourcedNumberSpec = { ...spec, ...STRENGTH_RANGE, unit: '%v/v' };
  return withBothBases(resolveUserNumber(value, numberSpec), '%v/v', table);
}

export interface EthanolBalance {
  sourceMass: Traced;
  waterMass: Traced;
}

/**
 * Mass balance for a batch of `totalMass` grams: m_source = M x w_T / w_S, water = M - m_source.
 * A target stronger than the source cannot be reached by dilution => missing.
 */
export function ethanolMassBalance(
  source: EthanolStrength,
  target: EthanolStrength,
  totalMass: Traced,
  sourceId?: string,
): Result<EthanolBalance> {
  const wS = decOf(source.pctWW);
  const wT = decOf(target.pctWW);
  if (wT.gt(wS)) {
    return missing([{ materialId: sourceId, property: 'targetPctVV', reason: ALCOHOL_REASONS.TARGET_EXCEEDS_SOURCE }]);
  }
  const m = decOf(totalMass);
  const sourceMass = traced('div', 'm_source = M x w_T / w_S', m.mul(wT).div(wS), 'g', [totalMass, target.pctWW, source.pctWW]);
  const waterMass = traced('sub', 'm_water = M - m_source', m.minus(decOf(sourceMass)), 'g', [totalMass, sourceMass]);
  return ok({ sourceMass, waterMass });
}

/** The batch to make: a total mass or a total volume of the DILUTED product. */
export type BatchTarget = { mass: Quantity } | { volume: Quantity };

export interface AlcoholDilutionInput {
  /** COA strength of the ethanol to dilute; its unit ('%v/v' or '%w/w') is the stated basis. */
  source: SourcedScalar | undefined;
  sourceMaterialId?: string;
  /** Required strength of the diluted product, %v/v (typed requirement). */
  targetPctVV: string;
  batch: BatchTarget;
  policy: ConfidencePolicy;
  /** Test seam: defaults to the shipped OIML R 22 table. */
  table?: OimlTable;
}

export interface AlcoholDilution {
  sourcePctWW: Traced;
  targetPctWW: Traced;
  /** OIML density of the diluted product at 20 C, g/mL. */
  targetDensity: Traced;
  sourceMass: Traced;
  waterMass: Traced;
  totalMass: Traced;
  totalVolume: Traced;
  /** Volumes of the ingredients before mixing (OIML densities of the source and of water). */
  sourceVolume: Traced;
  waterVolume: Traced;
  /** Volume lost on mixing: V_source + V_water - V_mix (mL). Positive for ethanol-water. */
  contractionVolume: Traced;
  /** Contraction as % of the ingredients' volume before mixing. */
  contractionPct: Traced;
}

const kgM3ToGPerMl = (rho: Dec): Dec => rho.div(1000);

/** Density of an ethanol-water mixture of strength `pctWW`, as a traced g/mL value. */
function densityGPerMl(label: string, pctWW: Traced, table?: OimlTable): Result<Traced> {
  return map(densityFromWW(decOf(pctWW), table), (rho) => traced('oiml', label, kgM3ToGPerMl(rho), 'g/mL', [pctWW]));
}

/** Dilutes ethanol of the COA strength to a target %v/v for a batch given as mass or volume. */
export function diluteEthanol(input: AlcoholDilutionInput): Result<AlcoholDilution> {
  const batch = 'mass' in input.batch
    ? resolveMassG(undefined, 'batchMass', 'Batch mass M (g)', input.batch.mass)
    : resolveVolumeMl(undefined, 'batchVolume', 'Batch volume V (mL)', input.batch.volume);
  const ends = all([
    resolveEthanolSource(input.source, { materialId: input.sourceMaterialId, property: 'sourceStrength', label: 'Source ethanol strength' }, input.policy, input.table),
    resolveTargetPctVV(input.targetPctVV, { property: 'targetPctVV', label: 'Target ethanol strength (%v/v)' }, input.table),
    batch,
  ] as const);
  return flatMap(ends, ([source, target, given]) => {
    const densities = all([
      densityGPerMl('Density of target mix (g/mL)', target.pctWW, input.table),
      densityGPerMl('Density of water (g/mL)', traced('input', 'Water %w/w', '0', '%w/w'), input.table),
      densityGPerMl('Density of source (g/mL)', source.pctWW, input.table),
    ] as const);
    return flatMap(densities, ([targetDensity, waterDensity, sourceDensity]) => {
      const rhoT = decOf(targetDensity);
      const byMass = 'mass' in input.batch;
      const totalMass = byMass
        ? given.leaf
        : traced('mul', 'M = V x rho_T (g)', given.value.mul(rhoT), 'g', [given.leaf, targetDensity]);
      const totalVolume = byMass
        ? traced('div', 'V = M / rho_T (mL)', given.value.div(rhoT), 'mL', [given.leaf, targetDensity])
        : given.leaf;
      const volumeOf = (label: string, mass: Traced, density: Traced): Traced =>
        traced('div', label, decOf(mass).div(decOf(density)), 'mL', [mass, density]);
      return map(
        ethanolMassBalance(source, target, totalMass, input.sourceMaterialId),
        ({ sourceMass, waterMass }): AlcoholDilution => {
          const sourceVolume = volumeOf('Source volume before mixing (mL)', sourceMass, sourceDensity);
          const waterVolume = volumeOf('Water volume before mixing (mL)', waterMass, waterDensity);
          const parts = decOf(sourceVolume).plus(decOf(waterVolume));
          const lost = parts.minus(decOf(totalVolume));
          const contractionVolume = traced('sub', 'Contraction = V_source + V_water - V_mix (mL)', lost, 'mL', [sourceVolume, waterVolume, totalVolume]);
          return {
            sourcePctWW: source.pctWW,
            targetPctWW: target.pctWW,
            targetDensity,
            sourceMass,
            waterMass,
            totalMass,
            totalVolume,
            sourceVolume,
            waterVolume,
            contractionVolume,
            contractionPct: traced('div', 'Contraction as % of the volume before mixing', lost.div(parts).mul(100), '%v/v', [contractionVolume, sourceVolume, waterVolume]),
          };
        },
      );
    });
  });
}
