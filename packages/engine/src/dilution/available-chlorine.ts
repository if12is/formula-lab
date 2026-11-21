/**
 * Available chlorine (K-03). Pure, decimal.js only.
 *   - The COA quotes available chlorine in % w/w on an EXPLICIT basis: as NaOCl or as Cl2.
 *   - Bases convert 1 mol NaOCl = 1 mol available Cl2: %Cl2 = %NaOCl x M_Cl2 / M_NaOCl
 *     (molar masses live in core/molar-mass).
 *   - Dilution to a target is the active-matter dilution (active/rawMassForTarget): m_raw = M x A_t / a_raw.
 *   - Storage loss comes ONLY from the user's lab measurements {days, lossPct}, interpolated linearly
 *     between measured points and never extrapolated. lossPct is the relative loss of the available
 *     chlorine present at filling (% of the initial amount). No decay rate is built in.
 *   With storage data the target is the level required at the END of storage, so the batch is made at
 *   fill target = target / (1 - loss/100).
 */
import { rawMassForTarget, type Dilution } from '../active';
import type { MissingData } from '../contract';
import { dec, type Dec } from '../core/decimal';
import { duplicateXs, interpolateAt, sortKnots, type Knot } from '../core/interpolate';
import { cl2PerNaocl } from '../core/molar-mass';
import { parseDec, type SourcedScalar } from '../core/parse';
import type { Quantity } from '../core/quantity';
import { all, combine, flatMap, map, missing, ok, type Result } from '../core/result';
import { INPUT_REASONS, resolveMassG, resolveSourcedNumber, resolveUserNumber, type SourcedNumberSpec } from '../sourced-input';
import type { ConfidencePolicy } from '../trace/sourced';
import { decOf, traced, type Traced } from '../trace/trace';

export type ChlorineBasis = 'NaOCl' | 'Cl2';

export const CHLORINE_REASONS = {
  BASIS_INVALID: 'chlorine.basis_invalid',
  STORAGE_POINTS_REQUIRED: 'chlorine.storage_points_required',
  STORAGE_DAYS_DUPLICATE: 'chlorine.storage_days_duplicate',
  STORAGE_OUT_OF_MEASURED_RANGE: 'chlorine.storage_days_out_of_measured_range',
  STORAGE_LOSS_TOTAL: 'chlorine.storage_loss_total',
} as const;

const isBasis = (x: unknown): x is ChlorineBasis => x === 'NaOCl' || x === 'Cl2';

/** Converts a % w/w figure between the two bases (1 mol NaOCl = 1 mol available Cl2). */
export function convertChlorineBasis(pct: Dec, from: ChlorineBasis, to: ChlorineBasis): Dec {
  if (from === to) {
    return pct;
  }
  return from === 'NaOCl' ? pct.mul(cl2PerNaocl()) : pct.div(cl2PerNaocl());
}

export interface StorageLossPoint {
  /** Storage time of the measurement, days (decimal string, >= 0). */
  days: string;
  /** Measured relative loss of available chlorine after `days`, % of the initial amount; sourced from the lab. */
  lossPct: SourcedScalar;
}

export interface StorageLossInput {
  lossPoints: readonly StorageLossPoint[];
  /** Storage time to cover, days. Must lie inside the measured days. */
  days: string;
}

interface LossKnot extends Knot {
  leaf: Traced;
}

const lossGap = (property: string, reason: string): MissingData => ({ property, reason });

function resolveLossPoint(point: StorageLossPoint, index: number, policy: ConfidencePolicy): Result<LossKnot> {
  const at = `storageLoss[${index}]`;
  const days = parseDec(point.days);
  const loss = resolveSourcedNumber(
    point.lossPct,
    { property: `${at}.lossPct`, label: `Measured storage loss at ${String(point.days)} d (%)`, unit: '%w/w', min: '0', max: '100' },
    policy,
  );
  if (days === null || days.lt(0)) {
    return missing([lossGap(`${at}.days`, INPUT_REASONS.INVALID_NUMBER), ...(loss.ok ? [] : loss.missing)]);
  }
  return map(loss, (l): LossKnot => ({ x: days, y: l.value, leaf: l.leaf }));
}

