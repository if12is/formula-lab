/**
 * `optimize(input)`: the tier optimizer entry point (J-01 … J-08 wired together).
 *
 * Pipeline: validate profile -> required-field check -> validate material data (every value sourced,
 * L7 gated) -> optional reverse pricing -> build exact model -> HiGHS cost solve + lexicographic tie-break
 * -> round to 0.001 % + q.s. re-balance -> EXACT verification (any violation is an error, a second attempt
 * with a rounding safety margin is made first) -> output with hashes. Infeasible models return the
 * minimal conflicting constraint set as message keys. Nothing here uses Date/random; all numbers that
 * cross the boundary are decimal strings.
 */
import {
  canonicalJson, gateConfidence, isIsoDate, resolveSourcedNumber, sha256Hex,
  type Confidence, type ConfidenceOverride, type MissingData, type SourcedValue,
} from '@fl/engine';
import type { SourcedScalar } from '@fl/engine';
import { buildModel, tightenForRounding, type BuildMaterial, type Model } from './build';
import { solverInfo, loadHighs, type SolverInfo } from './highs';
import { findConflicts, type Conflict } from './iis';
import { requiredFieldsMissing, validateTierProfile, type ProfileError, type TierProfile } from './profile';
import { reversePricing, type ReversePricingInput, type ReversePricingResult } from './reverse';
import { exactCostPerKg, roundSolution, verify, type RowCheck } from './round';
import { changedPrice, dualString, percentDelta, type PriceScenario, type ScenarioResult } from './sensitivity';
import { solveDeterministic } from './tiebreak';
import { byCodeUnit, dec, str, type Dec } from './util';

export const OPTIMIZER_VERSION = '0.1.0';

/** Fields required when `policy.requiredFields` is not given. Which fields a product truly needs is a chemist/product decision (J-02). */
export const DEFAULT_REQUIRED_FIELDS: readonly string[] = ['totalActiveMinPct'];

/** Function tags of a material, with the same sourcing rules as every other value. */
export interface SourcedTags {
  tags: string[];
  sourceId: string;
  confidence: Confidence;
  /** ISO date YYYY-MM-DD. */
  validFrom: string;
}

export interface OptimizerMaterial {
  materialId: string;
  /** Dated delivered price, EGP per kg (unit 'EGP' as in the engine). */
  price?: SourcedScalar;
  /** Active matter, unit '%w/w', 0..100. A non-active material must declare 0 explicitly. */
  activeMatterPct?: SourcedScalar;
  /** Required when the profile has function bounds; may be absent otherwise. */
  functions?: SourcedTags;
}

export interface OptimizerPolicy {
  allowConfidenceBelow?: Confidence;
  overrides?: ConfidenceOverride[];
  /** Profile fields that must be non-null before solving. Default: DEFAULT_REQUIRED_FIELDS. */
  requiredFields?: string[];
}

export interface OptimizerInput {
  profile: unknown;
  materials: OptimizerMaterial[];
  /** The declared q.s. (balancing) material, normally water. */
  qsMaterialId?: string | null;
  policy?: OptimizerPolicy;
  /** Needed when the profile has a target retail price (J-08 turns it into a cost cap). */
  reversePricing?: Omit<ReversePricingInput, 'shelfPriceInclVat' | 'fillKg'> & { packFillKg?: string; densityKgPerL?: SourcedScalar };
  /** Explicit "price of X changes by p %" re-solves (J-07). */
  scenarios?: PriceScenario[];
}

export interface OptimizerMessage {
  key: string;
  params: Record<string, string>;
}

export interface FormulaLine {
  materialId: string;
  /** Percent w/w, 3 decimals (the engine display step). */
  percentWW: string;
  priceEGPperKg: string;
  priceSourceId: string;
  priceConfidence: Confidence;
  /** percent/100 × price, exact. */
  costEGPperKg: string;
}

export interface BindingConstraint {
  id: string;
  /** Exact slack of the verified solution; always "0" for a binding constraint. */
  slack: string;
  message: OptimizerMessage | null;
  /** ∂cost/∂rhs from HiGHS (9 significant digits), or null if not available. */
  dual: string | null;
}

