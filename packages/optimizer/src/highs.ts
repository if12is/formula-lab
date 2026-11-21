/**
 * J-03: HiGHS (WASM, npm `highs`) wrapper.
 *
 * - Loaded once per process (memoised promise); `solveLp` is synchronous afterwards.
 * - Deterministic options are fixed here, recorded in every output (`solverInfo()`), and never
 *   configurable by callers: fixed random_seed, single thread, presolve on, simplex, no time limit.
 * - HiGHS computes in IEEE doubles, and the npm wrapper hands back its "pretty" solution text, which carries
 *   only ~6 significant digits (33.3333 for 100/3). Nothing from this module is a final answer: exact.ts
 *   takes the BASIS (which columns/rows are basic or at a bound, exact information) and recomputes the
 *   vertex in Decimal; round.ts then rounds and VERIFIES it exactly.
 * - The `highs` wrapper parses HiGHS's printed solution, so `output_flag` must stay on (turning it off
 *   makes solve() throw "Unable to parse solution"). HiGHS log lines are captured by the wrapper.
 *
 * Browser / Electron (later): the loader reads `highs.wasm` next to `highs.js` through fs in Node. In a
 * browser or Electron renderer pass `locateFile` (see `loadHighs`) returning the bundled asset URL
 * (e.g. Vite `?url` import of `highs/build/highs.wasm`). No code here depends on Node APIs.
 */
import highsLoader from 'highs';

/** Exact version pinned in package.json (a test asserts the installed package matches). */
export const HIGHS_PACKAGE_VERSION = '1.8.0';

/** Options handed to HiGHS on every solve. Keys sorted; values are the complete determinism contract. */
export const HIGHS_OPTIONS = {
  parallel: 'off',
  presolve: 'on',
  random_seed: 0,
  simplex_strategy: 1, // dual simplex, serial
  solver: 'simplex',
  threads: 1,
  // time_limit intentionally not set: HiGHS default is no limit (+inf).
} as const;

export interface SolverInfo {
  name: 'highs-wasm';
  packageVersion: string;
  /** Option values as strings (decimal-string rule: no JS numbers in hashed output). */
  options: Record<string, string>;
  timeLimit: 'none';
}

export function solverInfo(): SolverInfo {
  return {
    name: 'highs-wasm',
    packageVersion: HIGHS_PACKAGE_VERSION,
    options: Object.fromEntries(Object.entries(HIGHS_OPTIONS).map(([k, v]) => [k, String(v)])),
    timeLimit: 'none',
  };
}

export type SolveStatus = 'optimal' | 'infeasible' | 'error';

export interface LpSolution {
  status: SolveStatus;
  /** HiGHS model status text, e.g. "Optimal". */
  rawStatus: string;
  /** Primal value per column name (doubles). Only for status optimal. */
  primal: Map<string, number>;
  /** Reduced cost per column name. Only for status optimal. */
  reducedCost: Map<string, number>;
  /** Row dual (shadow price) per row name. Only for status optimal. */
  rowDual: Map<string, number>;
  /** Row activity per row name. Only for status optimal. */
  rowPrimal: Map<string, number>;
  /** Basis status per column name: BS basic, LB / UB / FX at a bound. Only for status optimal. */
  colStatus: Map<string, string>;
  /** Basis status per row name. Only for status optimal. */
  rowStatus: Map<string, string>;
}

type HighsInstance = Awaited<ReturnType<typeof highsLoader>>;
let loading: Promise<HighsInstance> | undefined;
let instance: HighsInstance | undefined;

/** Loads HiGHS once. `locateFile` is only honoured by the first call (browser / Electron wasm location). */
export function loadHighs(locateFile?: (file: string) => string): Promise<void> {
  loading ??= highsLoader(locateFile === undefined ? undefined : { locateFile }).then((h) => {
    instance = h;
    return h;
  });
  return loading.then(() => undefined);
}

/** Solves CPLEX LP text. Requires `await loadHighs()` first. */
export function solveLp(lpText: string): LpSolution {
  if (instance === undefined) {
    throw new Error('HiGHS is not loaded: await loadHighs() before solving');
  }
  const res = instance.solve(lpText, HIGHS_OPTIONS);
  const empty = {
    primal: new Map<string, number>(), reducedCost: new Map<string, number>(), rowDual: new Map<string, number>(),
    rowPrimal: new Map<string, number>(), colStatus: new Map<string, string>(), rowStatus: new Map<string, string>(),
  };
  if (res.Status === 'Infeasible' || res.Status === 'Primal infeasible or unbounded') {
    return { status: 'infeasible', rawStatus: res.Status, ...empty };
  }
  if (res.Status !== 'Optimal') {
    return { status: 'error', rawStatus: res.Status, ...empty };
  }
  const out: LpSolution = { status: 'optimal', rawStatus: res.Status, ...empty };
  for (const col of Object.values(res.Columns)) {
    const c = col as { Name: string; Primal: number; Dual: number; Status: string };
    out.colStatus.set(c.Name, c.Status);
    out.primal.set(c.Name, c.Primal);
    out.reducedCost.set(c.Name, c.Dual);
  }
  for (const row of res.Rows) {
    const r = row as { Name: string; Primal: number; Dual: number; Status: string };
    out.rowStatus.set(r.Name, r.Status);
    out.rowDual.set(r.Name, r.Dual);
    out.rowPrimal.set(r.Name, r.Primal);
  }
  return out;
}
