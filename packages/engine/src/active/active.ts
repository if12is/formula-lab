/**
 * Active matter (F-04). Pure, decimal.js only.
 *   A_total = sum(m_i x a_i) / sum(m_i)          (a_i = active matter % of the trade grade)
 *   m_raw   = M x A_target / a_raw                (dilution of a raw grade to a target active)
 *   water   = m x (A_current / A_target - 1)      (water to add to an existing batch)
 * Every a_i is an input with its own source and confidence; no grade value is embedded.
 */
import type { SourcedScalar } from '../core/parse';
import type { Quantity } from '../core/quantity';
import { all, missing, ok, type Result } from '../core/result';
import { resolveMassG, resolveSourcedNumber, resolveUserNumber, type SourcedNumberSpec } from '../sourced-input';
import type { ConfidencePolicy } from '../trace/sourced';
import { decOf, traced, type Traced } from '../trace/trace';
import { dec } from '../core/decimal';

export const ACTIVE_REASONS = {
  NO_LINES: 'active.no_lines',
  VALUE_REQUIRED: 'active.value_required',
  NON_ACTIVE_DECLARATION_REQUIRED: 'active.non_active_declaration_required',
  CONTRIBUTING_BUT_ZERO: 'active.contributing_but_zero',
  NON_ACTIVE_HAS_VALUE: 'active.declared_non_active_has_value',
  TARGET_EXCEEDS_RAW: 'active.target_exceeds_raw',
  TARGET_EXCEEDS_CURRENT: 'active.target_exceeds_current',
  CURRENT_ACTIVE_ZERO: 'active.current_active_zero',
} as const;

const PCT = { unit: '%w/w', min: '0', max: '100' } as const;
const TARGET_SPEC: SourcedNumberSpec = { ...PCT, min: '0', minExclusive: true, property: 'targetActivePct', label: 'Target active (%)' };

export interface ActiveLine {
  materialId: string;
  mass: Quantity;
  /** true: the material is meant to carry active matter (a_i must be present and > 0). */
  contributesActive: boolean;
  /** Active matter % of the trade grade (COA/TDS). A non-active material declares 0 with a source. */
  activeMatter?: SourcedScalar;
}

export interface ActiveBlendInput {
  lines: readonly ActiveLine[];
  policy: ConfidencePolicy;
}

export interface ActiveBlend {
  /** A_total, % w/w. */
  activeMatterPct: Traced;
  activeMass: Traced;
  totalMass: Traced;
}

interface Term {
  mass: Traced;
  active: Traced;
}

function activeTerm(line: ActiveLine, policy: ConfidencePolicy): Result<Term> {
  const id = line.materialId;
  const mass = resolveMassG(id, 'mass', `${id} mass (g)`, line.mass);
  const a = resolveSourcedNumber(
    line.activeMatter,
    { ...PCT, materialId: id, property: 'activeMatter', label: `${id} active matter (%)` },
    policy,
  );
  const both = all([mass, a] as const);
  if (!both.ok) {
    // A declared-absent value gets a more specific reason than the generic input.missing.
    const specific = line.activeMatter === undefined;
    return specific
      ? { ...both, missing: both.missing.map((g) => (g.property === 'activeMatter' ? { ...g, reason: line.contributesActive ? ACTIVE_REASONS.VALUE_REQUIRED : ACTIVE_REASONS.NON_ACTIVE_DECLARATION_REQUIRED } : g)) }
      : both;
  }
  const [m, act] = both.value;
  const inconsistent = line.contributesActive
    ? act.value.isZero() && ACTIVE_REASONS.CONTRIBUTING_BUT_ZERO
    : act.value.gt(0) && ACTIVE_REASONS.NON_ACTIVE_HAS_VALUE;
  if (inconsistent) {
    return missing([{ materialId: id, property: 'activeMatter', reason: inconsistent }], both.warnings);
  }
  const term = traced('mul', `${id} active mass = m x a/100`, m.value.mul(act.value).div(100), 'g', [m.leaf, act.leaf]);
  return ok({ mass: m.leaf, active: term }, both.warnings);
}

/** A_total of a blend. EVERY line without a usable active value is listed in `missing`. */
export function activeMatterOfBlend(input: ActiveBlendInput): Result<ActiveBlend> {
  if (input.lines.length === 0) {
    return missing([{ property: 'lines', reason: ACTIVE_REASONS.NO_LINES }]);
  }
  const terms = all(input.lines.map((l) => activeTerm(l, input.policy)));
  if (!terms.ok) {
    return terms;
  }
  const totalMass = terms.value.reduce((s, t) => s.plus(decOf(t.mass)), dec('0'));
  const activeMass = terms.value.reduce((s, t) => s.plus(decOf(t.active)), dec('0'));
  const totalT = traced('sum', 'Total mass (g)', totalMass, 'g', terms.value.map((t) => t.mass));
  const activeT = traced('sum', 'Active mass (g) = Σ m x a/100', activeMass, 'g', terms.value.map((t) => t.active));
  const pct = traced('div', 'A_total (%) = Σ(m x a) / Σm', activeMass.div(totalMass).mul(100), '%w/w', [activeT, totalT]);
  return ok({ activeMatterPct: pct, activeMass: activeT, totalMass: totalT }, terms.warnings);
}