/** Loss % after `input.days`, interpolated between the user's measured points; outside them => missing. */
export function storageLossAt(input: StorageLossInput, policy: ConfidencePolicy): Result<Traced> {
  if (input.lossPoints.length === 0) {
    return missing([lossGap('storageLoss', CHLORINE_REASONS.STORAGE_POINTS_REQUIRED)]);
  }
  const days = parseDec(input.days);
  const points = combine(input.lossPoints.map((p, i) => resolveLossPoint(p, i, policy)));
  if (days === null || days.lt(0)) {
    return missing([lossGap('storageDays', INPUT_REASONS.INVALID_NUMBER), ...(points.ok ? [] : points.missing)], points.warnings);
  }
  return flatMap(points, (knots) => {
    const sorted = sortKnots(knots);
    const duplicates = duplicateXs(sorted);
    if (duplicates.length > 0) {
      return missing(duplicates.map((x) => lossGap('storageLoss', `${CHLORINE_REASONS.STORAGE_DAYS_DUPLICATE}:${x.toFixed()}`)));
    }
    const at = interpolateAt(sorted, days);
    if (at === null) {
      const range = `${sorted[0]!.x.toFixed()}..${sorted[sorted.length - 1]!.x.toFixed()}`;
      return missing([lossGap('storageDays', `${CHLORINE_REASONS.STORAGE_OUT_OF_MEASURED_RANGE}:${days.toFixed()} not in ${range}`)]);
    }
    if (at.y.gte(100)) {
      return missing([lossGap('storageLoss', CHLORINE_REASONS.STORAGE_LOSS_TOTAL)]);
    }
    const inputs = at.lo === at.hi ? [sorted[at.lo]!.leaf] : [sorted[at.lo]!.leaf, sorted[at.hi]!.leaf];
    return ok(traced('interp', `Storage loss after ${days.toFixed()} d (%), measured points only`, at.y, '%w/w', inputs));
  });
}

export interface ChlorineDilutionInput {
  materialId?: string;
  /** Available chlorine of the COA grade, % w/w, on `coaBasis`. */
  coa: SourcedScalar | undefined;
  coaBasis: ChlorineBasis;
  /** Required available chlorine (typed requirement), % w/w on `targetBasis`; at the end of storage when `storage` is given. */
  targetPct: string;
  targetBasis: ChlorineBasis;
  batchMass: Quantity;
  storage?: StorageLossInput;
  policy: ConfidencePolicy;
}

export interface ChlorineDilution extends Dilution {
  /** Target converted to the COA basis (equals the typed target when the bases match). */
  targetOnCoaBasisPct: Traced;
  /** Measured storage loss over the storage time; present only when storage data was given. */
  lossPct?: Traced;
  /** What the batch is diluted to at filling, on the COA basis: target / (1 - loss/100), or the target. */
  fillTargetPct: Traced;
}

const coaSpec = (materialId: string | undefined): SourcedNumberSpec => ({
  materialId,
  property: 'rawActive',
  label: `${materialId ?? 'raw'} available chlorine (%)`,
  unit: '%w/w',
  min: '0',
  minExclusive: true,
  max: '100',
});

function checkBases(input: ChlorineDilutionInput): Result<true> {
  const gaps = (['coaBasis', 'targetBasis'] as const)
    .filter((k) => !isBasis(input[k]))
    .map((k) => ({ materialId: input.materialId, property: k, reason: CHLORINE_REASONS.BASIS_INVALID }));
  return gaps.length > 0 ? missing(gaps) : ok(true);
}

/** Dilution of a COA grade to a target available chlorine, optionally compensating the measured storage loss. */
export function availableChlorineDilution(input: ChlorineDilutionInput): Result<ChlorineDilution> {
  const loss: Result<Traced | undefined> = input.storage === undefined ? ok(undefined) : storageLossAt(input.storage, input.policy);
  // The COA is validated here too so that EVERY gap is listed together; rawMassForTarget re-checks it.
  const inputs = all([
    checkBases(input),
    resolveSourcedNumber(input.coa, coaSpec(input.materialId), input.policy),
    resolveMassG(undefined, 'batchMass', 'Batch mass M (g)', input.batchMass),
    resolveUserNumber(input.targetPct, { property: 'targetPct', label: 'Target available chlorine (%)', unit: '%w/w', min: '0', minExclusive: true, max: '100' }),
    loss,
  ] as const);
  return flatMap(inputs, ([, , , target, lossPct]) => {
    const onCoaBasis = input.targetBasis === input.coaBasis
      ? target.leaf
      : traced('mul', `Target on ${input.coaBasis} basis (%) = target x M ratio`, convertChlorineBasis(target.value, input.targetBasis, input.coaBasis), '%w/w', [target.leaf]);
    const fill = lossPct === undefined
      ? onCoaBasis
      : traced('div', 'Fill target (%) = target / (1 - loss/100)', decOf(onCoaBasis).mul(100).div(dec('100').minus(decOf(lossPct))), '%w/w', [onCoaBasis, lossPct]);
    const dilution = rawMassForTarget({
      batchMass: input.batchMass,
      targetActivePct: fill.value,
      rawActive: input.coa!,
      rawMaterialId: input.materialId,
      policy: input.policy,
    });
    return map(dilution, (d): ChlorineDilution => ({
      ...d,
      targetOnCoaBasisPct: onCoaBasis,
      ...(lossPct !== undefined && { lossPct }),
      fillTargetPct: fill,
    }));
  });
}
