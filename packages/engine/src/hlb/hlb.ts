/**
 * HLB calculations (F-08). Pure, decimal.js only. No data tables: every rHLB and every Davies
 * group number is an INPUT carrying its own source and confidence.
 *
 * Methods (definitional constants, not invented data):
 *  - Griffin, W.C. (1949) J. Soc. Cosmet. Chem. 1:311; (1954) 5:249:  HLB = 20 * Mh / M.
 *  - Davies, J.T. (1957) Proc. 2nd Int. Congr. Surface Activity 1:426:  HLB = 7 + sum(Hi) - 0.475 * n.
 *  - Required HLB of an oil phase = sum(mi * rHLBi) / sum(mi) (mass-weighted mean, oil phase only).
 *  - Two-emulsifier blend: f_high = (HLB_t - HLB_low) / (HLB_high - HLB_low) (algebraic mixing rule).
 */
import { D, type Dec } from '../core/decimal';
import { parseDec, type SourcedScalar } from '../core/parse';
import { Quantity } from '../core/quantity';
import { missing, ok, type Result } from '../core/result';
import type { MissingData } from '../contract';

export const HLB_SOURCES = {
  griffin: { citation: 'Griffin W.C., J. Soc. Cosmet. Chem. 1949, 1:311-326; 1954, 5:249-256', formula: 'HLB = 20 x Mh / M', constant: '20' },
  davies: { citation: 'Davies J.T., Proc. 2nd Int. Congr. Surface Activity, 1957, 1:426-438', formula: 'HLB = 7 + sum(Hi) - 0.475 x n', constants: { base: '7', perGroup: '0.475' } },
  requiredHlb: { formula: 'sum(mi x rHLBi) / sum(mi), oil phase only' },
  blend: { formula: 'f_high = (HLB_t - HLB_low) / (HLB_high - HLB_low)' },
} as const;

export type HlbPhase = 'oil' | 'water' | 'other';

export interface HlbLine {
  materialId: string;
  /** Decimal string, any consistent mass unit (only ratios matter). */
  mass: string;
  phase: HlbPhase;
  /** Required HLB of this material for the target emulsion type; needed for oil lines. */
  requiredHLB?: SourcedScalar;
}

export interface HlbPolicy {
  /** Final-calc policy rejects L7 estimates unless explicitly overridden (M-08 hooks here). */
  allowL7?: boolean;
}

const ZERO = new D(0);

function bad(property: string, reason: string, materialId?: string): MissingData {
  return materialId === undefined ? { property, reason } : { materialId, property, reason };
}

export function requiredHLB(lines: readonly HlbLine[], policy: HlbPolicy = {}): Result<{ value: string }> {
  const oil = lines.filter((l) => l.phase === 'oil');
  if (oil.length === 0) {
    return missing([bad('oil_phase', 'no_oil_phase_lines')]);
  }
  const gaps: MissingData[] = [];
  const warnings: string[] = [];
  let weighted: Dec = ZERO;
  let total: Dec = ZERO;
  for (const l of oil) {
    const m = parseDec(l.mass);
    if (m === null || m.lt(0)) {
      gaps.push(bad('mass', 'invalid_mass', l.materialId));
      continue;
    }
    const r = l.requiredHLB;
    if (r === undefined) {
      gaps.push(bad('requiredHLB', 'missing_required_hlb', l.materialId));
      continue;
    }
    const v = parseDec(r.value);
    if (v === null || v.lt(0)) {
      gaps.push(bad('requiredHLB', 'invalid_required_hlb', l.materialId));
      continue;
    }
    if (r.confidence === 'L7_estimate') {
      if (policy.allowL7 !== true) {
        gaps.push(bad('requiredHLB', 'confidence_L7_rejected_by_policy', l.materialId));
        continue;
      }
      warnings.push(`requiredHLB of ${l.materialId} is an L7 estimate (override allowed)`);
    }
    weighted = weighted.plus(m.mul(v));
    total = total.plus(m);
  }
  if (gaps.length > 0) {
    return missing(gaps, warnings);
  }
  if (total.isZero()) {
    return missing([bad('mass', 'oil_phase_total_mass_zero')], warnings);
  }
  return ok({ value: weighted.div(total).toFixed() }, warnings);
}