export interface DualReport {
  rows: { id: string; dual: string }[];
  reducedCosts: { materialId: string; reducedCost: string }[];
}

export type OptimizerStatus = 'optimal' | 'missing' | 'invalid' | 'infeasible' | 'error';

export interface OptimizerOutput {
  ok: boolean;
  status: OptimizerStatus;
  missing: MissingData[];
  errors: OptimizerMessage[];
  warnings: string[];
  formula: FormulaLine[];
  /** Exact cost of the verified formula, EGP per kg. Round at display time. */
  costPerKg: string | null;
  binding: BindingConstraint[];
  duals: DualReport | null;
  conflicts: Conflict[];
  reversePricing: ReversePricingResult | null;
  measuredRequirements: string[];
  sensitivity: ScenarioResult[];
  solver: SolverInfo & { lpSolves: string; roundingAttempt: '1' | '2' | null };
  optimizerVersion: string;
  inputHash: string;
  outputHash: string;
}

interface PreparedMaterial extends BuildMaterial {
  priceSourceId: string;
  priceConfidence: Confidence;
}

interface Prepared {
  profile: TierProfile;
  materials: PreparedMaterial[];
  qsMaterialId: string | null;
  targetCostCapPerKg: Dec | null;
  reverse: ReversePricingResult | null;
  warnings: string[];
}

type PrepareResult =
  | { kind: 'ok'; prepared: Prepared }
  | { kind: 'missing'; missing: MissingData[]; warnings: string[] }
  | { kind: 'invalid'; errors: OptimizerMessage[] };

function profileErrors(errors: ProfileError[]): OptimizerMessage[] {
  return errors.map((e) => ({ key: e.key, params: { path: e.path, ...(e.detail !== undefined && { detail: e.detail }) } }));
}

/** Canonical (order independent) form of the input: what inputHash covers. */
function canonicalInput(input: OptimizerInput, profile: TierProfile | null): unknown {
  const sortedProfile =
    profile === null
      ? input.profile
      : {
          ...profile,
          constraints: {
            ...profile.constraints,
            functionBounds: [...profile.constraints.functionBounds].sort((a, b) => byCodeUnit(a.function, b.function)),
            materialBounds: [...profile.constraints.materialBounds].sort((a, b) => byCodeUnit(a.materialId, b.materialId)),
            requiredMaterials: [...profile.constraints.requiredMaterials].sort(byCodeUnit),
            forbiddenMaterials: [...profile.constraints.forbiddenMaterials].sort(byCodeUnit),
            measuredRequirements: [...profile.constraints.measuredRequirements].sort(byCodeUnit),
          },
        };
  return {
    optimizerVersion: OPTIMIZER_VERSION,
    profile: sortedProfile,
    materials: [...input.materials]
      .map((m) => (m.functions === undefined ? m : { ...m, functions: { ...m.functions, tags: [...m.functions.tags].sort(byCodeUnit) } }))
      .sort((a, b) => byCodeUnit(a.materialId, b.materialId)),
    qsMaterialId: input.qsMaterialId ?? null,
    policy: {
      ...input.policy,
      ...(input.policy?.requiredFields !== undefined && { requiredFields: [...input.policy.requiredFields].sort(byCodeUnit) }),
    },
    reversePricing: input.reversePricing ?? null,
    scenarios: [...(input.scenarios ?? [])].sort((a, b) => byCodeUnit(a.materialId, b.materialId) || byCodeUnit(a.changePct, b.changePct)),
  };
}

function hashOf(value: unknown): string {
  try {
    return sha256Hex(canonicalJson(value));
  } catch {
    return sha256Hex('unhashable-input');
  }
}

