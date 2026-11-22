/**
 * Water-based room spray (L-05). Pure, decimal.js only.
 *   solubilizer mass = ratio x fragrance mass, water = 100 - oil - solubilizer - other additives (% w/w).
 * The solubilizer:fragrance ratio is NEVER built in: it comes from the solubilizer's TDS or from a documented trial
 * (`ratioBasis`), as a sourced value. Whether the result is clear is a MEASURED observation of a trial at that ratio:
 * only `clear` is PASS, `hazy` / `separated` is FAIL, no observation is UNKNOWN (never PASS).
 * The IFRA hook is the shared checkIfraLimit: no sourced certificate limit, no PASS.
 */
import type { MissingData } from '../contract';
import type { CheckStatus } from '../core/check-status';
import { dec } from '../core/decimal';
import { isIsoDate } from '../core/fx';
import type { SourcedScalar } from '../core/parse';
import type { Confidence } from '../contract';
import { all, flatMap, missing, ok, type Result } from '../core/result';
import { qty } from '../core/quantity';
import { resolveMassG, resolveSourcedNumber, resolveUserNumber, type ResolvedNumber } from '../sourced-input';
import { gateConfidence, type ConfidencePolicy } from '../trace/sourced';
import { decOf, traced, type Traced } from '../trace/trace';
import { FRAGRANCE_REASONS, FRAGRANCE_WARNINGS, checkIfraLimit, type IfraCheck } from './fine-fragrance';

export const ROOM_SPRAY_REASONS = {
  RATIO_BASIS_REQUIRED: 'fragrance.ratio_basis_required',
  CLARITY_INVALID: 'fragrance.clarity_invalid',
} as const;

export const ROOM_SPRAY_WARNINGS = {
  CLARITY_UNKNOWN: 'fragrance.clarity_unknown',
} as const;

export const RATIO_BASES = ['tds', 'trial'] as const;
export type RatioBasis = (typeof RATIO_BASES)[number];

export const CLARITY_OBSERVATIONS = ['clear', 'hazy', 'separated'] as const;
export type ClarityObservation = (typeof CLARITY_OBSERVATIONS)[number];

/** The measured clarity of a trial made at the stated ratio and oil %. */
export interface ClarityMeasurement {
  observation: ClarityObservation;
  sourceId: string;
  confidence: Confidence;
  /** ISO date of the measurement. */
  validFrom: string;
}

export interface RoomSprayInput {
  fragranceId?: string;
  /** Fragrance oil, % w/w of the product (a typed requirement). */
  fragranceOilPct: string;
  /** Solubilizer mass per 1 mass of fragrance oil (2 means 2:1). Sourced: TDS or a documented trial. */
  solubilizerRatio: SourcedScalar | undefined;
  ratioBasis: RatioBasis | undefined;
  /** Other additives (preservative, ...), % w/w. Absent means none. */
  otherPct?: string;
  clarity?: ClarityMeasurement;
  batchMass: ReturnType<typeof qty>;
  ifraLimitPct?: SourcedScalar;
  policy: ConfidencePolicy;
}

export interface RoomSpray {
  fragranceOilMass: Traced;
  solubilizerMass: Traced;
  waterMass: Traced;
  totalMass: Traced;
  solubilizerPct: Traced;
  waterPct: Traced;
  clarity: { status: CheckStatus; missing: MissingData[] };
  ifra: IfraCheck;
}

function resolveClarity(c: ClarityMeasurement | undefined, fragranceId: string | undefined, policy: ConfidencePolicy): Result<CheckStatus> {
  if (c === undefined) {
    return ok('UNKNOWN');
  }
  const valid = (CLARITY_OBSERVATIONS as readonly string[]).includes(c.observation) && c.sourceId.trim() !== '' && isIsoDate(c.validFrom);
  if (!valid) {
    return missing([{ materialId: fragranceId, property: 'clarity', reason: ROOM_SPRAY_REASONS.CLARITY_INVALID }]);
  }
  const gated = gateConfidence(fragranceId, 'clarity', { value: '0', unit: '%', sourceId: c.sourceId, confidence: c.confidence, validFrom: c.validFrom }, policy);
  if (!gated.ok) {
    return gated;
  }
  return ok(c.observation === 'clear' ? 'PASS' : 'FAIL', gated.warnings);
}

export function roomSpray(input: RoomSprayInput): Result<RoomSpray> {
  const basis: Result<RatioBasis> =
    input.ratioBasis !== undefined && (RATIO_BASES as readonly string[]).includes(input.ratioBasis)
      ? ok(input.ratioBasis)
      : missing([{ materialId: input.fragranceId, property: 'ratioBasis', reason: ROOM_SPRAY_REASONS.RATIO_BASIS_REQUIRED }]);
  const pct = (property: string, label: string, value: string, positive: boolean): Result<ResolvedNumber> =>
    resolveUserNumber(value, { property, label, unit: '%w/w', min: '0', minExclusive: positive, max: '100' });
  const inputs = all([
    pct('fragranceOilPct', 'Fragrance oil (%)', input.fragranceOilPct, true),
    pct('otherPct', 'Other additives (%)', input.otherPct ?? '0', false),
    resolveSourcedNumber(
      input.solubilizerRatio,
      { materialId: input.fragranceId, property: 'solubilizerRatio', label: 'Solubilizer : fragrance ratio (mass per mass)', unit: 'pc', min: '0', minExclusive: true },
      input.policy,
    ),
    basis,
    resolveMassG(undefined, 'batchMass', 'Batch mass M (g)', input.batchMass),
    resolveClarity(input.clarity, input.fragranceId, input.policy),
  ] as const);
  return flatMap(inputs, ([oil, other, ratio, , batch, clarityStatus]): Result<RoomSpray> => {
    const solubilizerPctValue = oil.value.mul(ratio.value);
    const water = dec('100').minus(oil.value).minus(solubilizerPctValue).minus(other.value);
    if (water.lte(0)) {
      return missing([{ materialId: input.fragranceId, property: 'fragranceOilPct', reason: FRAGRANCE_REASONS.PERCENTS_EXCEED_100 }]);
    }
    const M = decOf(batch.leaf);
    const solubilizerPct = traced('mul', 'Solubilizer % = oil % x ratio', solubilizerPctValue, '%w/w', [oil.leaf, ratio.leaf]);
    const waterPct = traced('sub', 'Water % = 100 - oil - solubilizer - other', water, '%w/w', [oil.leaf, solubilizerPct, other.leaf]);
    const part = (label: string, p: Traced): Traced => traced('mul', label, M.mul(decOf(p)).div(100), 'g', [batch.leaf, p]);
    const ifra = checkIfraLimit(oil.leaf, input.ifraLimitPct, input.policy, input.fragranceId);
    const clarity = {
      status: clarityStatus,
      missing: clarityStatus === 'UNKNOWN' ? [{ materialId: input.fragranceId, property: 'clarity', reason: 'input.missing' }] : [],
    };
    return ok<RoomSpray>(
      {
        fragranceOilMass: part('Fragrance oil (g)', oil.leaf),
        solubilizerMass: part('Solubilizer (g)', solubilizerPct),
        waterMass: part('Water (g)', waterPct),
        totalMass: batch.leaf,
        solubilizerPct,
        waterPct,
        clarity,
        ifra,
      },
      [
        ...ifra.warnings,
        ...(ifra.status === 'UNKNOWN' ? [FRAGRANCE_WARNINGS.IFRA_UNKNOWN] : []),
        ...(clarityStatus === 'UNKNOWN' ? [ROOM_SPRAY_WARNINGS.CLARITY_UNKNOWN] : []),
      ],
    );
  });
}