export interface DilutionInput {
  /** Final batch mass M. */
  batchMass: Quantity;
  /** Target active matter, % w/w (a requirement typed by the user, not a sourced datum). */
  targetActivePct: string;
  rawActive: SourcedScalar;
  rawMaterialId?: string;
  policy: ConfidencePolicy;
}

export interface Dilution {
  rawMass: Traced;
  /** Water (diluent) mass = M - m_raw. */
  diluentMass: Traced;
}

/** m_raw = M x A_target / a_raw; the rest of the batch is diluent. A target above a_raw cannot be reached by dilution. */
export function rawMassForTarget(input: DilutionInput): Result<Dilution> {
  const id = input.rawMaterialId;
  const inputs = all([
    resolveMassG(undefined, 'batchMass', 'Batch mass M (g)', input.batchMass),
    resolveSourcedNumber(
      input.rawActive,
      { ...PCT, materialId: id, property: 'rawActive', min: '0', minExclusive: true, label: `${id ?? 'raw'} active matter (%)` },
      input.policy,
    ),
    resolveUserNumber(input.targetActivePct, TARGET_SPEC),
  ] as const);
  if (!inputs.ok) {
    return inputs;
  }
  const [m, raw, target] = inputs.value;
  if (target.value.gt(raw.value)) {
    return missing([{ materialId: id, property: 'targetActivePct', reason: ACTIVE_REASONS.TARGET_EXCEEDS_RAW }], inputs.warnings);
  }
  const rawMass = traced('mul', 'm_raw = M x A_target / a_raw', m.value.mul(target.value).div(raw.value), 'g', [m.leaf, target.leaf, raw.leaf]);
  const diluent = traced('sub', 'Diluent = M - m_raw', m.value.minus(decOf(rawMass)), 'g', [m.leaf, rawMass]);
  return ok({ rawMass, diluentMass: diluent }, inputs.warnings);
}

export interface WaterToTargetInput {
  existingMass: Quantity;
  /** Current active %: a sourced value, or a traced result such as activeMatterOfBlend().activeMatterPct. */
  currentActive: SourcedScalar | Traced | undefined;
  targetActivePct: string;
  policy: ConfidencePolicy;
}

export interface WaterToTarget {
  waterMass: Traced;
  finalMass: Traced;
}

function isTraced(x: SourcedScalar | Traced | undefined): x is Traced {
  return x !== undefined && 'trace' in x;
}

/** Water to add to an existing batch so its active falls to the target: w = m x (A_cur / A_target - 1). */
export function waterToReachTarget(input: WaterToTargetInput): Result<WaterToTarget> {
  const spec: SourcedNumberSpec = { ...PCT, property: 'currentActive', label: 'Current active (%)' };
  const cur = isTraced(input.currentActive)
    ? resolveUserNumber(input.currentActive.value, spec)
    : resolveSourcedNumber(input.currentActive, spec, input.policy);
  const inputs = all([
    resolveMassG(undefined, 'existingMass', 'Existing mass m (g)', input.existingMass),
    cur,
    resolveUserNumber(input.targetActivePct, TARGET_SPEC),
  ] as const);
  if (!inputs.ok) {
    return inputs;
  }
  const [m, current, target] = inputs.value;
  if (current.value.isZero()) {
    return missing([{ property: 'currentActive', reason: ACTIVE_REASONS.CURRENT_ACTIVE_ZERO }], inputs.warnings);
  }
  if (target.value.gt(current.value)) {
    return missing([{ property: 'targetActivePct', reason: ACTIVE_REASONS.TARGET_EXCEEDS_CURRENT }], inputs.warnings);
  }
  const currentLeaf = isTraced(input.currentActive) ? input.currentActive : current.leaf;
  const water = m.value.mul(current.value.div(target.value).minus(1));
  const waterT = traced('mul', 'Water = m x (A_cur / A_target - 1)', water, 'g', [m.leaf, currentLeaf, target.leaf]);
  const finalT = traced('add', 'Final mass = m + water', m.value.plus(water), 'g', [m.leaf, waterT]);
  return ok({ waterMass: waterT, finalMass: finalT }, inputs.warnings);
}