function prepare(input: OptimizerInput): PrepareResult & { profile: TierProfile | null } {
  const validated = validateTierProfile(input.profile);
  if (!validated.ok) {
    return { kind: 'invalid', errors: profileErrors(validated.errors), profile: null };
  }
  const profile = validated.profile;
  const k = profile.constraints;
  const policy = input.policy ?? {};
  const confidencePolicy = {
    ...(policy.allowConfidenceBelow !== undefined && { allowConfidenceBelow: policy.allowConfidenceBelow }),
    ...(policy.overrides !== undefined && { overrides: policy.overrides }),
  };
  const warnings: string[] = [];
  const gaps: MissingData[] = [];

  gaps.push(...requiredFieldsMissing(profile, policy.requiredFields ?? DEFAULT_REQUIRED_FIELDS));
  for (const fb of k.functionBounds) {
    if (fb.min === null && fb.max === null) {
      warnings.push(`optimizer.warning.function_bound_unset:${fb.function}`);
    }
  }
  if (k.measuredRequirements.length > 0) {
    warnings.push(`optimizer.warning.measured_requirements_not_enforced:${[...k.measuredRequirements].sort(byCodeUnit).join(',')}`);
  }

  // Duplicate material ids are a data error, not something to guess about.
  const ids = input.materials.map((m) => m.materialId);
  const dupes = ids.filter((id, i) => ids.indexOf(id) !== i);
  if (dupes.length > 0) {
    return { kind: 'invalid', errors: [...new Set(dupes)].sort(byCodeUnit).map((id) => ({ key: 'optimizer.input.duplicate_material', params: { materialId: id } })), profile };
  }

  const forbidden = new Set(k.forbiddenMaterials);
  const offered = new Set(ids);
  const needsFunctions = k.functionBounds.some((f) => f.min !== null || f.max !== null);
  const materials: PreparedMaterial[] = [];
  for (const m of [...input.materials].sort((a, b) => byCodeUnit(a.materialId, b.materialId))) {
    if (forbidden.has(m.materialId)) {
      continue;
    }
    const price = resolveSourcedNumber(m.price, { materialId: m.materialId, property: 'price', label: `${m.materialId} price (EGP/kg)`, unit: 'EGP', min: '0' }, confidencePolicy);
    const active = resolveSourcedNumber(m.activeMatterPct, { materialId: m.materialId, property: 'activeMatterPct', label: `${m.materialId} active matter (%)`, unit: '%w/w', min: '0', max: '100' }, confidencePolicy);
    let tags: string[] | null = [];
    if (m.functions !== undefined || needsFunctions) {
      tags = null;
      const f = m.functions;
      const wellFormed = f !== undefined && Array.isArray(f.tags) && f.tags.every((t) => typeof t === 'string' && t !== '') && isIsoDate(f.validFrom) && typeof f.sourceId === 'string' && f.sourceId !== '';
      if (!wellFormed) {
        gaps.push({ materialId: m.materialId, property: 'functions', reason: f === undefined ? 'input.missing' : 'optimizer.input.invalid_function_tags' });
      } else {
        const gate = gateConfidence(m.materialId, 'functions', { value: '0', unit: '%w/w', sourceId: f.sourceId, confidence: f.confidence, validFrom: f.validFrom } satisfies SourcedValue, confidencePolicy);
        if (gate.ok) {
          tags = [...new Set(f.tags)];
          warnings.push(...gate.warnings);
        } else {
          gaps.push(...gate.missing);
        }
      }
    }
    if (!price.ok) {
      gaps.push(...price.missing);
    } else {
      warnings.push(...price.warnings);
    }
    if (!active.ok) {
      gaps.push(...active.missing);
    } else {
      warnings.push(...active.warnings);
    }
    if (price.ok && active.ok && tags !== null && m.price !== undefined) {
      materials.push({
        materialId: m.materialId,
        pricePerKg: price.value.value,
        activePct: active.value.value,
        functions: tags,
        priceSourceId: m.price.sourceId,
        priceConfidence: m.price.confidence,
      });
    }
  }
  if (materials.length === 0 && gaps.length === 0) {
    gaps.push({ property: 'materials', reason: 'optimizer.no_usable_materials' });
  }

  // Required materials: offered, and given an explicit min > 0 (no invented "small" default).
  for (const id of [...k.requiredMaterials].sort(byCodeUnit)) {
    if (!offered.has(id)) {
      gaps.push({ materialId: id, property: 'material', reason: 'optimizer.required_material_not_offered' });
    }
    const bound = k.materialBounds.find((b) => b.materialId === id);
    if (bound === undefined || bound.min === null || dec(bound.min).lte(0)) {
      gaps.push({ materialId: id, property: `profile.materialBounds.${id}.min`, reason: 'optimizer.required_material_needs_min' });
    }
  }
  for (const b of [...k.materialBounds].sort((x, y) => byCodeUnit(x.materialId, y.materialId))) {
    if (b.min !== null && dec(b.min).gt(0) && !offered.has(b.materialId) && !forbidden.has(b.materialId) && !k.requiredMaterials.includes(b.materialId)) {
      gaps.push({ materialId: b.materialId, property: 'material', reason: 'optimizer.bounded_material_not_offered' });
    }
  }
  const qs = input.qsMaterialId ?? null;
  if (qs !== null && !materials.some((m) => m.materialId === qs) && ![...gaps].some((g) => g.materialId === qs)) {
    gaps.push({ materialId: qs, property: 'qsMaterial', reason: 'optimizer.qs_material_not_usable' });
  }

  let targetCostCapPerKg: Dec | null = null;
  let reverse: ReversePricingResult | null = null;
  if (profile.targetRetailPrice.value !== null) {
    const rp = input.reversePricing ?? {};
    const per = profile.targetRetailPrice.per;
    let fillKg: string | undefined;
    if (per === 'kg') {
      fillKg = '1';
    } else if (per === 'pack') {
      fillKg = rp.packFillKg;
    } else {
      const density = resolveSourcedNumber(rp.densityKgPerL, { property: 'densityKgPerL', label: 'product density (kg/L)', unit: 'kg/L', min: '0', minExclusive: true }, confidencePolicy);
      if (density.ok) {
        fillKg = str(density.value.value);
        warnings.push(...density.warnings);
      } else {
        gaps.push(...density.missing.map((g) => ({ ...g, property: `reversePricing.${g.property}` })));
      }
    }
    if (per !== 'L' || fillKg !== undefined) {
      const { packFillKg: _p, densityKgPerL: _d, ...rest } = rp;
      void _p;
      void _d;
      const r = reversePricing({ ...rest, shelfPriceInclVat: profile.targetRetailPrice.value, ...(fillKg !== undefined && { fillKg }) });
      if (!r.ok) {
        gaps.push(...r.missing);
      } else if (r.value.maxFormulaCostPerKg === null) {
        gaps.push({ property: 'reversePricing.nonFormulaCostPerUnit', reason: 'input.missing' });
      } else {
        reverse = r.value;
        targetCostCapPerKg = dec(r.value.maxFormulaCostPerKg);
      }
    }
  }

  if (gaps.length > 0) {
    return { kind: 'missing', missing: gaps, warnings, profile };
  }
  return { kind: 'ok', prepared: { profile, materials, qsMaterialId: qs, targetCostCapPerKg, reverse, warnings }, profile };
}

