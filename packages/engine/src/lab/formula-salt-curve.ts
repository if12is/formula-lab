/**
 * A formula's OWN salt (viscosity) curve (O-03): the points are lab measurements taken on trials of that formula
 * (viscosity at an added salt %, each a local L1 result with its own source), and nothing else. The interpolation is
 * the F-09 one (viscosity/salt-curve.ts over core/interpolate.ts): piecewise linear, no extrapolation, ties rejected.
 * A formula without a curve, or with fewer than two points, gives `missing`, never a generic figure.
 * Pure, decimal.js only.
 */
import type { Confidence } from '../contract';
import { missing, ok, type Result } from '../core/result';
import { saltForViscosity, viscosityAt, type SaltPoint } from '../viscosity/salt-curve';
import { inputLeaf, referenceLeaf, traced, type Traced } from '../trace/trace';

export interface FormulaSaltPoint {
  /** Salt % w/w added in the trial, decimal string. */
  saltPct: string;
  /** Viscosity measured at that salt %, mPa.s, decimal string. */
  viscosity_mPas: string;
  /** The measurement the point is (e.g. `lab_measurement:42`). */
  sourceId: string;
  confidence: Confidence;
}

export interface FormulaSaltCurve {
  formulaId: string;
  points: readonly FormulaSaltPoint[];
}

export const FORMULA_SALT_CURVE_REASONS = {
  NO_CURVE: 'salt_curve.formula_curve_missing',
} as const;

const toPoint = (p: FormulaSaltPoint): SaltPoint => ({ ...p });

function noCurve(curve: FormulaSaltCurve | undefined): boolean {
  return curve === undefined || curve.points.length === 0;
}

const NO_CURVE_GAP = [{ property: 'salt_curve', reason: FORMULA_SALT_CURVE_REASONS.NO_CURVE }];

function pointLeaf(saltPct: string, viscosity: string, sourceId: string, confidence: Confidence): Traced {
  return referenceLeaf(`Measured viscosity at ${saltPct}% salt`, viscosity, 'mPa·s', sourceId, confidence);
}

export interface FormulaViscosity {
  viscosity: Traced;
  /** The measured points the value lies between (the same point twice when the salt % was measured). */
  bracket: { from: { saltPct: string; sourceId: string }; to: { saltPct: string; sourceId: string } };
}

/**
 * Viscosity of the formula at a salt %, from the formula's own measured curve. The trace holds the two neighbouring
 * measurements (with their source and confidence) and the salt % asked for.
 */
export function formulaViscosityAtSalt(curve: FormulaSaltCurve | undefined, saltPct: string): Result<FormulaViscosity> {
  if (curve === undefined || noCurve(curve)) {
    return missing(NO_CURVE_GAP);
  }
  const pts = curve.points.map(toPoint);
  const at = viscosityAt(pts, saltPct);
  if (!at.ok) {
    return at;
  }
  const { bracket, viscosity_mPas } = at.value;
  const find = (x: { saltPct: string; sourceId: string }): SaltPoint =>
    pts.find((p) => p.sourceId === x.sourceId && p.saltPct === x.saltPct) as SaltPoint;
  const lo = find(bracket.from);
  const hi = find(bracket.to);
  const leaves = [pointLeaf(lo.saltPct, lo.viscosity_mPas, lo.sourceId, lo.confidence)];
  if (hi !== lo) {
    leaves.push(pointLeaf(hi.saltPct, hi.viscosity_mPas, hi.sourceId, hi.confidence));
  }
  const viscosity = traced(
    'interp',
    `Viscosity at ${saltPct}% salt from the formula's own curve`,
    viscosity_mPas,
    'mPa·s',
    [inputLeaf('Salt (%)', saltPct, '%w/w'), ...leaves],
  );
  return ok({ viscosity, bracket }, at.warnings);
}

export interface FormulaSaltSolution {
  saltPct: Traced;
  /** Only for a flat stretch of the curve at the target: every salt % from `saltPct` to here gives it. */
  saltPctTo?: string;
  confidence: Confidence;
}

export interface FormulaSaltForViscosity {
  solutions: FormulaSaltSolution[];
  monotonic: boolean;
}

/** Salt % giving a target viscosity on the formula's own curve; several levels are all returned, ascending. */
export function formulaSaltForViscosity(curve: FormulaSaltCurve | undefined, targetMPas: string): Result<FormulaSaltForViscosity> {
  if (curve === undefined || noCurve(curve)) {
    return missing(NO_CURVE_GAP);
  }
  const pts = curve.points.map(toPoint);
  const inv = saltForViscosity(pts, targetMPas);
  if (!inv.ok) {
    return inv;
  }
  const solutions = inv.value.solutions.map((s): FormulaSaltSolution => {
    const leaves = [s.segment.from, s.segment.to]
      .filter((x, i, all) => i === 0 || x.sourceId !== all[0]!.sourceId)
      .map((x) => {
        const p = pts.find((q) => q.sourceId === x.sourceId) as SaltPoint;
        return pointLeaf(p.saltPct, p.viscosity_mPas, p.sourceId, p.confidence);
      });
    return {
      saltPct: traced('interp', `Salt % giving ${targetMPas} mPa.s on the formula's own curve`, s.saltPct, '%w/w', [
        inputLeaf('Target viscosity', targetMPas, 'mPa·s'),
        ...leaves,
      ]),
      ...(s.saltPctTo !== undefined && { saltPctTo: s.saltPctTo }),
      confidence: s.confidence,
    };
  });
  return ok({ solutions, monotonic: inv.value.monotonic }, inv.warnings);
}
