import {
  ENGINE_VERSION,
  type EngineInput,
  type EngineOutput,
  type MissingData,
} from './contract';
import { planBatch } from './batch/plan';
import { costOfBatch, costPerLitre, costPerNetKg, costPerPack, packagingOfBatch, priceByMargin } from './cost/derived';
import { materialCostPerKg } from './cost/material';
import { sourcedEgp } from './cost/money';
import type { Result } from './core/result';
import { Quantity, type QuantityJSON } from './core/quantity';
import { validateFormula } from './formula/validate';
import { outputHash, inputHash } from './hash';
import { resolveDensity, type ConfidencePolicy } from './trace/sourced';
import { dedupeMissing, emptyOutcome, type Outcome, type Traced } from './trace/trace';

const PACKAGING_PROPERTY = 'packaging.perPack';

function toQuantity(q: QuantityJSON | Quantity): Quantity {
  return q instanceof Quantity ? q : Quantity.fromJSON(q);
}

/** Hashes quantities by their canonical JSON so class instances and plain objects hash alike. */
function hashableInput(input: EngineInput): EngineInput {
  if (input.batch === undefined) {
    return input;
  }
  const { size, packSize } = input.batch;
  return { ...input, batch: { ...input.batch, size: toQuantity(size).toJSON(), packSize: toQuantity(packSize).toJSON() } };
}

/** Records a step: warnings always, the traced value under `key` when ok, gaps when not. */
function record(out: Outcome, r: Result<Traced>, key: string): Traced | undefined {
  out.warnings.push(...r.warnings);
  if (r.ok) {
    out.results[key] = r.value;
    return r.value;
  }
  out.missing.push(...r.missing);
  return undefined;
}

/** Packaging cost per pack comes only from the sourced `packaging.perPack`; absent => missing. */
function packagingCost(input: EngineInput, policy: ConfidencePolicy): Result<Traced> {
  return sourcedEgp(undefined, PACKAGING_PROPERTY, 'Packaging cost per pack', input.packaging?.perPack, { fxRates: input.fxRates, policy });
}

function formulaOutcome(input: EngineInput): Outcome | undefined {
  const check = validateFormula(input.formula.lines);
  if (check.ok) {
    return undefined;
  }
  const gaps: MissingData[] = check.errors.map((e) => ({
    ...(e.materialId === undefined ? {} : { materialId: e.materialId }),
    property: 'formula',
    reason: e.messageKey,
  }));
  return { ...emptyOutcome(), missing: gaps };
}

function addPricing(out: Outcome, input: EngineInput): void {
  if (input.pricing === undefined) {
    return;
  }
  for (const [unit, price] of [['perKg', 'price.perKg'], ['perL', 'price.perL'], ['perPack', 'price.perPack']] as const) {
    const cost = out.results[`cost.${unit}`];
    if (cost !== undefined) {
      record(out, priceByMargin(cost, input.pricing.marginPct), price);
    }
  }
}

function compute(input: EngineInput): Outcome {
  const invalid = formulaOutcome(input);
  if (invalid) {
    return invalid;
  }
  const policy: ConfidencePolicy = { allowConfidenceBelow: input.policy.allowConfidenceBelow, overrides: input.policy.overrides };
  const out = emptyOutcome();
  const material = record(
    out,
    materialCostPerKg({ lines: input.formula.lines, prices: input.prices, fxRates: input.fxRates, policy }),
    'cost.materialPerKg',
  );
  if (input.batch === undefined) {
    return out;
  }
  const { wastagePct } = input.batch;
  const plan = planBatch({
    size: toQuantity(input.batch.size),
    wastagePct,
    packSize: toQuantity(input.batch.packSize),
    productDensity: input.formula.productDensity,
    policy,
  });
  Object.assign(out.results, plan.results);
  out.missing.push(...plan.missing);
  out.warnings.push(...plan.warnings);

  const perKg = material && record(out, costPerNetKg(material, wastagePct), 'cost.perKg');
  const density = resolveDensity(undefined, 'productDensity', input.formula.productDensity, policy, 'Product density (measured)');
  if (perKg && density.ok) {
    out.results['cost.perL'] = costPerLitre(perKg, density.value.leaf);
  }
  const packaging = record(out, packagingCost(input, policy), 'cost.packaging');
  const packMass = out.results['batch.packMass'];
  if (perKg && packMass && packaging) {
    out.results['cost.perPack'] = costPerPack(perKg, packMass, packaging);
  }
  const { 'batch.grossMass': gross, 'batch.fullPacks': packs } = out.results;
  if (material && gross && packs && packaging) {
    const packagingTotal = packagingOfBatch(packs, packaging);
    const { materials, batch } = costOfBatch(material, gross, packagingTotal);
    Object.assign(out.results, { 'cost.packagingBatch': packagingTotal, 'cost.materialBatch': materials, 'cost.batch': batch });
  }
  addPricing(out, input);
  return out;
}

/** Stable de-duplication that keeps first-seen order. */
function unique(items: readonly string[]): string[] {
  return [...new Set(items)];
}

/**
 * The deterministic entry point: validates the formula, costs it, plans the batch.
 * `ok` is false iff `missing` is non-empty; results are keyed (`cost.perKg`, `batch.fullPacks`, ...)
 * and each carries its trace. Same input (any key order) => same inputHash and outputHash.
 */
export function calculate(input: EngineInput): EngineOutput {
  const out = compute(input);
  const warnings = unique(out.warnings);
  const missing = dedupeMissing(out.missing);
  if (input.engineVersion !== ENGINE_VERSION) {
    warnings.push('engine.version_mismatch');
  }
  const body: Omit<EngineOutput, 'outputHash'> = {
    ok: missing.length === 0,
    missing,
    results: out.results,
    warnings,
    inputHash: inputHash(hashableInput(input)),
    engineVersion: ENGINE_VERSION,
    datasetVersion: input.datasetVersion,
  };
  return { ...body, outputHash: outputHash(body) };
}
