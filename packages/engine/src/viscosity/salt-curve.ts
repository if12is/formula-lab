/**
 * Salt curve (F-09): viscosity vs. salt % of a base, from USER MEASUREMENTS only.
 * Piecewise-linear interpolation between neighbouring points; never extrapolates
 * (outside the measured salt range => missing/out_of_measured_range). No built-in curve.
 */
import type { Confidence, MissingData } from '../contract';
import { D, type Dec } from '../core/decimal';
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
  pts.sort((a, b) => a.x.comparedTo(b.x));
  for (let i = 1; i < pts.length; i++) {
    if (pts[i]!.x.eq(pts[i - 1]!.x)) {
      gaps.push(gap(`duplicate_salt_pct: ${pts[i]!.x.toFixed()}`));
    }
  }
  return gaps.length > 0 ? missing(gaps) : ok(pts);
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
  const first = pts[0]!;
  const last = pts[pts.length - 1]!;
  if (x.lt(first.x) || x.gt(last.x)) {
    return missing([
      gap(`out_of_measured_range: ${x.toFixed()} not in [${first.x.toFixed()}, ${last.x.toFixed()}]`, 'viscosity_mPas'),
    ]);
  }
  for (let i = 0; i < pts.length; i++) {
    const p = pts[i]!;
    if (x.eq(p.x)) {
      return ok({ viscosity_mPas: p.y.toFixed(), bracket: bracketOf(p, p), confidence: p.src.confidence });
    }
    const next = pts[i + 1]!;
    if (x.lt(next.x)) {
      const y = p.y.plus(x.minus(p.x).mul(next.y.minus(p.y)).div(next.x.minus(p.x)));
      return ok({ viscosity_mPas: y.toFixed(), bracket: bracketOf(p, next), confidence: worst(p, next) });
    }
  }
  /* c8 ignore next */
  throw new Error('unreachable: x within range but no segment found');
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
  const solutions: SaltSolution[] = [];
  let up = false;
  let down = false;
  for (let i = 0; i + 1 < pts.length; i++) {
    const a = pts[i]!;
    const b = pts[i + 1]!;
    up = up || b.y.gt(a.y);
    down = down || b.y.lt(a.y);
    const lo = D.min(a.y, b.y);
    const hi = D.max(a.y, b.y);
    if (t.lt(lo) || t.gt(hi)) {
      continue;
    }
    const segment = bracketOf(a, b);
    const confidence = worst(a, b);
    if (a.y.eq(b.y)) {
      solutions.push({ saltPct: a.x.toFixed(), saltPctTo: b.x.toFixed(), segment, confidence });
    } else if (t.eq(a.y)) {
      solutions.push({ saltPct: a.x.toFixed(), segment, confidence });
    } else if (t.eq(b.y)) {
      solutions.push({ saltPct: b.x.toFixed(), segment, confidence });
    } else {
      const x = a.x.plus(t.minus(a.y).mul(b.x.minus(a.x)).div(b.y.minus(a.y)));
      solutions.push({ saltPct: x.toFixed(), segment, confidence });
    }
  }
  // A knot shared by two segments yields the same point twice; keep the first. A point that lies
  // inside a flat-interval solution is redundant.
  const intervals = solutions.filter((s) => s.saltPctTo !== undefined);
  const seen = new Set<string>();
  const unique = solutions.filter((s) => {
    if (s.saltPctTo === undefined) {
      const x = new D(s.saltPct);
      if (intervals.some((i) => x.gte(i.saltPct) && x.lte(i.saltPctTo!))) {
        return false;
      }
    }
    const key = `${s.saltPct}|${s.saltPctTo ?? ''}`;
    if (seen.has(key)) {
      return false;
    }
    seen.add(key);
    return true;
  });
  unique.sort((p, q) => new D(p.saltPct).comparedTo(new D(q.saltPct)));
  if (unique.length === 0) {
    return missing([gap(`viscosity_not_on_curve: ${t.toFixed()}`, 'saltPct')]);
  }
  const monotonic = !(up && down);
  const warnings = unique.length > 1 ? ['multiple_salt_levels_give_target_viscosity'] : [];
  return ok({ solutions: unique, monotonic }, warnings);
}