type CoreResult =
  | {
      kind: 'optimal';
      model: Model;
      values: Dec[];
      checks: RowCheck[];
      costStage: { rowDual: Map<string, number>; reducedCost: Map<string, number> };
      attempt: 1 | 2;
      exact: boolean;
      solves: number;
    }
  | { kind: 'infeasible'; conflicts: Conflict[]; solves: number }
  | { kind: 'error'; errors: OptimizerMessage[]; solves: number };

function solvePrepared(p: Prepared, materials: readonly PreparedMaterial[]): CoreResult {
  const base = buildModel({ profile: p.profile, materials, qsMaterialId: p.qsMaterialId, targetCostCapPerKg: p.targetCostCapPerKg });
  let solves = 0;
  let firstViolation: RowCheck | undefined;
  for (const attempt of [1, 2] as const) {
    const model = tightenForRounding(base, attempt === 2);
    const res = solveDeterministic(model);
    solves += res.solves;
    if (res.status !== 'optimal') {
      if (res.status === 'error') {
        return { kind: 'error', errors: [{ key: 'optimizer.error.solver_status', params: { status: res.rawStatus } }], solves };
      }
      if (attempt === 1) {
        return { kind: 'infeasible', conflicts: findConflicts(model), solves };
      }
      break;
    }
    const values = roundSolution(base, res.values);
    const check = verify(base, values);
    if (check.ok) {
      return { kind: 'optimal', model: base, values, checks: check.rows, costStage: { rowDual: res.costStage.rowDual, reducedCost: res.costStage.reducedCost }, attempt, exact: res.exact, solves };
    }
    firstViolation ??= check.violations[0];
  }
  return { kind: 'error', errors: [{ key: 'optimizer.error.verification_failed', params: { id: firstViolation?.id ?? 'unknown', slack: firstViolation === undefined ? '' : str(firstViolation.slack) } }], solves };
}

