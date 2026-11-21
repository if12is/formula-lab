/**
 * Value resolution (D-04): picks ONE value for a material property out of competing candidates.
 *
 * Order: manual override > best confidence (L1 best) > latest validFrom (ISO string compare,
 * no Date objects) > sourceId ascending > lotNo ascending (final deterministic tie-break).
 * Everything not chosen is returned with a reason so the UI can show conflicts. When several
 * candidates share the top confidence but disagree on the value, `conflict` is set.
 */
import type { Confidence, MissingData } from '../contract';
import { CONFIDENCE_RANK, parseDec } from '../core/parse';
import type { Unit } from '../core/quantity';
import { missing, ok, type Result } from '../core/result';

export interface Candidate {
  value?: string;
  min?: string;
  max?: string;
  unit: Unit;
  sourceId: string;
  confidence: Confidence;
  /** ISO date YYYY-MM-DD. */
  validFrom: string;
  lotNo?: string;
}

export interface ManualOverride {
  value: string;
  unit: Unit;
  /** Mandatory, non-empty justification. */
  reason: string;
  by: string;
  /** ISO date or ISO datetime of the decision. */
  at: string;
  sourceId: string;
}

export type RejectReason = 'lower confidence' | 'older' | 'tie broken by sourceId' | 'overridden' | 'after asOf' | 'invalid candidate';

export interface Rejected {
  candidate: Candidate;
  reason: RejectReason;
  detail?: string;
}

export interface ResolveOptions {
  override?: ManualOverride;
  /** ISO date; candidates with validFrom after it are ignored (kept in `rejected`). */
  asOf?: string;
}

export type Resolution =
  | { chosenBy: 'override'; chosen: ManualOverride; reason: string; rejected: Rejected[]; conflict: null }
  | { chosenBy: 'rule'; chosen: Candidate; rejected: Rejected[]; conflict: Conflict | null };

export interface Conflict {
  confidence: Confidence;
  /** All candidates at the top confidence (including the chosen one) that do not all agree. */
  candidates: Candidate[];
}

const ISO_DATE = /^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/;
const ISO_DATE_PREFIX = /^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])([T ].*)?$/;

function isValidCandidate(c: Candidate): string | null {
  const nums = [c.value, c.min, c.max];
  if (nums.every((n) => n === undefined)) {
    return 'no value, min or max';
  }
  if (nums.some((n) => n !== undefined && parseDec(n) === null)) {
    return 'non-decimal number';
  }
  if (!ISO_DATE.test(c.validFrom)) {
    return 'validFrom is not an ISO date';
  }
  if (!c.sourceId) {
    return 'missing sourceId';
  }
  return null;
}

function cmpStr(a: string, b: string): number {
  return a < b ? -1 : a > b ? 1 : 0;
}

function sameNum(a: string | undefined, b: string | undefined): boolean {
  if (a === undefined || b === undefined) {
    return a === b;
  }
  return parseDec(a)!.eq(parseDec(b)!);
}

function agree(a: Candidate, b: Candidate): boolean {
  return a.unit === b.unit && sameNum(a.value, b.value) && sameNum(a.min, b.min) && sameNum(a.max, b.max);
}

/** Total order: best confidence, latest validFrom, sourceId, lotNo. */
function compare(a: Candidate, b: Candidate): number {
  return (
    CONFIDENCE_RANK[a.confidence] - CONFIDENCE_RANK[b.confidence] ||
    cmpStr(b.validFrom, a.validFrom) ||
    cmpStr(a.sourceId, b.sourceId) ||
    cmpStr(a.lotNo ?? '', b.lotNo ?? '')
  );
}

export function resolveValue(
  property: string,
  candidates: readonly Candidate[],
  options: ResolveOptions = {},
  materialId?: string,
): Result<Resolution> {
  const gap = (reason: string): MissingData =>
    materialId === undefined ? { property, reason } : { materialId, property, reason };
  const { override, asOf } = options;
  if (asOf !== undefined && !ISO_DATE.test(asOf)) {
    return missing([gap('invalid_asOf')]);
  }

  const rejected: Rejected[] = [];
  const eligible: Candidate[] = [];
  for (const c of candidates) {
    const problem = isValidCandidate(c);
    if (problem !== null) {
      rejected.push({ candidate: c, reason: 'invalid candidate', detail: problem });
    } else if (asOf !== undefined && c.validFrom > asOf) {
      rejected.push({ candidate: c, reason: 'after asOf', detail: `validFrom ${c.validFrom} > asOf ${asOf}` });
    } else {
      eligible.push(c);
    }
  }

  if (override !== undefined) {
    if (parseDec(override.value) === null || !override.reason.trim() || !override.by || !ISO_DATE_PREFIX.test(override.at) || !override.sourceId) {
      return missing([gap('invalid_override: needs decimal value, non-empty reason, by, ISO at, sourceId')]);
    }
    for (const c of eligible) {
      rejected.push({ candidate: c, reason: 'overridden', detail: override.reason });
    }
    return ok({ chosenBy: 'override', chosen: override, reason: override.reason, rejected, conflict: null });
  }

  if (eligible.length === 0) {
    return missing([gap(candidates.length === 0 ? 'no_candidates' : 'no_eligible_candidates')]);
  }

  const sorted = [...eligible].sort(compare);
  const chosen = sorted[0]!;
  for (const c of sorted.slice(1)) {
    if (CONFIDENCE_RANK[c.confidence] > CONFIDENCE_RANK[chosen.confidence]) {
      rejected.push({ candidate: c, reason: 'lower confidence', detail: `${c.confidence} < ${chosen.confidence}` });
    } else if (c.validFrom < chosen.validFrom) {
      rejected.push({ candidate: c, reason: 'older', detail: `${c.validFrom} < ${chosen.validFrom}` });
    } else {
      rejected.push({ candidate: c, reason: 'tie broken by sourceId', detail: `${c.sourceId} after ${chosen.sourceId}` });
    }
  }

  const top = sorted.filter((c) => c.confidence === chosen.confidence);
  const disagreeing = top.some((c) => !agree(c, chosen));
  const conflict: Conflict | null = disagreeing ? { confidence: chosen.confidence, candidates: top } : null;
  return ok(
    { chosenBy: 'rule', chosen, rejected, conflict },
    conflict ? [`conflict: ${property} has disagreeing candidates at ${chosen.confidence}`] : [],
  );
}
