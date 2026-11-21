# ADR 001: One shared TypeScript engine with Decimal arithmetic

- Status: Accepted (2026-11-21, owner decision recorded in CLAUDE.md)
- Task: B-03

## Context
Every number shown to a factory (formula percentages, cost, tier prices, compliance) must be reproducible and auditable. The same calculation must run in the browser, Electron (offline), Node/server and Capacitor. IEEE floats and duplicated formula code in several languages cause silent drift.

## Decision
1. The engine is a single pure TypeScript package, `packages/engine`, used unchanged in browser, Electron, Node and Capacitor.
2. Arithmetic uses decimal.js with precision 34 and `ROUND_HALF_EVEN` internally. No `number` arithmetic on quantities or money.
3. Rounding happens once, at display or print time, per `plan/02-specs/rounding-policy.md`. Totals are computed from unrounded values.
4. Numbers cross every JSON boundary as strings.
5. The engine does no IO and uses no `Date.now()` or random. All data, including dates and prices, arrives in the input.
6. Missing data never becomes zero or a default. Functions return `Result<T, MissingData[]>`; the calculation stops and reports `missing[]`.
7. Every input value is a `SourcedValue` carrying `sourceId`, `confidence` (L1 to L7) and `validFrom`. Policy decides which confidence levels warn or block (see `plan/02-specs/engine-contract.md`).
8. `inputHash` and `outputHash` are SHA-256 over canonical JSON (sorted keys, no whitespace, strings for numbers). Same input hash must give the same output, byte for byte.
9. Every output carries `engineVersion` (pinned semver) and `datasetVersion` (ADR 003).
10. Laravel never re-implements formulas. Server-side calculation runs the same engine through a Node sidecar (a long-lived Node process, or a CLI fallback, called by Laravel with the input JSON and returning the output JSON). When a client computed a result offline, the server may re-run the sidecar and compare `outputHash`; a mismatch is rejected.

### Why a Node sidecar
Alternatives for server-side use are porting to PHP (rejected, two truths), running a JS engine inside PHP (fragile), or trusting client results (unverifiable). A sidecar runs the identical package and version, is stateless and easy to pin and test, and keeps Laravel as orchestration, persistence and auth only. Hash re-verification is available for offline results but is not the primary path.

## Consequences
- Identical results across all runtimes; golden-file tests in Vitest are the contract.
- Laravel needs a sidecar deployment and a health check; calculation latency includes a local process hop.
- Engine upgrades are versioned; stored results keep the `engineVersion` that produced them and are never silently recomputed.
- Fixtures with hash pinning detect accidental behavior changes.
- Developers cannot "just compute" a number in a controller or Vue component; review rejects it.

## Alternatives rejected
- PHP port or dual implementation: drift and double testing.
- Native JS numbers or `bignumber.js` mixed with floats: floats violate determinism; decimal.js is chosen for rounding mode control.
- Database-side calculation (SQL): untestable in the browser, not offline-capable.
- Defaults or zero for missing values: hides data gaps and produces false costs.
