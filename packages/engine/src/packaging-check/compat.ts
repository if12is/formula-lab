/**
 * Packaging compatibility (H-02): a closure (cap) or a pump may only go on a container whose neck finish the user has
 * declared compatible with the closure's, together with a source. The engine holds NO compatibility knowledge: two
 * identical finish codes are not assumed to fit, and a pair nobody declared is not compatible.
 *
 *   PASS     every closure / pump of the BOM sits on a container with a declared, sourced pair
 *   FAIL     a closure / pump has no declared pair with a container's neck finish: the BOM is refused
 *   UNKNOWN  a neck finish is missing on a container or a closure, or a pair rests on an L7 estimate (refused unless allowed)
 *
 * The check order is container-major, closures in BOM order, so the output is stable. Pure, no decimals involved.
 */
import type { Confidence, MissingData } from '../contract';
import type { CheckStatus } from '../core/check-status';

/** The BOM item kinds that close a container. Every other kind is not part of this check. */
export const CLOSURE_KINDS = ['closure', 'pump_sprayer'] as const;
export const CONTAINER_KIND = 'container';

export const COMPAT_REASONS = {
  NECK_MISSING: 'packaging_compat.neck_finish_missing',
  NO_CONTAINER: 'packaging_compat.no_container',
  NO_PAIR: 'packaging_compat.no_declared_pair',
  PAIR_L7: 'packaging_compat.pair_confidence_L7_rejected_by_policy',
  PAIR_INVALID: 'packaging_compat.pair_invalid',
} as const;

export interface CompatItem {
  itemId: string;
  /** container, closure, pump_sprayer, ...; other kinds are ignored. */
  kind: string;
  /** The neck finish recorded in the packaging library (for a closure: the finish it fits); absent = not recorded. */
  neckFinish?: string | null;
}

/** A pair a person declared, with where the statement comes from. */
export interface CompatPair {
  containerNeck: string;
  closureNeck: string;
  sourceId: string;
  confidence: Confidence;
  validFrom?: string;
}

export interface CompatPolicy {
  /** The final check refuses a pair that rests on an L7 estimate unless this is true. */
  allowL7?: boolean;
}

export interface PairCheck {
  containerId: string;
  closureId: string;
  status: CheckStatus;
  /** The declared pair that decided a PASS. */
  pair?: CompatPair;
}

export interface CompatibilityCheck {
  status: CheckStatus;
  checks: PairCheck[];
  missing: MissingData[];
  warnings: string[];
}

/** The key two finish texts are compared by: trimmed, one space between words, lower case. Nothing else is inferred. */
export function neckKey(text: string | null | undefined): string | null {
  if (typeof text !== 'string') {
    return null;
  }
  const key = text.trim().replace(/\s+/g, ' ').toLowerCase();
  return key === '' ? null : key;
}

const isClosure = (kind: string): boolean => (CLOSURE_KINDS as readonly string[]).includes(kind);

export function checkPackagingCompatibility(items: readonly CompatItem[], pairs: readonly CompatPair[], policy: CompatPolicy = {}): CompatibilityCheck {
  const containers = items.filter((i) => i.kind === CONTAINER_KIND);
  const closures = items.filter((i) => isClosure(i.kind));
  const gaps: MissingData[] = [];
  const warnings: string[] = [];
  const checks: PairCheck[] = [];

  for (const pair of pairs) {
    if (neckKey(pair.containerNeck) === null || neckKey(pair.closureNeck) === null || typeof pair.sourceId !== 'string' || pair.sourceId === '') {
      gaps.push({ property: 'pair', reason: COMPAT_REASONS.PAIR_INVALID });
    }
  }

  if (closures.length > 0 && containers.length === 0) {
    for (const closure of closures) {
      gaps.push({ materialId: closure.itemId, property: 'container', reason: COMPAT_REASONS.NO_CONTAINER });
    }
  }
  if (containers.length > 0 && closures.length === 0) {
    warnings.push(...containers.map((c) => `packaging_compat.container_without_closure:${c.itemId}`));
  }

  for (const container of containers) {
    const containerKey = neckKey(container.neckFinish);
    for (const closure of closures) {
      const closureKey = neckKey(closure.neckFinish);
      if (containerKey === null || closureKey === null) {
        for (const [item, key] of [[container, containerKey], [closure, closureKey]] as const) {
          if (key === null && !gaps.some((g) => g.materialId === item.itemId && g.property === 'neckFinish')) {
            gaps.push({ materialId: item.itemId, property: 'neckFinish', reason: COMPAT_REASONS.NECK_MISSING });
          }
        }
        checks.push({ containerId: container.itemId, closureId: closure.itemId, status: 'UNKNOWN' });
        continue;
      }
      const declared = pairs.filter((p) => neckKey(p.containerNeck) === containerKey && neckKey(p.closureNeck) === closureKey);
      const usable = declared.filter((p) => p.confidence !== 'L7_estimate' || policy.allowL7 === true);
      const decisive = usable[0];
      if (decisive !== undefined) {
        if (decisive.confidence === 'L7_estimate') {
          warnings.push(`packaging_compat.pair_is_estimate:${container.itemId}:${closure.itemId}`);
        }
        checks.push({ containerId: container.itemId, closureId: closure.itemId, status: 'PASS', pair: decisive });
      } else if (declared.length > 0) {
        gaps.push({ materialId: closure.itemId, property: 'pair', reason: COMPAT_REASONS.PAIR_L7 });
        checks.push({ containerId: container.itemId, closureId: closure.itemId, status: 'UNKNOWN' });
      } else {
        checks.push({ containerId: container.itemId, closureId: closure.itemId, status: 'FAIL' });
      }
    }
  }

  const status: CheckStatus = gaps.length > 0 || checks.some((c) => c.status === 'UNKNOWN') ? 'UNKNOWN' : checks.some((c) => c.status === 'FAIL') ? 'FAIL' : 'PASS';
  return { status, checks, missing: gaps, warnings };
}
