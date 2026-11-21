/**
 * Exact vertex recovery (the numeric heart of the "doubles are not trusted" rule).
 *
 * The npm `highs` wrapper returns the solution as printed text with ~6 significant digits, which is not
 * enough to round to 0.001 % safely or to chain the tie-break stages. But the BASIS HiGHS reports (which
 * columns and rows are basic, which sit at a bound) is exact combinatorial information. Given the basis, the
 * vertex is the unique solution of a square linear system, which we solve here in Decimal (34 digits,
 * deterministic partial-pivot Gaussian elimination). Result: the LP optimum as Decimals, independent of the
 * solver's printing precision. If the reported basis is not usable (wrong size, singular system) the caller
 * falls back to the printed doubles and says so (`exact: false`); the final exact verification still applies.
 *
 * System: unknowns are the basic columns x_j and basic row activities r_i; for every LP row i:
 *   r_i − Σ_j A_ij x_j = 0   with nonbasic columns/rows fixed at their bound values.
 */
import type { Dec } from '@fl/engine';
import { costObjective, lpRows, renderLp, type ExtraRow, type LpRow, type Model, type Term } from './build';
import { solveLp, type LpSolution } from './highs';
import { D, dec, fromDouble } from './util';

export interface ExactSolve {
  sol: LpSolution;
  /** Vertex per model variable. Exact when `exact`, otherwise the solver's printed doubles. */
  values: Dec[];
  exact: boolean;
}

function boundValue(status: string | undefined, lower: Dec | null, upper: Dec | null): Dec | null {
  if (status === 'UB') {
    return upper ?? lower;
  }
  return lower ?? upper; // LB, FX
}

function rowBounds(row: LpRow): { lower: Dec | null; upper: Dec | null } {
  return {
    lower: row.sense === '<=' ? null : row.rhs,
    upper: row.sense === '>=' ? null : row.rhs,
  };
}

/** Solves M u = b exactly (Gaussian elimination, partial pivoting by largest |entry|, first index on ties). */
function gauss(m: Dec[][], b: Dec[]): Dec[] | null {
  const n = b.length;
  const a = m.map((row, i) => [...row, b[i] as Dec]);
  for (let col = 0; col < n; col += 1) {
    let pivot = col;
    for (let r = col + 1; r < n; r += 1) {
      if ((a[r]?.[col] ?? dec('0')).abs().gt((a[pivot]?.[col] ?? dec('0')).abs())) {
        pivot = r;
      }
    }
    const pv = a[pivot]?.[col];
    if (pv === undefined || pv.abs().lt('1e-25')) {
      return null;
    }
    [a[col], a[pivot]] = [a[pivot] as Dec[], a[col] as Dec[]];
    const prow = a[col] as Dec[];
    for (let r = col + 1; r < n; r += 1) {
      const row = a[r] as Dec[];
      const factor = (row[col] as Dec).div(prow[col] as Dec);
      if (!factor.isZero()) {
        for (let c = col; c <= n; c += 1) {
          row[c] = (row[c] as Dec).minus(factor.mul(prow[c] as Dec));
        }
      }
    }
  }
  const u: Dec[] = new Array<Dec>(n);
  for (let r = n - 1; r >= 0; r -= 1) {
    const row = a[r] as Dec[];
    let acc = row[n] as Dec;
    for (let c = r + 1; c < n; c += 1) {
      acc = acc.minus((row[c] as Dec).mul(u[c] as Dec));
    }
    u[r] = acc.div(row[r] as Dec);
  }
  return u;
}

/** Recomputes the vertex of `sol` exactly from its basis. Null when the basis cannot be used. */
export function exactVertex(model: Model, rows: readonly LpRow[], sol: LpSolution): Dec[] | null {
  const nCols = model.variables.length;
  const colBasic = model.variables.map((v) => sol.colStatus.get(v.name) === 'BS');
  const rowBasic = rows.map((r) => sol.rowStatus.get(r.name) === 'BS');
  const basicCount = colBasic.filter(Boolean).length + rowBasic.filter(Boolean).length;
  if (basicCount !== rows.length) {
    return null;
  }
  const x: (Dec | null)[] = model.variables.map((v) => (sol.colStatus.get(v.name) === 'BS' ? null : boundValue(sol.colStatus.get(v.name), v.lower, dec('100'))));
  const unknownIndex = new Map<string, number>();
  let k = 0;
  colBasic.forEach((basic, j) => {
    if (basic) {
      unknownIndex.set(`c${j}`, k);
      k += 1;
    }
  });
  rowBasic.forEach((basic, i) => {
    if (basic) {
      unknownIndex.set(`r${i}`, k);
      k += 1;
    }
  });
  const matrix: Dec[][] = rows.map(() => new Array<Dec>(rows.length).fill(new D(0)));
  const rhs: Dec[] = rows.map(() => new D(0));
  for (let i = 0; i < rows.length; i += 1) {
    const row = rows[i] as LpRow;
    const mi = matrix[i] as Dec[];
    if (rowBasic[i] === true) {
      mi[unknownIndex.get(`r${i}`) as number] = new D(1);
    } else {
      const { lower, upper } = rowBounds(row);
      const fixed = boundValue(sol.rowStatus.get(row.name), lower, upper);
      if (fixed === null) {
        return null;
      }
      rhs[i] = (rhs[i] as Dec).minus(fixed);
    }
    for (const t of row.terms as readonly Term[]) {
      const known = x[t.v];
      if (known === null || known === undefined) {
        const idx = unknownIndex.get(`c${t.v}`);
        if (idx === undefined) {
          return null;
        }
        mi[idx] = (mi[idx] as Dec).minus(t.coef);
      } else {
        rhs[i] = (rhs[i] as Dec).plus(t.coef.mul(known));
      }
    }
  }
  const u = gauss(matrix, rhs);
  if (u === null) {
    return null;
  }
  const out: Dec[] = [];
  for (let j = 0; j < nCols; j += 1) {
    const known = x[j];
    out.push(known !== null && known !== undefined ? known : (u[unknownIndex.get(`c${j}`) as number] as Dec));
  }
  return out;
}

/** Solves an LP over the model and returns the exact vertex (or the printed doubles, flagged). */
export function solveExact(model: Model, objective: readonly Term[], options: { extraRows?: readonly ExtraRow[]; omitRows?: ReadonlySet<number> } = {}): ExactSolve {
  const sol = solveLp(renderLp(model, { objective: [...objective], ...options }));
  if (sol.status !== 'optimal') {
    return { sol, values: [], exact: false };
  }
  const exact = exactVertex(model, lpRows(model, options), sol);
  if (exact !== null) {
    return { sol, values: exact, exact: true };
  }
  return { sol, values: model.variables.map((v) => fromDouble(sol.primal.get(v.name) ?? 0)), exact: false };
}

/** Exact objective value Σ coefᵢ·xᵢ. */
export function objectiveValue(objective: readonly Term[], values: readonly Dec[]): Dec {
  let total = new D(0);
  for (const t of objective) {
    total = total.plus(t.coef.mul(values[t.v] ?? new D(0)));
  }
  return total;
}

export { costObjective };
