# ADR 000: Technology stack and monorepo layout

- Status: Accepted (2026-11-21, owner decision recorded in CLAUDE.md)
- Task: B-01

## Context
Formula Lab serves detergent, cosmetics, perfume, air-freshener and moisturizer factories in Egypt. It must give deterministic formulation and costing results, work offline on the factory machine, and avoid duplicating commerce functionality. The owner decided that Formula Lab is formulation-only; everything commercial or operational (suppliers, purchases, inventory, sales, customers, distributors, wholesale prices, POS) lives in Sanad (`wasef201/sanad`) and is reached through its API. All screens must come from Taif Kit (`if12is/taif-kit`).

## Decision

### Stack
| Layer | Choice |
| --- | --- |
| Backend | Laravel 12, PostgreSQL |
| Web UI | Inertia v2 + Vue 3 + Tailwind, components and templates from Taif Kit (`github:if12is/taif-kit`, pinned to a tag, never a branch) |
| Calculation | `packages/engine` (TypeScript, decimal.js), see ADR 001 |
| Optimizer | `packages/optimizer` (HiGHS compiled to WASM), tier LP |
| Desktop (offline) | Electron with SQLite, `apps/desktop` |
| Mobile | Capacitor, `apps/mobile` |
| AI | Claude Haiku through the Anthropic API, called from the server only, see ADR 002 |

### Monorepo layout (pnpm workspaces)
- `packages/engine`, `packages/optimizer`: pure TypeScript, no UI or framework imports.
- `apps/api`: the Laravel app. Because Inertia is a monolith, the web UI lives inside it at `apps/api/resources/js` and is built by the Laravel Vite pipeline. There is no separate SPA build, and `apps/web` is NOT created.
- `apps/desktop` (Electron) and `apps/mobile` (Capacitor): placeholders until S-09 and S-10.
- `docs/adr`: decision records. `plan/`: specs and phases.

### Sanad integration
Sanad is the source of truth for all POS and commerce data. Formula Lab keeps no parallel tables for them and links by Sanad's `external_id`. Details are in `plan/02-specs/sanad-integration.md`; the decisions that matter here:
- The Sanad POS on the factory machine is local-first and usually behind NAT, so it initiates the connection (outbound).
- Push from Sanad: purchases, items and balances, via outbox plus cursor.
- Pull by Sanad: approved production orders (BOM), finished products with computed unit cost and suggested price, wholesale prices.
- Every message carries an `Idempotency-Key`; conflicts use Sanad's existing `SyncConflict` mechanism.
- Formula Lab sends to Sanad only: the finished product, calculated unit cost, suggested price, and the approved production order. Sanad executes inventory movements and sales.
- Direct LAN connection is an allowed alternative for the Electron edition, with the same contract.

### Testing
- Vitest: engine and optimizer.
- Pest: Laravel.
- Playwright: every screen and user journey.
Nothing counts as working without tests.

### Language
UI is Arabic RTL by default. Code, comments, commits and docs are English.

## Consequences
- One calculation implementation (TypeScript) serves browser, Electron, Node and Capacitor.
- A single deployable web app; no CORS or token plumbing between a SPA and the API.
- Taif Kit becomes a hard dependency; missing components are contributed to the kit, not built ad hoc.
- Formula Lab depends on Sanad endpoints that may not exist yet (task G-03); integration work is gated on that.
- Offline editions need a sync path, handled through the same Sanad-style outbox/cursor pattern.
- Supplier portal and network (phase R) are deferred until the core is done.

## Alternatives rejected
- Separate Vue SPA (`apps/web`) plus API: duplicates routing, auth and build for no gain given Inertia.
- Re-implementing formulas in PHP: two sources of truth for numbers; violates determinism (ADR 001).
- Own commerce tables (purchasing, stock, sales): duplicates Sanad and causes drift.
- Building UI components from scratch: Taif Kit already covers the need.
- Server calling Sanad Central or the POS directly: the POS is behind NAT; an outbound-initiated design avoids inbound exposure.