function formulaOf(model: Model, values: readonly Dec[], materials: readonly PreparedMaterial[]): FormulaLine[] {
  const lines: FormulaLine[] = [];
  for (const v of model.variables) {
    const pct = values[v.index] ?? dec('0');
    if (pct.isZero()) {
      continue;
    }
    const m = materials.find((x) => x.materialId === v.materialId);
    lines.push({
      materialId: v.materialId,
      percentWW: pct.toFixed(3),
      priceEGPperKg: str(v.pricePerKg),
      priceSourceId: m?.priceSourceId ?? '',
      priceConfidence: m?.priceConfidence ?? 'L7_estimate',
      costEGPperKg: str(pct.mul(v.pricePerKg).div(100)),
    });
  }
  return lines.sort((a, b) => dec(b.percentWW).comparedTo(a.percentWW) || byCodeUnit(a.materialId, b.materialId));
}

function runScenarios(p: Prepared, base: Extract<CoreResult, { kind: 'optimal' }>, scenarios: readonly PriceScenario[]): { results: ScenarioResult[]; warnings: string[] } {
  const baseFormula = formulaOf(base.model, base.values, p.materials);
  const baseCost = exactCostPerKg(base.model, base.values);
  const pctOf = (lines: FormulaLine[], id: string): Dec => dec(lines.find((l) => l.materialId === id)?.percentWW ?? '0');
  const results: ScenarioResult[] = [];
  const warnings: string[] = [];
  for (const s of [...scenarios].sort((a, b) => byCodeUnit(a.materialId, b.materialId) || byCodeUnit(a.changePct, b.changePct))) {
    const target = p.materials.find((m) => m.materialId === s.materialId);
    const valid = target !== undefined && /^-?\d+(\.\d+)?$/.test(s.changePct) && dec(s.changePct).gt(-100);
    const common = { materialId: s.materialId, changePct: s.changePct };
    if (!valid) {
      results.push({ ...common, basePriceEGPperKg: target === undefined ? '' : str(target.pricePerKg), scenarioPriceEGPperKg: '', status: 'missing', scenarioCostPerKg: null, costDeltaPerKg: null, costDeltaPct: null, costDeltaIfFormulaUnchangedPerKg: null, formulaChanged: false, formulaDelta: [] });
      warnings.push(`optimizer.warning.scenario_invalid:${s.materialId}`);
      continue;
    }
    const newPrice = changedPrice(target.pricePerKg, s.changePct);
    const scenarioMaterials = p.materials.map((m) => (m.materialId === s.materialId ? { ...m, pricePerKg: newPrice } : m));
    const r = solvePrepared(p, scenarioMaterials);
    const header = { ...common, basePriceEGPperKg: str(target.pricePerKg), scenarioPriceEGPperKg: str(newPrice) };
    if (r.kind !== 'optimal') {
      results.push({ ...header, status: r.kind, scenarioCostPerKg: null, costDeltaPerKg: null, costDeltaPct: null, costDeltaIfFormulaUnchangedPerKg: null, formulaChanged: false, formulaDelta: [] });
      continue;
    }
    const scenarioFormula = formulaOf(r.model, r.values, scenarioMaterials);
    const cost = exactCostPerKg(r.model, r.values);
    const ids = [...new Set([...baseFormula, ...scenarioFormula].map((l) => l.materialId))].sort(byCodeUnit);
    const formulaDelta = ids
      .map((id) => ({ materialId: id, basePercentWW: pctOf(baseFormula, id).toFixed(3), scenarioPercentWW: pctOf(scenarioFormula, id).toFixed(3), deltaPercentWW: pctOf(scenarioFormula, id).minus(pctOf(baseFormula, id)).toFixed(3) }))
      .filter((d) => !dec(d.deltaPercentWW).isZero());
    const delta = cost.minus(baseCost);
    results.push({
      ...header,
      status: 'optimal',
      scenarioCostPerKg: str(cost),
      costDeltaPerKg: str(delta),
      costDeltaPct: percentDelta(delta, baseCost),
      costDeltaIfFormulaUnchangedPerKg: str(pctOf(baseFormula, s.materialId).mul(newPrice.minus(target.pricePerKg)).div(100)),
      formulaChanged: formulaDelta.length > 0,
      formulaDelta,
    });
  }
  return { results, warnings };
}

