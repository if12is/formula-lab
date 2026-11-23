/**
 * Emulsifier calculation paths by ionic character (M-08). The HLB mixing rule is for non-ionic emulsifiers only, so an
 * emulsifier's ionicity picks how it is worked out:
 *
 *  - nonionic   -> `hlb`: oil-phase required HLB and the two-emulsifier blend (hlb.ts).
 *  - cationic   -> `cationic_tds`: no HLB. The dose is compared with the usage range the supplier's TDS states.
 *  - anionic    -> `anionic_active`: no HLB. The surfactant's share is its active matter: mass x active % / 100.
 *  - amphoteric / other -> `custom`: no rule is applied; the formulator works it out and records the result.
 *
 * Pure, decimal.js only. The TDS range and the active matter are sourced inputs (no table here, no default), and an L7
 * estimate is refused in the final calculation unless the policy allows it, exactly as for rHLB.
 */
import { D } from '../core/decimal';
import { parseDec, type SourcedScalar } from '../core/parse';
import { missing, ok, type Result } from '../core/result';
import type { MissingData } from '../contract';
import type { HlbPolicy, Ionicity } from './hlb';

export type EmulsifierPath = 'hlb' | 'cationic_tds' | 'anionic_active' | 'custom';

const PATHS: Readonly<Record<Ionicity, EmulsifierPath>> = {
  nonionic: 'hlb',
  cationic: 'cationic_tds',
  anionic: 'anionic_active',
  amphoteric: 'custom',
  other: 'custom',
};

function bad(property: string, reason: string, materialId?: string): MissingData {
  return materialId === undefined ? { property, reason } : { materialId, property, reason };
}

/** The path an emulsifier follows. An undeclared ionicity is missing, never assumed non-ionic. */
export function emulsifierPathFor(materialId: string, ionicity: Ionicity | undefined): Result<{ path: EmulsifierPath }> {
  if (ionicity === undefined) {
    return missing([bad('ionicity', 'ionicity_missing', materialId)]);
  }
  const path = PATHS[ionicity];
  if (path === undefined) {
    return missing([bad('ionicity', 'ionicity_unknown', materialId)]);
  }
  return ok({ path });
}

function sourcedGap(value: SourcedScalar | undefined, property: string, materialId: string, policy: HlbPolicy, gaps: MissingData[]): string | null {
  if (value === undefined) {
    gaps.push(bad(property, 'missing_input', materialId));
    return null;
  }
  const parsed = parseDec(value.value);
  if (parsed === null || parsed.lt(0)) {
    gaps.push(bad(property, 'invalid_input', materialId));
    return null;
  }
  if (value.confidence === 'L7_estimate' && policy.allowL7 !== true) {
    gaps.push(bad(property, 'confidence_L7_rejected_by_policy', materialId));
    return null;
  }
  return parsed.toFixed();
}

export interface CationicTdsInput {
  materialId: string;
  ionicity: Ionicity | undefined;
  /** The dose used, % w/w of the formula, a decimal string. */
  dosePercent: string;
  /** The usage range the supplier's TDS states, % w/w, each end sourced. */
  tdsMin: SourcedScalar | undefined;
  tdsMax: SourcedScalar | undefined;
}

export interface CationicTdsResult {
  dosePercent: string;
  min: string;
  max: string;
  position: 'below' | 'within' | 'above';
  /** Distance outside the range in % w/w points; "0" when within. */
  gap: string;
}

/**
 * Compares a cationic emulsifier's dose with the TDS usage range. No HLB is used. The result states where the dose sits;
 * it is not a pass or fail verdict, because the range is the supplier's recommendation and the formulator decides.
 */
export function cationicTdsDose(input: CationicTdsInput, policy: HlbPolicy = {}): Result<CationicTdsResult> {
  const gaps: MissingData[] = [];
  if (input.ionicity === undefined) {
    gaps.push(bad('ionicity', 'ionicity_missing', input.materialId));
  } else if (input.ionicity !== 'cationic') {
    gaps.push(bad('ionicity', 'path_cationic_only', input.materialId));
  }
  const dose = parseDec(input.dosePercent);
  if (dose === null || dose.lt(0) || dose.gt(100)) {
    gaps.push(bad('dosePercent', 'invalid_input', input.materialId));
  }
  const min = sourcedGap(input.tdsMin, 'tdsMin', input.materialId, policy, gaps);
  const max = sourcedGap(input.tdsMax, 'tdsMax', input.materialId, policy, gaps);
  if (gaps.length > 0 || dose === null || min === null || max === null) {
    return missing(gaps);
  }
  const lo = new D(min);
  const hi = new D(max);
  if (lo.gt(hi)) {
    return missing([bad('tdsMin', 'tds_range_inverted', input.materialId)]);
  }
  const position = dose.lt(lo) ? 'below' : dose.gt(hi) ? 'above' : 'within';
  const gap = position === 'below' ? lo.minus(dose) : position === 'above' ? dose.minus(hi) : new D(0);
  return ok({ dosePercent: dose.toFixed(), min: lo.toFixed(), max: hi.toFixed(), position, gap: gap.toFixed() });
}

export interface AnionicActiveInput {
  materialId: string;
  ionicity: Ionicity | undefined;
  /** Mass of the commercial material, any consistent mass unit. */
  mass: string;
  /** Active matter of the commercial material, % w/w, sourced. */
  activeMatter: SourcedScalar | undefined;
  /** Optional total mass of the formula in the same unit, to state the active as % of the formula. */
  totalMass?: string;
}

export interface AnionicActiveResult {
  activeMass: string;
  /** Active matter as % w/w of the formula, or null when no total was given. */
  activePercentOfTotal: string | null;
}

/** The active-matter share of an anionic surfactant: mass x active % / 100. No HLB is used. */
export function anionicActiveMatter(input: AnionicActiveInput, policy: HlbPolicy = {}): Result<AnionicActiveResult> {
  const gaps: MissingData[] = [];
  if (input.ionicity === undefined) {
    gaps.push(bad('ionicity', 'ionicity_missing', input.materialId));
  } else if (input.ionicity !== 'anionic') {
    gaps.push(bad('ionicity', 'path_anionic_only', input.materialId));
  }
  const mass = parseDec(input.mass);
  if (mass === null || mass.lte(0)) {
    gaps.push(bad('mass', 'invalid_mass', input.materialId));
  }
  const active = sourcedGap(input.activeMatter, 'activeMatter', input.materialId, policy, gaps);
  let total: string | null = null;
  if (input.totalMass !== undefined) {
    const t = parseDec(input.totalMass);
    if (t === null || t.lte(0) || (mass !== null && t.lt(mass))) {
      gaps.push(bad('totalMass', 'invalid_total_mass', input.materialId));
    } else {
      total = t.toFixed();
    }
  }
  if (active !== null && new D(active).gt(100)) {
    gaps.push(bad('activeMatter', 'active_matter_over_100', input.materialId));
  }
  if (gaps.length > 0 || mass === null || active === null) {
    return missing(gaps);
  }
  const activeMass = mass.mul(active).div(100);
  return ok({ activeMass: activeMass.toFixed(), activePercentOfTotal: total === null ? null : activeMass.div(total).mul(100).toFixed() });
}
