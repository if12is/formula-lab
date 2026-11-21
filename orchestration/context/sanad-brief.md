# Sanad brief (wasef201/sanad @ ff39eb2, v1.2.68) — for Formula Lab integration

## Headline findings
1. Sanad's existing sync is DB-to-DB (Electron SQLite ⇄ tenant MySQL hub via `app/Services/SyncService.php`), NOT an HTTP API. Outbox exists at DB level (`sync_outbox` table filled by triggers + `devices.outbox_cursor`). There is no HTTP push/pull protocol to extend ⇒ the Formula Lab link must be built new, reusing the concepts (outbox/cursor, SyncLog, SyncConflict).
2. `/api/v1/store/*` is portal-only (`ResolveStoreTenant` 404s on desktop). The factory desktop POS does not serve it.
3. `item_kind` / `is_sellable` do not exist. Only `is_service` (bool) and `product_type` (free string).
4. No manufacturing / production / BOM / idempotency code exists.

## Conventions (sanad/AGENTS.md)
- Route → FormRequest → Controller → Service → Action → Model. JS only (no TS), Tailwind 3. Laravel 12, PHP ≥8.2.
- Curly braces, constructor promotion, explicit types, PHPDoc array shapes; follow sibling files (models use `$casts` property).
- Tests: **PHPUnit 11 classes only (no Pest)**, `extends Tests\TestCase`, `use RefreshDatabase`, `test_snake_case` methods, setUp `config(['app.installed'=>true,'app.activated'=>true])`, `getJson/postJson` + `assertJsonPath`. sqlite :memory:. Run `php artisan test --compact <file>`; `vendor/bin/pint --dirty --format agent` after PHP edits.
- No new base folders / dependency changes without approval; no tag/release. Commit style: plain English sentences.
- New migrations guarded with `Schema::hasColumn` like siblings (e.g. `2026_09_16_005447`). Widening `stock_movements.type`: copy `2026_11_14_100200_widen_stock_movements_type_for_sale_edit_restore.php` (sqlite + mysql/pgsql branches).
- Adding columns to products affects sync schema alignment tests (`tests/Feature/Sync/SchemaAlign*`, `ForeignKeyRemapCoverageTest`, `SyncDependencyGraphTest`) — plain columns are safe, new FKs need mapping.

## API today (routes/api.php)
- `v1/store/*` middleware store.tenant + store.token (`X-Sanad-Activation` + Bearer `Setting store_api_token`, hash_equals) — portal only.
- `v1` + `VerifyApiToken` (static `SANAD_API_TOKEN`): `GET v1/sync/status`, `POST v1/sync/trigger`, `GET v1/online-orders/pending`.
- `/api/mobile/*` Sanctum + `X-Device-Uuid`: products, suppliers, purchases, sales… (paginators, `{success:true, <key>: paginator}`).
- Errors: `{message}` Arabic, 401/404/422/503. No Idempotency-Key anywhere; store orders dedupe on `external_reference`.
- Cursor precedent: `StoreCatalogService::products(updatedSince, cursor)` orders by `updated_at, uuid`, PAGE_SIZE+1 lookahead, opaque cursor, tombstones `{uuid, deleted:true}`.

## Models
- Product (`app/Models/Product.php`): name, name_en, slug, barcode, sku, cost_price, sell_price, wholesale_price, guide_price, tax_rate, stock, unit, uom_id, category_id, status, is_service, product_type, uuid, soft deletes. Units: ProductUnit (conversion_factor), Uom. Batches: product_batches (batch_number, cost_price, remaining_quantity, expiry_date, production_date, status).
- Purchase (invoice_number, supplier_id, purchase_date, status draft|confirmed|received|partial|cancelled, subtotal, tax_amount, discount_amount, total, branch_id); PurchaseItem (product_id, uom_id, product_unit_id, quantity, base_quantity, unit_cost, tax/discount, subtotal, batch_number, expiry/production_date, received_quantity, uuid). Supplier, SupplierLedgerEntry.
- StockMovement types: purchase, sale, sale_return, purchase_return, transfer_in/out, adjustment_in/out, opening, … — no production types. Writer: `app/Services/StockService.php` (`recordMovement`), `BatchService`.
- WholesaleTier = global percent discount (not per-product prices); products have wholesale_price/guide_price.
- SyncLog (device_uuid, direction push|pull, table_name, records_count, status success|failed|conflict, error_message, started_at, finished_at). SyncConflict (record_uuid, table_name, device_uuid, local_payload, central_payload, direction pull|push, status pending|resolved_local|resolved_central|dismissed, resolved_by) + `SyncConflictService`.

## Gaps to build in Sanad (branch feat/manufacturing-api, PR, no merge)
1. Additive migration: products.item_kind (default merchandise) + is_sellable (default true); enforce unsellable in POS search (`app/Support/ProductSearch.php`), store catalog, mobile products, checkout, StoreOrderService; report filter by kind.
2. Idempotency middleware + `api_idempotency_keys` table (replay stored response, `Idempotent-Replay: true`; same key different body ⇒ 422).
3. Manufacturing token with abilities (manufacturing:read / manufacturing:write) — Sanctum or setting-based bearer.
4. Endpoints (LAN/direct mode, same contract as push): `GET v1/manufacturing/products|purchases|suppliers|stock` (cursor updated_at,uuid), `PUT v1/manufacturing/products/{uuid}`, `POST v1/manufacturing/production-orders/{external_id}/consume|receive` (StockService + new types production_consume/production_receive, FEFO consumption, 422 on insufficient stock), `PUT v1/manufacturing/products/{uuid}/wholesale-prices`.
5. Outbound "Formula Lab link" client in Sanad (Sanad initiates): push changes (products/purchases/suppliers/stock) with Idempotency-Key, pull approved production orders / finished goods / prices; own cursor; logs to SyncLog (table_name `formula_lab.*`); collisions to SyncConflict. Webhooks are replaced by this push.
