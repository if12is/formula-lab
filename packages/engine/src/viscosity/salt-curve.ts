/**
 * Salt curve (F-09): viscosity vs. salt % of a base, from USER MEASUREMENTS only.
 * Piecewise-linear interpolation between neighbouring points; never extrapolates
 * (outside the measured salt range => missing/out_of_measured_range). No built-in curve.
 */
import type { Confidence, MissingData } from '../contract';
import type { Dec } from '../core/decimal';
import { duplicateXs, interpolateAt, solveForY, sortKnots } from '../core/interpolate';
import { CONFIDENCE_RANK, parseDec } from '../core/parse';
import { missing, ok, type Result } from '../core/result';

export interface SaltPoint {
  /** Salt (e.g. NaCl) % w/w, decimal string. */
  saltPct: string;
  /** Measured viscosity in mPa.s, decimal string. */
  viscosity_mPas: string;
  sourceId: string;
  confidence: Confidence;
}

interface Pt {
  x: Dec;
  y: Dec;
  src: SaltPoint;
}

export interface Bracket {
  from: { saltPct: string; sourceId: string };
  to: { saltPct: string; sourceId: string };
}

function gap(reason: string, property = 'salt_curve'): MissingData {
  return { property, reason };
}

/** Validates and sorts ascending by saltPct. Ties are rejected, so the order is fully deterministic. */
function prepare(points: readonly SaltPoint[]): Result<Pt[]> {
  const gaps: MissingData[] = [];
  const pts: Pt[] = [];
  for (const p of points) {
    const x = parseDec(p.saltPct);
    const y = parseDec(p.viscosity_mPas);
    if (x === null || x.lt(0) || y === null || y.lt(0)) {
      gaps.push(gap(`invalid_point: saltPct=${String(p.saltPct)} viscosity_mPas=${String(p.viscosity_mPas)}`));
    } else {
      pts.push({ x, y, src: p });
    }
  }
  if (gaps.length > 0) {
    return missing(gaps);
  }
  if (pts.length < 2) {
    return missing([gap('need_at_least_2_points')]);
  }
  const sorted = sortKnots(pts);
  const duplicates = duplicateXs(sorted).map((x) => gap(`duplicate_salt_pct: ${x.toFixed()}`));
  return duplicates.length > 0 ? missing(duplicates) : ok(sorted);
}

function bracketOf(a: Pt, b: Pt): Bracket {
  return {
    from: { saltPct: a.src.saltPct, sourceId: a.src.sourceId },
    to: { saltPct: b.src.saltPct, sourceId: b.src.sourceId },
  };
}

function worst(a: Pt, b: Pt): Confidence {
  return CONFIDENCE_RANK[a.src.confidence] >= CONFIDENCE_RANK[b.src.confidence] ? a.src.confidence : b.src.confidence;
}

export interface ViscosityAt {
  viscosity_mPas: string;
  /** Neighbouring measured points used (equal ends when the query hits a measured point). */
  bracket: Bracket;
  /** Worst confidence of the two neighbours. */
  confidence: Confidence;
}

export function viscosityAt(points: readonly SaltPoint[], saltPct: string): Result<ViscosityAt> {
  const prepared = prepare(points);
  if (!prepared.ok) {
    return prepared;
  }
  const x = parseDec(saltPct);
  if (x === null || x.lt(0)) {
    return missing([gap('invalid_input', 'saltPct')]);
  }
  const pts = prepared.value;
  const at = interpolateAt(pts, x);
  if (at === null) {
    const first = pts[0]!;
    const last = pts[pts.length - 1]!;
    return missing([
      gap(`out_of_measured_range: ${x.toFixed()} not in [${first.x.toFixed()}, ${last.x.toFixed()}]`, 'viscosity_mPas'),
    ]);
  }
  const lo = pts[at.lo]!;
  const hi = pts[at.hi]!;
  return ok({ viscosity_mPas: at.y.toFixed(), bracket: bracketOf(lo, hi), confidence: worst(lo, hi) });
}

export interface SaltSolution {
  /** Salt % solving the target (start of the interval when `saltPctTo` is set). */
  saltPct: string;
  /** Only for a flat segment whose viscosity equals the target: the solution is saltPct..saltPctTo. */
  saltPctTo?: string;
  segment: Bracket;
  confidence: Confidence;
}

export interface SaltForViscosity {
  solutions: SaltSolution[];
  /** False when the curve rises and falls: several salt levels can give the same viscosity. */
  monotonic: boolean;
}

/**
 * Inverse lookup: salt % giving `targetMPas`. Each segment between neighbouring measured points
 * is linear, hence monotonic, so each is inverted on its own; solutions of all segments are
 * returned ascending by salt %. Targets outside the measured viscosity span => missing.
 */
export function saltForViscosity(points: readonly SaltPoint[], targetMPas: string): Result<SaltForViscosity> {
  const prepared = prepare(points);
  if (!prepared.ok) {
    return prepared;
  }
  const t = parseDec(targetMPas);
  if (t === null || t.lt(0)) {
    return missing([gap('invalid_input', 'targetViscosity')]);
  }
  const pts = prepared.value;
  const { solutions: found, monotonic } = solveForY(pts, t);
  const solutions = found.map((f): SaltSolution => {
    const a = pts[f.lo]!;
    const b = pts[f.hi]!;
    return {
      saltPct: f.x.toFixed(),
      ...(f.xTo !== undefined && { saltPctTo: f.xTo.toFixed() }),
      segment: bracketOf(a, b),
      confidence: worst(a, b),
    };
  });
  if (solutions.length === 0) {
    return missing([gap(`viscosity_not_on_curve: ${t.toFixed()}`, 'saltPct')]);
  }
  const warnings = solutions.length > 1 ? ['multiple_salt_levels_give_target_viscosity'] : [];
  return ok({ solutions, monotonic }, warnings);
}
