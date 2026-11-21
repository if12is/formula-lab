/**
 * Typed decision contexts the apps build (Y-02, Y-03, Y-05) plus small pure helpers for the derived fields.
 * Decimals are strings. Every list/count is computed by the app from its records; the rule sets only judge them.
 * These are `type` aliases (not interfaces) so they are assignable to `DecisionContext`.
 */
import type { CheckStatus } from '../core/check-status';
import { dec, isDecimalString } from '../core/decimal';

/** Context for `data/decisions/approve-formula-version.json`. */
export type FormulaApprovalContext = {
  formula: {
    /** Engine validate result for the formula version (Σ and line checks). */
    validateOk: boolean;
    /** Σ of all lines, percent, decimal string. */
    totalPct: string;
  };
  engine: {
    /** Number of `missing[]` entries in the engine output, decimal string ("0" when none). */
    missingCount: string;
    missingProperties: string[];
  };
  compliance: { overall: CheckStatus };
  criticalMaterials: {
    ids: string[];
    /** Confidence of each critical material's value (e.g. `L2_supplier_tds`), one entry per material. */
    confidences: string[];
  };
  lab: {
    /** Measured properties the product family requires. */
    requiredProperties: string[];
    /** Required properties without a lab result (see `coverageGap`). */
    missingProperties: string[];
    missingCount: string;
  };
  deviations: { openCount: string; openIds: string[] };
};

/** Context for `data/decisions/release-batch.json`. */
export type ReleaseBatchContext = {
  formulaVersion: { id: string; status: string };
  lots: { coaMissingCount: string; coaMissingLotIds: string[] };
  deviations: { unapprovedCount: string; unapprovedIds: string[] };
  /** Built with `evaluateSpecs`; limits come from the app's spec data, never invented here. */
  labResults: {
    outOfSpecCount: string;
    outOfSpecProperties: string[];
    notEvaluatedCount: string;
    notEvaluatedProperties: string[];
  };
};

/** Context for `data/decisions/publish-to-sanad.json`. */
export type PublishToSanadContext = {
  cost: { approved: boolean; approvedUnitCost?: string };
  priceWaterfall: { missingCount: string; missingProperties: string[] };
  repricing: {
    /** Largest absolute material price change (percent) since the approved cost. */
    maxMaterialPriceChangePct: string;
    changedMaterialIds: string[];
    /** Configured re-approval threshold (percent). Leave undefined when not configured: the decision blocks. */
    thresholdPct?: string;
  };
};

/** Sorted, de-duplicated items of `required` that are not in `present`. */
export function coverageGap(required: readonly string[], present: readonly string[]): string[] {
  const have = new Set(present);
  return [...new Set(required.filter((r) => !have.has(r)))].sort();
}

/** Count as a decimal string, as the rule sets expect. */
export function countString(items: readonly unknown[]): string {
  return String(items.length);
}

/** Acceptance limits for one measured property. Both bounds null means no limit is defined (never a pass). */
export type SpecLimit = { property: string; min: string | null; max: string | null };

export type SpecEvaluation = {
  outOfSpec: string[];
  /** Properties with no result, no limit defined, or a non-decimal value. */
  notEvaluated: string[];
};

/** Compares measured results (decimal strings) with the limits supplied by the caller. Pure and decimal-exact. */
export function evaluateSpecs(limits: readonly SpecLimit[], results: Readonly<Record<string, string>>): SpecEvaluation {
  const outOfSpec: string[] = [];
  const notEvaluated: string[] = [];
  for (const limit of limits) {
    const measured = results[limit.property];
    const bounds = [limit.min, limit.max];
    const usable = isDecimalString(measured) && (limit.min !== null || limit.max !== null) && bounds.every((b) => b === null || isDecimalString(b));
    if (!usable) {
      notEvaluated.push(limit.property);
      continue;
    }
    const value = dec(measured as string);
    const below = limit.min !== null && value.lt(dec(limit.min));
    const above = limit.max !== null && value.gt(dec(limit.max));
    if (below || above) {
      outOfSpec.push(limit.property);
    }
  }
  return { outOfSpec: outOfSpec.sort(), notEvaluated: notEvaluated.sort() };
}
