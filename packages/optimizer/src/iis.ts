/**
 * J-06: infeasibility explanation.
 *
 * IIS-like minimal conflicting set by a deterministic DELETION FILTER: go through the non-structural
 * constraints in the model's fixed row order; try dropping each from the still-infeasible set; if the
 * model stays infeasible the constraint was not needed (drop it for good), otherwise keep it. What is
 * left is a minimal infeasible subset (removing any one of its members makes the rest feasible) together
 * with the structural rows (Σx = 100, x >= 0), which are never reported. Order is fixed, so the same
 * data always gives the same set. (HiGHS ships an IIS routine in newer versions; the wasm build we pin
 * does not expose it, and a filter over a handful of rows is cheap and portable.)
 *
 * Each conflicting constraint is mapped to a message key with params; cost limits additionally carry the
 * cheapest cost reachable once that limit is lifted, so the message can say "below the cheapest feasible
 * by X EGP".
 */
import { costObjective, renderLp, type ConstraintMessage, type Model } from './build';
import { objectiveValue, solveExact } from './exact';
import { solveLp } from './highs';
import { D, dec, str } from './util';

export interface Conflict {
  id: string;
  key: string;
  params: Record<string, string>;
}

function isFeasible(model: Model, omit: ReadonlySet<number>): boolean {
  return solveLp(renderLp(model, { objective: costObjective(model), omitRows: omit })).status === 'optimal';
}

/** Cheapest cost (EGP/kg) of the model with one row lifted, rounded UP to 0.01; null if still infeasible. */
function cheapestWithout(model: Model, rowIndex: number): string | null {
  const cost = costObjective(model);
  const r = solveExact(model, cost, { omitRows: new Set([rowIndex]) });
  if (r.sol.status !== 'optimal') {
    return null;
  }
  return str(objectiveValue(cost, r.values).toDecimalPlaces(2, D.ROUND_UP));
}

/** Minimal conflicting set of a model known to be infeasible. Returns [] when only structural rows conflict. */
export function findConflicts(model: Model): Conflict[] {
  const omit = new Set<number>();
  for (const row of model.rows) {
    if (row.structural) {
      continue;
    }
    omit.add(row.index);
    if (isFeasible(model, omit)) {
      omit.delete(row.index);
    }
  }
  const kept = model.rows.filter((r) => !r.structural && !omit.has(r.index));
  return kept.map((row) => {
    const message: ConstraintMessage = row.message ?? { key: 'optimizer.infeasible.structural', params: {} };
    if (row.id !== 'maxCost' && row.id !== 'targetPrice') {
      return { id: row.id, key: message.key, params: message.params };
    }
    const cheapest = cheapestWithout(model, row.index);
    if (cheapest === null) {
      return { id: row.id, key: 'optimizer.infeasible.cost_limit_conflicts', params: { limitEGPperKg: message.params.limitEGPperKg ?? '' } };
    }
    const limit = message.params.limitEGPperKg ?? '0';
    const gap = dec(cheapest).minus(limit).toDecimalPlaces(2, D.ROUND_UP);
    return { id: row.id, key: message.key, params: { ...message.params, minAchievableEGPperKg: cheapest, gapEGPperKg: str(gap) } };
  });
}