export interface Emulsifier {
  materialId: string;
  hlb: SourcedScalar;
}

export interface BlendResult {
  fractionHigh: string;
  fractionLow: string;
  high: { materialId: string; mass: Quantity };
  low: { materialId: string; mass: Quantity };
}

/** Splits `totalMass` of emulsifier between a low-HLB and a high-HLB emulsifier to hit `targetHLB`. */
export function blendEmulsifiers(
  targetHLB: string,
  low: Emulsifier,
  high: Emulsifier,
  totalMass: Quantity,
): Result<BlendResult> {
  const t = parseDec(targetHLB);
  const lo = parseDec(low.hlb.value);
  const hi = parseDec(high.hlb.value);
  const gaps: MissingData[] = [];
  if (t === null) {
    gaps.push(bad('targetHLB', 'invalid_input'));
  }
  if (lo === null) {
    gaps.push(bad('hlb', 'invalid_input', low.materialId));
  }
  if (hi === null) {
    gaps.push(bad('hlb', 'invalid_input', high.materialId));
  }
  if (totalMass.dimension !== 'mass' || totalMass.value.lte(0)) {
    gaps.push(bad('totalMass', 'must_be_positive_mass'));
  }
  if (gaps.length > 0 || t === null || lo === null || hi === null) {
    return missing(gaps);
  }
  if (!(lo.lt(t) && t.lt(hi))) {
    return missing([bad('targetHLB', `target_out_of_range: need HLB_low (${lo.toFixed()}) < target (${t.toFixed()}) < HLB_high (${hi.toFixed()})`)]);
  }
  const fHigh = t.minus(lo).div(hi.minus(lo));
  const fLow = new D(1).minus(fHigh);
  return ok({
    fractionHigh: fHigh.toFixed(),
    fractionLow: fLow.toFixed(),
    high: { materialId: high.materialId, mass: totalMass.mul(fHigh) },
    low: { materialId: low.materialId, mass: totalMass.mul(fLow) },
  });
}

/** Griffin: HLB = 20 * Mh / M. Requires 0 <= Mh <= M, M > 0 (any consistent mass unit). */
export function griffinHLB(hydrophilicMass: string, totalMass: string): Result<{ value: string }> {
  const mh = parseDec(hydrophilicMass);
  const m = parseDec(totalMass);
  if (mh === null || m === null) {
    return missing([bad('griffin', 'invalid_input')]);
  }
  if (m.lte(0) || mh.lt(0) || mh.gt(m)) {
    return missing([bad('griffin', 'need 0 <= Mh <= M and M > 0')]);
  }
  return ok({ value: new D(20).mul(mh).div(m).toFixed() });
}

export interface DaviesGroup {
  /** Free-text group name, e.g. for the trace; no table is embedded. */
  group: string;
  /** Davies group number Hi (input, sourced). */
  h: SourcedScalar;
  /** Occurrences of the group in the molecule; positive integer string, default "1". */
  count?: string;
}

/** Davies: HLB = 7 + sum(count_i * Hi) - 0.475 * n, n = sum(count_i). */
export function daviesHLB(groups: readonly DaviesGroup[]): Result<{ value: string }> {
  if (groups.length === 0) {
    return missing([bad('daviesGroups', 'no_groups')]);
  }
  const gaps: MissingData[] = [];
  let sum: Dec = ZERO;
  let n: Dec = ZERO;
  for (const g of groups) {
    const h = parseDec(g.h?.value);
    const c = parseDec(g.count ?? '1');
    if (h === null) {
      gaps.push(bad(`davies_group:${g.group}`, 'missing_or_invalid_group_number'));
    } else if (c === null || !c.isInteger() || c.lte(0)) {
      gaps.push(bad(`davies_group:${g.group}`, 'count_must_be_positive_integer'));
    } else {
      sum = sum.plus(h.mul(c));
      n = n.plus(c);
    }
  }
  if (gaps.length > 0) {
    return missing(gaps);
  }
  return ok({ value: new D(7).plus(sum).minus(new D('0.475').mul(n)).toFixed() });
}

