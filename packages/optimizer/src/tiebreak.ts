/**
 * J-05: deterministic tie-breaking.
 *
 * Why it is needed: an LP can have a whole face of optimal solutions (e.g. two materials with the same
 * price and function). Which vertex a simplex run returns then depends on column order and pivoting.
 *
 * What we do (two layers):
 *  1. The LP text is a pure function of the data: variables are named and ordered by materialId, rows in
 *     a fixed order (build.ts). Permuting the input materials therefore yields byte-identical LP text and
 *     (HiGHS being deterministic with our fixed options) identical solutions.
 *  2. Unique optimum: after the cost solve (optimum C*), a LEXICOGRAPHIC secondary objective is applied in
 *     materialId order: add cost <= C* + tol; minimise x₀; fix x₀ <= x₀* + tol; minimise x₁; ... The
 *     lexicographic minimum of a polytope is a single point, so the answer no longer depends on the
 *     solver's path. (Weights such as Σ k·xᵢ can still tie for special k/face combinations; the
 *     sequential form cannot.) Effect: among equal-cost formulas the one using the least of the
 *     alphabetically-first materials wins, the rest is absorbed by later ids.
 *
 * Every stage's vertex is recomputed exactly from the solver's basis (exact.ts), so the cap values are
 * exact Decimals; TIE_TOLERANCE (1e-9, EGP/kg for the cost cap and percent points for the fixes) only has
 * to absorb the solver's own feasibility tolerance. It is far below the 0.001 % rounding step.
 */
import type { Dec } from '@fl/engine';
import { costObjective, type ExtraRow, type Model } from './build';
import { objectiveValue, solveExact } from './exact';
import type { LpSolution } from './highs';
import { dec } from './util';

export const TIE_TOLERANCE = '0.000000001';

export type DeterministicSolve =
  | {
      status: 'optimal';
      /** Stage 1 (pure cost) solution: source of duals and reduced costs. */
      costStage: LpSolution;
      /** Unique lexicographic optimum, exact Decimals (see `exact`). */
      values: Dec[];
      /** True when every stage vertex was recovered exactly from the basis. */
      exact: boolean;
      /** Number of LP solves performed. */
      solves: number;
    }
  | { status: 'infeasible' | 'error'; rawStatus: string; solves: number };

/** Cost optimum then lexicographic tie-break. */
export function solveDeterministic(model: Model, omitRows?: ReadonlySet<number>): DeterministicSolve {
  const cost = costObjective(model);
  const n = model.variables.length;
  const withOmit = omitRows === undefined ? {} : { omitRows };
  const first = solveExact(model, cost, withOmit);
  if (first.sol.status !== 'optimal') {
    return { status: first.sol.status, rawStatus: first.sol.rawStatus, solves: 1 };
  }
  let solves = 1;
  let exact = first.exact;
  let values = first.values;
  const tol = dec(TIE_TOLERANCE);
  const extra: ExtraRow[] = [{ sense: '<=', rhs: objectiveValue(cost, values).plus(tol), terms: cost }];
  for (let i = 0; i < n; i += 1) {
    const stage = solveExact(model, [{ v: i, coef: dec('1') }], { extraRows: extra, ...withOmit });
    solves += 1;
    if (stage.sol.status !== 'optimal') {
      return { status: 'error', rawStatus: `tiebreak-stage-${i}:${stage.sol.rawStatus}`, solves };
    }
    exact = exact && stage.exact;
    values = stage.values;
    extra.push({ sense: '<=', rhs: (values[i] ?? dec('0')).plus(tol), terms: [{ v: i, coef: dec('1') }] });
  }
  return { status: 'optimal', costStage: first.sol, values, exact, solves };
}
