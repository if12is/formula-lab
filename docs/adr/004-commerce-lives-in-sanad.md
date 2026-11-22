# ADR 004: Commerce lives in Sanad, not in Formula Lab

- Status: Accepted (2026-11-21, owner decision recorded in CLAUDE.md "Scope")
- Task: K-08

## Context
Formula Lab exists for formulas: the deterministic engine, materials data and COAs, formula and pack cost, tiers, compliance, the lab loop. The factory's commerce and operations are already built in Sanad (`wasef201/sanad`): suppliers, purchases, stock and its movements, sales, invoices, customers, distributors, wholesale prices, the cashier, shifts and branches. A second copy here would drift from the first, and the owner's rule is no parallel tables.

## Decision
1. Formula Lab has no sales, invoice, customer, distributor, inventory, stock, supplier, purchase, payment, cashier, shift or branch module: no table, model, controller or screen for any of them.
2. Formula Lab sends Sanad four things: the finished good, its computed unit cost, a suggested price (price lists per customer) and an approved production order (BOM). Sanad executes them on stock and sells them.
3. What may exist here is a cache of Sanad's rows (tables named `sanad_*`, written only from Sanad's own changes) and records that carry a Sanad uuid (`sanad_product_uuid`, `price_list_customers.customer_uuid`). A cache is read, never edited here.
4. Reports about sales, purchases or stock read from Sanad through `app/Integrations/Sanad`; Formula Lab computes none of them.
5. Phase Q is limited to the weighing sheet, the approved production order sent to Sanad, batch traceability linked to the formula and the actual-vs-computed cost comparison. Any warehouse or stock movement is Sanad's.
6. Phase R (supplier network or portal) stays deferred until the core is finished.

## Enforcement
`tests/Feature/Architecture/NoCommerceModuleTest.php` fails when a route, table, model or controller is named for a commerce or stock concept, unless it is a `sanad_*` cache, the Sanad integration API or a local-only mock. A new commerce need is therefore a Sanad task.

## Consequences
- A table that looks tempting (customers, stock) is added to Sanad and cached here only when a formula screen needs to read it.
- `price_lists` and `price_list_customers` stay: they are Formula Lab's pricing output (a suggestion computed from cost) and a link to a Sanad customer uuid, not a customer or sales record.
- The test uses name words, so a commerce table with an innocent name is a review matter, not a test matter.

## Alternatives rejected
- A light local sales or stock module for offline use: two truths for the same stock; the offline edition queues messages to Sanad instead (see the Sanad integration spec).
- Reading Sanad's database directly: bypasses its API contract and its audit.
