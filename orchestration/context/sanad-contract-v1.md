# Sanad ⇄ Formula Lab — HTTP Contract v1

Implemented by: the Sanad PR (`feat/manufacturing-api`, G-03) and Formula Lab's `SanadClient` / inbound controllers (G-04). Background: `plan/02-specs/sanad-integration.md`. Verified against Sanad @ ff39eb2.

## 0. Conventions (both directions)
- JSON only, UTF-8. `Content-Type: application/json`, `Accept: application/json`.
- **Quantities and money are decimal strings** (`"12.500000"`, `"1450.00"`), never JSON numbers. Never floats. Currency is always `EGP` unless a `currency` field says otherwise. Format: `^-?\d+(\.\d+)?$` and JSON type string. Both sides enforce it on requests: Formula Lab refuses floats before sending; Sanad rejects a JSON number (or any non-matching string) in `cost_price`, `sell_price`, `wholesale_price`, `guide_price`, `tax_rate`, `quantity`, `unit_cost` with `422` (custom validation rule `decimal_string`). Where a value must also be non-negative / positive the endpoint says so (e.g. `quantity > 0`, prices `>= 0`).
- Timestamps: ISO-8601 UTC with seconds (`2026-11-21T09:30:00Z`). Dates: `YYYY-MM-DD`.
- IDs: Sanad `uuid` (string UUID). Sanad **branches have no uuid**: a branch is always identified by `branch_code` (string, nullable; `null` = Sanad's main branch). `branch_uuid` does not exist anywhere in this contract. Formula Lab identifiers are sent as `external_id` (string, FL-generated UUID or stable id).
- Errors: `{ "message": "<Arabic text>" }` (like Sanad). Validation (422) adds `"errors": {"field": ["..."]}`. Codes:
  `400` malformed, `401` missing/invalid token (`{"message":"Unauthorized"}` is accepted from Sanad's existing middleware), `403` token lacks ability, `404` unknown route/entity, `409` state conflict (e.g. order already received), `422` validation / idempotency body mismatch / insufficient stock, `429` throttled (respect `Retry-After`), `503` temporarily unavailable (retry).
- Versioned by path (`/v1/`). Additive changes only within v1; unknown JSON fields must be ignored by both sides.

## 1. Auth
- `Authorization: Bearer <token>` on every request.
- Sanad-hosted endpoints (§4): token has abilities `manufacturing:read` (all `GET`) and `manufacturing:write` (all `PUT`/`POST`). Missing ability ⇒ `403 {"message":"الصلاحية غير كافية."}`.
- Formula Lab-hosted endpoints (§2, §3): token issued by Formula Lab per Sanad link/tenant, stored by Sanad as a setting. FL scopes it to one tenant.
- Sanad sends `X-Device-Uuid: <sanad device uuid>` on **every** request it makes to Formula Lab (§2 push, §3.1 outbox GET, §3.2 ack): used for `SyncLog.device_uuid` and cursor ownership. The same value is repeated as `device_uuid` in the push and ack bodies.

## 2. Sanad → Formula Lab: push changes
`POST /api/sanad/v1/changes` (hosted by Formula Lab)

Headers: `Authorization`, `X-Device-Uuid`, **`Idempotency-Key`** (required on every POST/PUT in this contract; any opaque string, 1-255 chars, not necessarily a UUID; see §6).

Request:
```json
{
  "device_uuid": "6f1c…",
  "cursor": "MjAyNi0xMS0yMSAwOTozMDowMHxhYmM",
  "items": [
    {
      "entity": "purchase",
      "uuid": "b7e0…",
      "op": "upsert",
      "updated_at": "2026-11-21T09:30:00Z",
      "payload": { }
    }
  ]
}
```
- `entity` ∈ `product | supplier | customer | purchase | batch | stock_movement` (`customer` added by §8: merchants and the uuid of their price list). `purchase` payloads embed their items (see §5). `op` ∈ `upsert | delete` (delete = tombstone, payload `{"deleted_at": "..."}` only).
- `cursor`: opaque, produced by Sanad for the **last item** of the batch (`base64url("Y-m-d H:i:s|uuid")`, same scheme as `StoreCatalogService::cursor`, ordering `updated_at, uuid`). Max 200 items/batch (matches Sanad PAGE_SIZE spirit; FL may reject larger with 422).
- **Push cursors are per entity.** Sanad keeps one cursor for each entity (`product`, `supplier`, `purchase`, `batch`, `stock_movement`) and each request carries items of **one entity only**; the request's `cursor` is that entity's cursor after the batch. A cursor of one entity is never valid for another. Cursors advance only after a `2xx`.
- Items must be ordered by `(updated_at, uuid)` ascending; parents before children is NOT required (purchase embeds items; unknown `supplier_uuid`/`product_uuid` are accepted and resolved lazily).

Response `200`:
```json
{
  "accepted": 198,
  "cursor": "MjAyNi0xMS0yMSAwOTozMDowMHxhYmM",
  "conflicts": [
    {
      "entity": "product",
      "uuid": "c1d2…",
      "reason": "formula_lab_newer",
      "local_updated_at": "2026-11-21T10:00:00Z",
      "central_updated_at": "2026-11-21T09:30:00Z",
      "local_payload": { },
      "central_payload": { }
    }
  ]
}
```
- `accepted` = items applied or already-identical (no-op). `conflicts[]` items are **not** applied; Sanad records each in `SyncConflict` (`table_name = 'formula_lab.<entity>'`, `direction='push'`, `status='pending'`) and logs the run in `SyncLog`. `cursor` advances past conflicted items (they live in SyncConflict now).
- `reason` ∈ `formula_lab_newer | invalid_reference | schema_mismatch`.
- **Conflict naming (read carefully; the two sides name things from their own point of view).** In Formula Lab's `/changes` response, `local_*` = **Formula Lab's** copy (what FL already holds) and `central_*` = the **incoming Sanad** copy (what Sanad just sent). Sanad's `SyncConflict` uses its own point of view, so when recording a push conflict Sanad **swaps** them: `SyncConflict.local_payload` / `local_updated_at` = **Sanad's** copy (response `central_*`), and `SyncConflict.central_payload` / `central_updated_at` = **Formula Lab's** copy (response `local_*`, plus `conflict_reason`).
- `skipped[]` (additive): items whose `entity` Formula Lab does not know are not applied and are listed as `{entity, uuid, reason: "unknown_entity"}`; they never cause a 4xx/5xx and do not count in `accepted`. Sanad pushes one entity per request (supplier, customer, product, purchase, batch, stock_movement); Formula Lab stores `batch` as a reference only and accepts `stock_movement` without storing it until the actual-cost phase (Q).
- Partial failure of the whole request ⇒ non-2xx and **nothing** applied (the batch is atomic); Sanad retries with the same `Idempotency-Key`.

## 3. Sanad pulls from Formula Lab
### 3.1 `GET /api/sanad/v1/outbox?cursor=<opaque>&limit=100`
Returns pending work for this link, ordered by an increasing opaque cursor.
```json
{
  "data": [
    {
      "id": "ob_01J…",
      "type": "production_order",
      "external_id": "po-2026-000123",
      "produced_at": "2026-11-21T09:30:00Z",
      "payload": { }
    }
  ],
  "next_cursor": "ob_01J…",
  "has_more": false
}
```
`type` ∈ `production_order | product_upsert | wholesale_prices | price_list_upsert` (`price_list_upsert`: §8). Every item also carries `produced_at` (ISO-8601 UTC, set when Formula Lab enqueued it; additive field) which Sanad uses to detect a local price edit made after the suggestion was produced. Items stay in the outbox until acked; re-reading is safe. A `failed` ack re-queues the item as a new outbox row (new `id`, `retry_of` = old id), at most 5 failures per item (§3.2).

Payloads:
- **production_order** (approved only; immutable once published — corrections are a new `external_id`):
```json
{
  "external_id": "po-2026-000123",
  "formula_version": {"formula_id": "…", "version": 4, "hash": "sha256…"},
  "branch_code": "FACT",
  "planned_on": "2026-11-25",
  "finished_good_uuid": "…",
  "batch_number": "B-261125-01",
  "quantity": "500.000000",
  "uom": "unit",
  "production_date": "2026-11-25",
  "expiry_date": "2028-11-24",
  "consume": [
    {"product_uuid": "…", "quantity": "62.500000", "uom": "kg", "kind": "raw_material"},
    {"product_uuid": "…", "quantity": "505.000000", "uom": "unit", "kind": "packaging"}
  ],
  "receive": {"quantity": "500.000000", "uom": "unit", "unit_cost": "18.457300"}
}
```
`unit_cost` is the engine-computed cost per finished unit (deterministic; carries `calc_run_id` for audit). `branch_code` is a string or `null` (`null` = Sanad's main branch); an unknown code fails the item with a `failed` ack. Sanad's link service also reads the legacy key `branch` when `branch_code` is absent.
- **product_upsert**: Sanad `Product` upsert body of §4.2 (`PUT products/{uuid}`) minus path uuid, plus `uuid`. The body's `item_kind` says what it is: used for finished goods and for publishing raw materials / packaging to Sanad. (Renamed from `finished_good`; Sanad still accepts `finished_good` as a legacy alias of `product_upsert`.)
- **wholesale_prices**: body of §4.5 plus `product_uuid`. These are *suggestions*; Sanad applies them (policy is Sanad's).

### 3.2 `POST /api/sanad/v1/outbox/ack`
Headers include `Idempotency-Key`. Request:
```json
{
  "device_uuid": "6f1c…",
  "acks": [
    {"id": "ob_01J…", "status": "applied", "result": {"consume_status": "consumed", "receive_status": "received", "product_batch_uuid": "…"}},
    {"id": "ob_01K…", "status": "failed", "error": {"message": "الرصيد غير كافٍ للخامة X.", "code": "insufficient_stock"}}
  ]
}
```
`status` ∈ `applied | failed | conflict`. Response `200 {"acked": 2}`. Unknown ids ⇒ ignored (counted in `"ignored": n`).

**`failed` semantics.** An ack is final for the row it names. For a `failed` ack Formula Lab marks the row failed and **re-queues the item as a new outbox row** (new `id`, so a new position after Sanad's cursor), at most 5 times in total; after the 5th failure FL stops re-queuing, keeps the row flagged for human review and raises an alert. `conflict` is not re-queued (a person decides). **Sanad always advances its pull cursor after a successful (`2xx`) ack, regardless of the statuses inside it** (failed items come back under new ids, so the old cursor position is never revisited). If the ack request itself fails, Sanad does not advance the cursor and re-reads the same page; re-applying is a no-op by domain idempotency.

## 4. Sanad local endpoints (LAN / direct mode — same payloads)
Hosted by Sanad Desktop under `/api/v1/manufacturing/*`. Auth §1. Rate limit `throttle:120,1`. These are called by Formula Lab's `SanadClient` only when it can reach Sanad on the LAN; the Sanad-initiated link (§2/§3) is the default.

### 4.1 Reads (`manufacturing:read`)
`GET /api/v1/manufacturing/{products|purchases|suppliers|customers|stock}?updated_since=<iso>&cursor=<opaque>&kind=<item_kind>`
```json
{ "data": [ { } ], "next_cursor": "opaque-or-null" }
```
Ordering `(updated_at, uuid)`, page size 100 (+1 lookahead, same as `StoreCatalogService`). Tombstones `{"uuid": "…", "deleted": true, "deleted_at": "…"}`. Items use the §5 payload shapes. `products` supports `kind` filter (`raw_material|packaging|finished_good|merchandise`); `stock` rows: `{"product_uuid","branch_code","quantity","uom","updated_at","batches":[…]}` (quantity = base-uom decimal string; `branch_code` string or `null`). `stock` also accepts `?branch=<branch_code>` to filter. Each `batches[]` entry (additive; only batches with remaining quantity > 0, earliest expiry first, i.e. FEFO order): `{"batch_uuid","batch_number","remaining_quantity","cost_price","production_date","expiry_date","status"}` (`remaining_quantity` in base uom as a 6-dp string, `cost_price` 2-dp string, dates `YYYY-MM-DD` or `null`). Purchases (§5) carry `branch_code` like stock rows and movements do.

### 4.2 `PUT /api/v1/manufacturing/products/{uuid}` (`manufacturing:write`, idempotent)
Upsert a finished good (or raw material/packaging) by uuid.
```json
{
  "name": "شامبو فاخر 400 مل",
  "name_en": "Premium Shampoo 400ml",
  "sku": "FG-SHP-400",
  "barcode": "6221234567890",
  "item_kind": "finished_good",
  "is_sellable": true,
  "uom": "unit",
  "cost_price": "18.4573",
  "sell_price": "39.00",
  "wholesale_price": "31.00",
  "guide_price": "45.00",
  "tax_rate": "14.00",
  "external_id": "sku-0001"
}
```
All money/rate fields are decimal strings (§0): a JSON number in `cost_price`, `sell_price`, `wholesale_price`, `guide_price` or `tax_rate` is `422`.

`tax_rate` (Formula Lab proposal, to be confirmed by Sanad, see HUMAN-TODO TAX): `null` means tax is disabled on the product (no tax line), `"0.00"` means taxed at 0 %, any other value is the rate.

Response `200` (existing) or `201` (created): `{"uuid": "…", "item_kind": "finished_good", "is_sellable": true, "updated_at": "…"}`. Sanad rounds money to its storage precision (currently 2 dp) and returns stored values; FL keeps the unrounded engine value. `item_kind` is immutable once the product has stock movements (⇒ 422).

### 4.3 `POST /api/v1/manufacturing/production-orders/{external_id}/consume`
Consumes materials (FEFO batches via `StockService::recordMovement`, movement type `production_consume`, reference = external_id).
```json
{
  "branch_code": "FACT",
  "consumed_on": "2026-11-25",
  "lines": [
    {"product_uuid": "…", "quantity": "62.500000", "uom": "kg"}
  ]
}
```
`branch_code` (string, nullable, max 100) may also be set per line (`lines[].branch_code`, overrides the top-level one); omitted/`null` = Sanad's main branch. Sanad still accepts the legacy key `branch` as an alias of `branch_code` for now (same request: the replay/conflict hash does not tell them apart). `quantity` must be a decimal string `> 0`.

Response `200`:
```json
{
  "external_id": "po-2026-000123",
  "status": "consumed",
  "lines": [
    {"product_uuid": "…", "quantity": "62.500000", "unit_cost": "41.20", "total_cost": "2575.00",
     "batches": [{"batch_uuid": "…", "batch_number": "L-7731", "quantity": "62.500000"}]}
  ],
  "total_cost": "2575.00"
}
```
`422` with `{"message": "...", "errors": {"lines.0.quantity": ["الرصيد غير كافٍ"]}, "shortfalls":[{"product_uuid":"…","required":"62.5","available":"40.0"}]}` if any line is short — all-or-nothing (no partial consumption). `409` if already consumed with a different body.

### 4.4 `POST /api/v1/manufacturing/production-orders/{external_id}/receive`
Receives finished goods as a new `ProductBatch` + `StockMovement` type `production_receive`.
```json
{
  "branch_code": "FACT",
  "finished_good_uuid": "…",
  "batch_number": "B-261125-01",
  "quantity": "500.000000",
  "uom": "unit",
  "unit_cost": "18.457300",
  "production_date": "2026-11-25",
  "expiry_date": "2028-11-24"
}
```
`branch_code` as in §4.3 (legacy `branch` accepted as an alias). `quantity` is a decimal string `> 0`, `unit_cost` a decimal string `>= 0`.

Response `200`: `{"external_id": "…", "status": "received", "product_batch_uuid": "…", "stock_movement_uuid": "…", "quantity": "500.000000"}`. `422` if `finished_good_uuid` is not `item_kind=finished_good`. `409` if the order was not consumed first.

### 4.5 `PUT /api/v1/manufacturing/products/{uuid}/wholesale-prices` (idempotent)
```json
{
  "wholesale_price": "31.00",
  "guide_price": "45.00",
  "sell_price": "39.00",
  "tier": "distributors"
}
```
Each price is a decimal string `>= 0` (JSON numbers ⇒ `422`). Writes `Product.wholesale_price/guide_price/sell_price` (Sanad `WholesaleTier` is a global percent discount, not per-product; `tier` is informational). Fields omitted are unchanged. Response `200`: `{"uuid":"…","wholesale_price":"31.00","guide_price":"45.00","sell_price":"39.00","updated_at":"…"}`.

## 5. Entity payloads (used in §2 `payload` and §4.1 `data[]`)
All decimals are strings; `*_uuid` replace Sanad integer FKs, except branches, which are identified by `branch_code` (Sanad branches have no uuid).
```json
// product
{"uuid":"","name":"","name_en":null,"sku":null,"barcode":null,"item_kind":"raw_material","is_sellable":false,
 "uom":{"uuid":null,"name":"كجم","base":true},"units":[{"uuid":"","uom_name":"جركن 25 كجم","conversion_factor":"25.000000","is_purchase_default":true}],
 "cost_price":"41.20","sell_price":"0.00","wholesale_price":null,"guide_price":null,"tax_rate":"14.00","stock":"120.000000","status":"published","updated_at":""}
// supplier
{"uuid":"","code":null,"name":"","name_en":null,"tax_number":null,"payment_terms":30,"is_active":true,"updated_at":""}
// customer: see §8.5 (pricing fields only)
// purchase (items embedded)
{"uuid":"","invoice_number":"","reference_number":null,"supplier_uuid":"","purchase_date":"2026-11-20","status":"received",
 "subtotal":"2575.00","tax_amount":"360.50","discount_amount":"0.00","total":"2935.50","branch_code":null,"updated_at":"",
 "items":[{"uuid":"","product_uuid":"","uom_name":"جركن 25 كجم","quantity":"2.00","base_quantity":"50.00","unit_cost":"1287.50",
           "discount_amount":"0.00","tax_rate":"14.00","tax_amount":"360.50","subtotal":"2575.00","received_quantity":"2.00",
           "batch_number":"L-7731","production_date":null,"expiry_date":"2028-06-30"}]}
// batch
{"uuid":"","batch_number":"","product_uuid":"","purchase_uuid":null,"supplier_uuid":null,"cost_price":"41.20",
 "initial_quantity":"50.000000","remaining_quantity":"50.000000","base_quantity":"50.000000","remaining_base_quantity":"50.000000",
 "expiry_date":null,"production_date":null,"status":"active","updated_at":""}
// stock_movement
{"uuid":"","product_uuid":"","batch_uuid":null,"branch_code":null,"type":"production_consume","quantity":"-62.50","unit_cost":"41.20",
 "reference_type":"production_order","reference_id":"po-2026-000123","created_at":"","updated_at":""}
```
Field provenance (Sanad columns): Product `app/Models/Product.php:75`, Purchase `:18`, PurchaseItem `:13`, Supplier `:27`, ProductBatch `:36`, StockMovement `:14`.

## 6. Idempotency (Sanad PR: middleware + `api_idempotency_keys`; FL implements the same for §2/§3)
- Required header `Idempotency-Key` on all non-GET requests. Missing, empty or longer than 255 chars ⇒ `400 {"message":"..."}`. The key is **any opaque string of 1-255 characters**; it need not be a UUID and neither side may parse it. The recommended deterministic key is the **sha256 (hex) of the request payload** (Sanad: of the pushed `items` / of the `acks`; Formula Lab's `SanadClient`: of method + path + body), so a retry of the same batch automatically reuses the same key.
- Key scope: `(token/tenant, method, path, key)`; retained 7 days at minimum.
- Store: request body hash (sha256 of canonical JSON), response status + body.
- **Replay** (same key, same body hash): return the stored status + body unchanged with header `Idempotent-Replay: true`. No side effects re-run.
- **Same key, different body**: `422 {"message":"مفتاح التكرار مستخدم مع بيانات مختلفة."}`.
- Concurrent in-flight with same key: `409 {"message":"الطلب قيد التنفيذ."}` + `Retry-After: 1`.
- `5xx` responses are not stored (the key can be retried); `4xx` validation responses are stored.
- Domain-level idempotency on top: `consume`/`receive` are also keyed by `{external_id}` — a repeat with the same body is a no-op returning the original result.

## 7. Retries, ordering, limits
- Client retries `429/503/network` with exponential backoff (1s, 2s, 4s … cap 60s, max 8 tries), reusing the same `Idempotency-Key`.
- Cursors advance only after a `2xx` (push cursors are per entity, see §2; the pull cursor advances after a successful ack, see §3.2). Both sides must tolerate duplicate delivery.
- Max request body 2 MB; max 200 `items` per push, 100 per outbox page.

## 8. Price lists (price per product and per merchant) — delta, Sanad `feat/customer-price-lists`
Formula Lab publishes the final manufactured product (§3.1 `product_upsert`, `item_kind: finished_good`) **and** prices per product and per merchant. A merchant is a Sanad **customer**; a **price list** is a named set of per-product prices; a customer points at **at most one** list. Everything below follows §0 (decimal strings, ISO timestamps, Arabic `{message}` errors, `Idempotency-Key` on every `PUT`/`POST`, additive within v1).

### 8.1 Sale price precedence (Sanad side, informational)
Per sale line, first match wins: **manual price on the invoice > the customer's price-list item > wholesale price (cost plus / wholesale plus / catalog wholesale) > product sell price**. A list applies only when it is `is_active`, the customer is on it and the product has an item on it; otherwise pricing falls through unchanged. The list price is a **base-unit** price: for a sold unit whose list price differs from the product sell price Sanad scales it by the same ratio it uses for the wholesale price. Sale lines priced from a list are stamped `pricing_mode = "price_list"`. Formula Lab never sets prices on sale lines; it only maintains lists.

### 8.2 `PUT /api/v1/manufacturing/price-lists/{uuid}` (`manufacturing:write`, idempotent)
Upsert a list by uuid (FL generates and owns the uuid), optionally with item rows.
```json
{
  "name": "تجار القاهرة",
  "currency": "EGP",
  "is_active": true,
  "items": [
    {"product_uuid": "…", "price": "31.00"},
    {"product_uuid": "…", "price": null}
  ]
}
```
- `name` required (max 255). `currency` optional, **only `EGP` is accepted** (anything else ⇒ `422`; Sanad has no FX). `is_active` optional (default `true` on create, unchanged on update). `items` optional, **max 500 rows per request**.
- Item row: `product_uuid` (Sanad product uuid, unique within the request) and `price`, which **must be present**: a decimal string **> 0** upserts the price; `null` removes the product from the list. A JSON number, `"0"`, a negative or a non-decimal string ⇒ `422` (`items.N.price`). Sanad rounds to 2 dp and returns the stored value.
- Unknown / trashed `product_uuid` ⇒ `422` with `errors."items.N.product_uuid"`; the request is **atomic** (nothing applied, the list is not created).
- Omitted `items` leaves existing items alone; **items not mentioned are never deleted**. To drop a product send `price: null`. (Publish a full snapshot by sending every item and `null` for the ones to drop.)
- Response `201` (created) / `200`:
```json
{"uuid":"…","name":"تجار القاهرة","currency":"EGP","is_active":true,"created":true,
 "items":[{"product_uuid":"…","price":"31.00"}],"removed":["…"],"items_count":12,"updated_at":"2026-11-21T09:30:00Z"}
```
`items` / `removed` echo only the rows of this request (stored values); `items_count` is the list's total after the request.

### 8.3 `PUT /api/v1/manufacturing/price-lists/{uuid}/items` (`manufacturing:write`, idempotent)
Same item semantics as §8.2, items only: body `{"items": [{"product_uuid","price"}…]}` (`items` required, 1-500 rows). Unknown list uuid ⇒ `404 {"message":"قائمة الأسعار غير موجودة."}`. Response `200`: same shape as §8.2 with `created: false`.

### 8.4 `PUT /api/v1/manufacturing/customers/{uuid}/price-list` (`manufacturing:write`, idempotent)
Assign a merchant to a list, or detach it. `price_list_uuid` **must be present**: a list uuid assigns, `null` detaches.
```json
{"price_list_uuid": "…"}
```
Response `200` = the customer payload of §8.5 (with the new `price_list_uuid`). Errors: unknown customer uuid ⇒ `404 {"message":"العميل غير موجود."}`; unknown list uuid ⇒ `422` (`errors.price_list_uuid`); **inactive list** ⇒ `422` (`errors.price_list_uuid`); missing key ⇒ `422`. Assigning a customer to its current list is a no-op.

### 8.5 Customers feed — `GET /api/v1/manufacturing/customers?updated_since=<iso>&cursor=<opaque>` (`manufacturing:read`)
Same envelope and paging as §4.1 (`{data, next_cursor}`, order `(updated_at, uuid)`, page size 100, +1 lookahead, opaque cursor). Archived customers are tombstones `{"uuid","deleted":true,"deleted_at"}`. A customer row carries **only what pricing needs** (no balances, phones, addresses):
```json
{"uuid":"…","code":"CLT-0001","name":"تاجر الجملة","name_en":null,"is_active":true,"is_wholesale":false,
 "price_list_uuid":"…","updated_at":"2026-11-21T09:30:00Z"}
```
`price_list_uuid` is `null` when the customer has no list. A customer permanently deleted in Sanad (not archived) leaves no tombstone.

### 8.6 Sanad-initiated link
- **Push (§2)**: new `entity: "customer"` (own per-entity cursor), payload exactly the §8.5 row. Customers that lose their list or are archived arrive as normal `upsert` / `delete` ops. Formula Lab that does not know `customer` answers `skipped[]` (`unknown_entity`); that is not an error.
- **Pull (§3.1)**: new outbox `type: "price_list_upsert"`. Payload (every item carries `produced_at` as usual):
```json
{
  "uuid": "…", "name": "تجار القاهرة", "currency": "EGP", "is_active": true,
  "items": [{"product_uuid": "…", "price": "31.00"}, {"product_uuid": "…", "price": null}],
  "assign_customers": ["<customer uuid>"],
  "unassign_customers": ["<customer uuid>"]
}
```
`uuid` + `name` required; `items` rules as §8.2 (atomic; `price: null` removes). `assign_customers` points those customers at this list (unknown customer ⇒ whole item `failed`, code `unknown_customer`; an inactive list cannot receive customers). `unassign_customers` detaches only customers currently on **this** list. All three of `items`, `assign_customers`, `unassign_customers` are optional.
- **Ack** (§3.2): `applied` with `result {"uuid","created","items_upserted","items_removed","customers_assigned"}`; `failed` with `error.code` ∈ `invalid_payload` (shape/currency/price format) | `validation` (unknown `product_uuid`) | `unknown_customer`; `conflict` with `result {"reason":"local_price_list_changed"}`.
- **Conflict**: if a person edited the list in Sanad (UI) **after** the item's `produced_at`, Sanad does not apply the item, records a `SyncConflict` (`table_name = formula_lab.price_list_upsert`, `direction = pull`; `local_payload` = Sanad's current list with its items, `central_payload` = the received payload) and acks `conflict` (not re-queued, a person decides — same rule as `product_upsert` / `wholesale_prices`). Writes made by Formula Lab itself (API or link) never count as local edits. Customer assignments are last-write-wins and never conflict.
- Idempotency is domain-level: re-applying the same payload is a no-op (same list uuid, same prices).

### 8.7 Notes for Formula Lab
- FL owns list uuids; create a list once with §8.2, then address it by uuid forever. Sanad also lets people create lists by hand: FL only sees those as `price_list_uuid` on customers (they have no feed of their own) and must not assume every `price_list_uuid` it receives is one it created.
- A list must be `is_active` for sales to use it; deactivate with `PUT … {"name","is_active":false}` (customers stay assigned but are priced as if they had no list).
- Prices are plain per-product base-unit prices. Nothing in this section changes `sell_price` / `wholesale_price` / `guide_price` (§4.2 / §4.5 stay as they are).
