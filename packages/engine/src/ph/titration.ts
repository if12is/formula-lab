/**
 * pH adjustment from a MEASURED titration curve of the formula itself (K-07). Pure, decimal.js only.
 * The curve is the user's lab measurement: points {added, pH} where `added` is the amount of the
 * adjusting reagent (acid or base) added, as % w/w of the formula or g per kg of formula.
 * The amount for a target pH is found by linear interpolation between neighbouring measured points
 * (core/interpolate): no extrapolation (target pH outside the measured pH span => missing), and no
 * estimated curve (an L7 point is refused). A curve that rises and falls gives EVERY solution plus
 * a warning, never a silent pick.
 */
import type { Confidence, MissingData } from '../contract';
import { D, type Dec } from '../core/decimal';
import { duplicateXs, solveForY, sortKnots, type Knot } from '../core/interpolate';
import { parseDec } from '../core/parse';
import type { Quantity } from '../core/quantity';
import { all, combine, flatMap, missing, ok, type Result } from '../core/result';
import { INPUT_REASONS, resolveMassG, type ResolvedNumber } from '../sourced-input';
import { isWorseConfidence } from '../trace/sourced';

export type TitrationUnit = 'pctWW' | 'gPerKg';

export const TITRATION_REASONS = {
  NEED_TWO_POINTS: 'titration.need_at_least_2_points',
  ESTIMATE_NOT_ALLOWED: 'titration.estimate_not_allowed',
  DUPLICATE_ADDED: 'titration.duplicate_added',
  PH_OUT_OF_MEASURED_RANGE: 'titration.ph_out_of_measured_range',
} as const;

export const TITRATION_WARNINGS = {
  CURVE_NON_MONOTONIC: 'titration.curve_non_monotonic',
} as const;

export interface TitrationPoint {
  /** Reagent added, in the curve's unit (decimal string, >= 0). */
  added: string;
  /** Measured pH of the formula at that addition (decimal string). */
  pH: string;
  sourceId: string;
  confidence: Confidence;
}

export interface TitrationCurve {
  /** The acid or base that was titrated in, e.g. a material id. */
  reagentId: string;
  unit: TitrationUnit;
  points: readonly TitrationPoint[];
}

export interface TitrationInput {
  curve: TitrationCurve;
  /** Target pH (typed requirement). */
  targetPH: string;
  /** When given, each solution also reports the reagent mass for this batch. */
  batchMass?: Quantity;
}

export interface TitrationBracket {
  from: { added: string; sourceId: string };
  to: { added: string; sourceId: string };
}

export interface TitrationSolution {
  /** Reagent amount reaching the target pH (start of the interval when `addedTo` is set), in the curve's unit. */
  added: string;
  /** Only when the curve is flat at the target between two points: any amount in added..addedTo works. */
  addedTo?: string;
  /** Reagent mass for `batchMass`, grams (and the interval end). */
  amountG?: string;
  amountGTo?: string;
  segment: TitrationBracket;
  /** Worst confidence of the two neighbouring points. */
  confidence: Confidence;
}

export interface TitrationResult {
  reagentId: string;
  unit: TitrationUnit;
  /** Ascending by amount. */
  solutions: TitrationSolution[];
  monotonic: boolean;
}

interface PhKnot extends Knot {
  src: TitrationPoint;
}

const gap = (property: string, reason: string): MissingData => ({ property, reason });

function resolvePoint(p: TitrationPoint, index: number): Result<PhKnot> {
  const at = `titration[${index}]`;
  const x = parseDec(p.added);
  const y = parseDec(p.pH);
  const gaps = [
    ...(x === null || x.lt(0) ? [gap(`${at}.added`, INPUT_REASONS.INVALID_NUMBER)] : []),
    ...(y === null ? [gap(`${at}.pH`, INPUT_REASONS.INVALID_NUMBER)] : []),
    ...(p.confidence === 'L7_estimate' ? [gap(`${at}.confidence`, TITRATION_REASONS.ESTIMATE_NOT_ALLOWED)] : []),
  ];
  return gaps.length > 0 ? missing(gaps) : ok({ x: x!, y: y!, src: p });
}

/** Validates every point (all gaps listed), then sorts ascending by amount; duplicate amounts are rejected. */
function prepare(points: readonly TitrationPoint[]): Result<PhKnot[]> {
  if (points.length < 2) {
    return missing([gap('titration', TITRATION_REASONS.NEED_TWO_POINTS)]);
  }
  return flatMap(combine(points.map(resolvePoint)), (knots) => {
    const sorted = sortKnots(knots);
    const duplicates = duplicateXs(sorted).map((x) => gap('titration', `${TITRATION_REASONS.DUPLICATE_ADDED}:${x.toFixed()}`));
    return duplicates.length > 0 ? missing(duplicates) : ok(sorted);
  });
}

const bracketOf = (a: PhKnot, b: PhKnot): TitrationBracket => ({
  from: { added: a.src.added, sourceId: a.src.sourceId },
  to: { added: b.src.added, sourceId: b.src.sourceId },
});

/** Reagent mass of one addition for a batch of `batchG` grams. */
function amountOf(added: Dec, unit: TitrationUnit, batchG: Dec): string {
  return (unit === 'pctWW' ? batchG.mul(added).div(100) : batchG.div(1000).mul(added)).toFixed();
}

/** Reagent amount(s) giving the target pH on the measured curve; outside the measured pH span => missing. */
export function reagentForTargetPH(input: TitrationInput): Result<TitrationResult> {
  const target = parseDec(input.targetPH);
  const targetR: Result<Dec> = target === null ? missing([gap('targetPH', INPUT_REASONS.INVALID_NUMBER)]) : ok(target);
  const massR: Result<ResolvedNumber | undefined> =
    input.batchMass === undefined ? ok(undefined) : resolveMassG(undefined, 'batchMass', 'Batch mass (g)', input.batchMass);
  return flatMap(all([prepare(input.curve.points), targetR, massR] as const), ([pts, t, mass]) => {
    const { solutions: found, monotonic } = solveForY(pts, t);
    if (found.length === 0) {
      const lo = D.min(...pts.map((p) => p.y));
      const hi = D.max(...pts.map((p) => p.y));
      return missing([gap('targetPH', `${TITRATION_REASONS.PH_OUT_OF_MEASURED_RANGE}:${t.toFixed()} not in ${lo.toFixed()}..${hi.toFixed()}`)]);
    }
    const solutions = found.map((f): TitrationSolution => {
      const a = pts[f.lo]!;
      const b = pts[f.hi]!;
      return {
        added: f.x.toFixed(),
        ...(f.xTo !== undefined && { addedTo: f.xTo.toFixed() }),
        ...(mass !== undefined && { amountG: amountOf(f.x, input.curve.unit, mass.value) }),
        ...(mass !== undefined && f.xTo !== undefined && { amountGTo: amountOf(f.xTo, input.curve.unit, mass.value) }),
        segment: bracketOf(a, b),
        confidence: isWorseConfidence(a.src.confidence, b.src.confidence) ? a.src.confidence : b.src.confidence,
      };
    });
    return ok(
      { reagentId: input.curve.reagentId, unit: input.curve.unit, solutions, monotonic },
      monotonic ? [] : [TITRATION_WARNINGS.CURVE_NON_MONOTONIC],
    );
  });
}