function emptyOutput(partial: Partial<OptimizerOutput> & Pick<OptimizerOutput, 'status' | 'inputHash'>): OptimizerOutput {
  const out: Omit<OptimizerOutput, 'outputHash'> = {
    ok: false,
    missing: [],
    errors: [],
    warnings: [],
    formula: [],
    costPerKg: null,
    binding: [],
    duals: null,
    conflicts: [],
    reversePricing: null,
    measuredRequirements: [],
    sensitivity: [],
    solver: { ...solverInfo(), lpSolves: '0', roundingAttempt: null },
    optimizerVersion: OPTIMIZER_VERSION,
    ...partial,
  };
  return { ...out, outputHash: hashOf(out) };
}

/**
 * Runs the optimizer. Async only because HiGHS (WASM) is loaded on first use; the computation itself is
 * synchronous and deterministic.
 */
export async function optimize(input: OptimizerInput): Promise<OptimizerOutput> {
  await loadHighs();
  const prep = prepare(input);
  const inputHash = hashOf(canonicalInput(input, prep.profile));
  if (prep.kind === 'invalid') {
    return emptyOutput({ status: 'invalid', inputHash, errors: prep.errors });
  }
  if (prep.kind === 'missing') {
    return emptyOutput({ status: 'missing', inputHash, missing: prep.missing, warnings: prep.warnings });
  }
  const p = prep.prepared;
  const measured = [...p.profile.constraints.measuredRequirements].sort(byCodeUnit);
  const core = solvePrepared(p, p.materials);
  const solver = { ...solverInfo(), lpSolves: String(core.solves), roundingAttempt: core.kind === 'optimal' ? (String(core.attempt) as '1' | '2') : null };
  if (core.kind === 'infeasible') {
    return emptyOutput({ status: 'infeasible', inputHash, warnings: p.warnings, conflicts: core.conflicts, reversePricing: p.reverse, measuredRequirements: measured, solver });
  }
  if (core.kind === 'error') {
    return emptyOutput({ status: 'error', inputHash, warnings: p.warnings, errors: core.errors, reversePricing: p.reverse, measuredRequirements: measured, solver });
  }

  const warnings = [...p.warnings];
  if (core.attempt === 2) {
    warnings.push('optimizer.warning.rounding_margin_applied');
  }
  if (!core.exact) {
    warnings.push('optimizer.warning.solver_precision_fallback');
  }
  const rowByName = new Map(core.model.rows.map((r) => [r.id, r]));
  const binding: BindingConstraint[] = core.checks
    .filter((c) => c.id !== 'sum' && !c.id.startsWith('nonneg.') && c.slack.isZero())
    .map((c) => {
      const row = rowByName.get(c.id);
      const dual = row === undefined ? undefined : core.costStage.rowDual.get(row.name);
      return { id: c.id, slack: '0', message: row?.message ?? null, dual: dual === undefined ? null : dualString(dual) };
    });
  const duals: DualReport = {
    rows: core.model.rows.map((r) => ({ id: r.id, dual: dualString(core.costStage.rowDual.get(r.name) ?? 0) })),
    reducedCosts: core.model.variables.map((v) => ({ materialId: v.materialId, reducedCost: dualString(core.costStage.reducedCost.get(v.name) ?? 0) })),
  };
  const scenarioRun = runScenarios(p, core, input.scenarios ?? []);
  warnings.push(...scenarioRun.warnings);
  return emptyOutput({
    ok: true,
    status: 'optimal',
    inputHash,
    warnings,
    formula: formulaOf(core.model, core.values, p.materials),
    costPerKg: str(exactCostPerKg(core.model, core.values)),
    binding,
    duals,
    reversePricing: p.reverse,
    measuredRequirements: measured,
    sensitivity: scenarioRun.results,
    solver,
  });
}
