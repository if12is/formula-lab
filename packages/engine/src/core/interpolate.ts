/**
 * Piecewise-linear interpolation over measured knots (salt curve, titration curve, storage loss).
 * Pure Decimal arithmetic. It NEVER extrapolates: a query outside the knots' x-range gives `null`.
 * Callers validate and sort their knots first (see sortKnots / duplicateXs); every function here
 * expects knots ascending by x with distinct x values.
 */
import { D, type Dec } from './decimal';

export interface Knot {
  x: Dec;
  y: Dec;
}

/** Ascending-by-x copy. Equal x values keep their input order (the sort is stable). */
export function sortKnots<K extends Knot>(knots: readonly K[]): K[] {
  return [...knots].sort((a, b) => a.x.comparedTo(b.x));
}

/** x values that occur more than once in knots sorted ascending (each duplicated x is listed once per extra knot). */
export function duplicateXs<K extends Knot>(sorted: readonly K[]): Dec[] {
  return sorted.filter((k, i) => i > 0 && k.x.eq(sorted[i - 1]!.x)).map((k) => k.x);
}

export interface InterpolatedAt {
  y: Dec;
  /** Indexes of the neighbouring knots used; equal when the query hits a knot exactly. */
  lo: number;
  hi: number;
}

/** y at `x`, or null when x lies outside the measured range (or there are no knots). */
export function interpolateAt<K extends Knot>(sorted: readonly K[], x: Dec): InterpolatedAt | null {
  const first = sorted[0];
  const last = sorted[sorted.length - 1];
  if (first === undefined || last === undefined || x.lt(first.x) || x.gt(last.x)) {
    return null;
  }
  // First knot with knot.x >= x; it exists because x <= last.x. At index 0 it equals x (x >= first.x).
  const hi = sorted.findIndex((k) => k.x.gte(x));
  const upper = sorted[hi]!;
  if (upper.x.eq(x)) {
    return { y: upper.y, lo: hi, hi };
  }
  const lower = sorted[hi - 1]!;
  const y = lower.y.plus(x.minus(lower.x).mul(upper.y.minus(lower.y)).div(upper.x.minus(lower.x)));
  return { y, lo: hi - 1, hi };
}

export interface InverseSolution {
  /** x solving the target (start of the interval when `xTo` is set). */
  x: Dec;
  /** Only for a flat segment whose y equals the target: every x in x..xTo is a solution. */
  xTo?: Dec;
  /** Neighbouring knots of the segment that produced it. */
  lo: number;
  hi: number;
}

export interface InverseSolutions {
  /** Ascending by x. Empty when the target is not on the curve. */
  solutions: InverseSolution[];
  /** False when the curve both rises and falls: several x can give the same y. */
  monotonic: boolean;
}

/**
 * x values where the curve equals `target`. Each segment is linear, hence monotonic, so each is
 * inverted on its own. A knot shared by two segments yields one solution; a point inside a
 * flat-interval solution is dropped as redundant.
 */
export function solveForY<K extends Knot>(sorted: readonly K[], target: Dec): InverseSolutions {
  const found: InverseSolution[] = [];
  let up = false;
  let down = false;
  for (let i = 0; i + 1 < sorted.length; i++) {
    const a = sorted[i]!;
    const b = sorted[i + 1]!;
    up = up || b.y.gt(a.y);
    down = down || b.y.lt(a.y);
    if (target.lt(D.min(a.y, b.y)) || target.gt(D.max(a.y, b.y))) {
      continue;
    }
    const seg = { lo: i, hi: i + 1 };
    if (a.y.eq(b.y)) {
      found.push({ x: a.x, xTo: b.x, ...seg });
    } else if (target.eq(a.y)) {
      found.push({ x: a.x, ...seg });
    } else if (target.eq(b.y)) {
      found.push({ x: b.x, ...seg });
    } else {
      found.push({ x: a.x.plus(target.minus(a.y).mul(b.x.minus(a.x)).div(b.y.minus(a.y))), ...seg });
    }
  }
  const intervals = found.filter((s) => s.xTo !== undefined);
  const seen = new Set<string>();
  const solutions = found.filter((s) => {
    if (s.xTo === undefined && intervals.some((v) => s.x.gte(v.x) && s.x.lte(v.xTo!))) {
      return false;
    }
    const key = `${s.x.toFixed()}|${s.xTo?.toFixed() ?? ''}`;
    if (seen.has(key)) {
      return false;
    }
    seen.add(key);
    return true;
  });
  solutions.sort((p, q) => p.x.comparedTo(q.x));
  return { solutions, monotonic: !(up && down) };
}
