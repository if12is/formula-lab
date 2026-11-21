# Sanad ⇄ Formula Lab — HTTP Contract v1

Implemented by: the Sanad PR (`feat/manufacturing-api`, G-03) and Formula Lab's `SanadClient` / inbound controllers (G-04). Background: `plan/02-specs/sanad-integration.md`. Verified against Sanad @ ff39eb2.

## 0. Conventions (both directions)
- JSON only, UTF-8. `Content-Type: application/json`, `Accept: application/json`.
- **Quantities and money are decimal strings** (`"12.500000"`, `"1450.00"`), never JSON numbers. Never floats. Currency is always `EGP` unless a `currency` field says otherwise.
- Timestamps: ISO-8601 UTC with seconds (`2026-11-21T09:30:00Z`). Dates: `YYYY-MM-DD`.
- IDs: Sanad `uuid` (string UUID). Formula Lab identifiers are sent as `external_id` (string, FL-generated UUID or stable id).
- Errors: `{ "message": "<Arabic text>" }` (like Sanad). Validation (422) adds `"errors": {"field": ["..."]}`. Codes:
  `400` malformed, `401` missing/invalid token (`{"message":"Unauthorized"}` is accepted from Sanad's existing middleware), `403` token lacks ability, `404` unknown route/entity, `409` state conflict (e.g. order already received), `422` validation / idempotency body mismatch / insufficient stock, `429` throttled (respect `Retry-After`), `503` temporarily unavailable (retry).
- Versioned by path (`/v1/`). Additive changes only within v1; unknown JSON fields must be ignored by both sides.

## 1. Auth
- `Authorization: Bearer <token>` on every request.
- Sanad-hosted endpoints (§4): token has abilities `manufacturing:read` (all `GET`) and `manufacturing:write` (all `PUT`/`POST`). Missing ability ⇒ `403 {"message":"الصلاحية غير كافية."}`.
- Formula Lab-hosted endpoints (§2, §3): token issued by Formula Lab per Sanad link/tenant, stored by Sanad as a setting. FL scopes it to one tenant.
- Sanad also sends `X-Device-Uuid: <sanad device uuid>` on §2/§3 (used for `SyncLog.device_uuid` and cursor ownership).

## 2. Sanad → Formula Lab: push changes
`POST /api/sanad/v1/changes` (hosted by Formula Lab)

Headers: `Authorization`, `X-Device-Uuid`, **`Idempotency-Key: <uuid>`** (required on every POST/PUT in this contract; max 255 chars).

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
- `entity` ∈ `product | supplier | purchase | batch | stock_movement`. `purchase` payloads embed their items (see §5). `op` ∈ `upsert | delete` (delete = tombstone, payload `{"deleted_at": "..."}` only).
- `cursor`: opaque, produced by Sanad for the **last item** of the batch (`base64url("Y-m-d H:i:s|uuid")`, same scheme as `StoreCatalogService::cursor`, ordering `updated_at, uuid`). Max 200 items/batch (matches Sanad PAGE_SIZE spirit; FL may reject larger with 422).
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
- `reason` ∈ `formula_lab_newer | invalid_reference | schema_mismatch`. In a conflict entry `local_*` describes the copy Formula Lab already holds and `central_*` what Sanad just sent.
- `skipped[]` (additive): items whose `entity` Formula Lab does not know are not applied and are listed as `{entity, uuid, reason: "unknown_entity"}`; they never cause a 4xx/5xx and do not count in `accepted`. Sanad pushes one entity per request (supplier, product, purchase, batch, stock_movement); Formula Lab stores `batch` as a reference only and accepts `stock_movement` without storing it until the actual-cost phase (Q).
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
`type` ∈ `production_order | finished_good | wholesale_prices`. Every item also carries `produced_at` (ISO-8601 UTC, set when Formula Lab enqueued it; additive field) which Sanad uses to detect a local price edit made after the suggestion was produced. Items stay in the outbox until acked; re-reading is safe. A `failed` ack re-queues the item as a new outbox row (new `id`) until 5 failures.

Payloads:
- **production_order** (approved only; immutable once published — corrections are a new `external_id`):
```json
{
  "external_id": "po-2026-000123",
  "formula_version": {"formula_id": "…", "version": 4, "hash": "sha256…"},
  "branch_uuid": "…",
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
`unit_cost` is the engine-computed cost per finished unit (deterministic; carries `calc_run_id` for audit).
- **finished_good**: Sanad `Product` upsert body of §4.2 (`PUT products/{uuid}`) minus path uuid, plus `uuid`.
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
`status` ∈ `applied | failed | conflict`. Response `200 {"acked": 2}`. Unknown ids ⇒ ignored (counted in `"ignored": n`). `failed` items return to the outbox flagged for human review (not auto-retried forever: FL stops after 5 failures and raises an alert).

## 4. Sanad local endpoints (LAN / direct mode — same payloads)
Hosted by Sanad Desktop under `/api/v1/manufacturing/*`. Auth §1. Rate limit `throttle:120,1`. These are called by Formula Lab's `SanadClient` only when it can reach Sanad on the LAN; the Sanad-initiated link (§2/§3) is the default.

### 4.1 Reads (`manufacturing:read`)
`GET /api/v1/manufacturing/{products|purchases|suppliers|stock}?updated_since=<iso>&cursor=<opaque>&kind=<item_kind>`
```json
{ "data": [ { } ], "next_cursor": "opaque-or-null" }
```
Ordering `(updated_at, uuid)`, page size 100 (+1 lookahead, same as `StoreCatalogService`). Tombstones `{"uuid": "…", "deleted": true, "deleted_at": "…"}`. Items use the §5 payload shapes. `products` supports `kind` filter (`raw_material|packaging|finished_good|merchandise`); `stock` rows: `{"product_uuid","branch_uuid","quantity","uom","updated_at"}` (quantity = base-uom decimal string).

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
Response `200` (existing) or `201` (created): `{"uuid": "…", "item_kind": "finished_good", "is_sellable": true, "updated_at": "…"}`. Sanad rounds money to its storage precision (currently 2 dp) and returns stored values; FL keeps the unrounded engine value. `item_kind` is immutable once the product has stock movements (⇒ 422).

### 4.3 `POST /api/v1/manufacturing/production-orders/{external_id}/consume`
Consumes materials (FEFO batches via `StockService::recordMovement`, movement type `production_consume`, reference = external_id).
```json
{
  "branch_uuid": "…",
  "consumed_on": "2026-11-25",
  "lines": [
    {"product_uuid": "…", "quantity": "62.500000", "uom": "kg"}
  ]
}
```
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
  "branch_uuid": "…",
  "finished_good_uuid": "…",
  "batch_number": "B-261125-01",
  "quantity": "500.000000",
  "uom": "unit",
  "unit_cost": "18.457300",
  "production_date": "2026-11-25",
  "expiry_date": "2028-11-24"
}
```
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
Writes `Product.wholesale_price/guide_price/sell_price` (Sanad `WholesaleTier` is a global percent discount, not per-product; `tier` is informational). Fields omitted are unchanged. Response `200`: `{"uuid":"…","wholesale_price":"31.00","guide_price":"45.00","sell_price":"39.00","updated_at":"…"}`.

## 5. Entity payloads (used in §2 `payload` and §4.1 `data[]`)
All decimals are strings; `*_uuid` replace Sanad integer FKs.
```json
// product
{"uuid":"","name":"","name_en":null,"sku":null,"barcode":null,"item_kind":"raw_material","is_sellable":false,
 "uom":{"uuid":null,"name":"كجم","base":true},"units":[{"uuid":"","uom_name":"جركن 25 كجم","conversion_factor":"25.000000","is_purchase_default":true}],
 "cost_price":"41.20","sell_price":"0.00","wholesale_price":null,"guide_price":null,"tax_rate":"14.00","stock":"120.000000","status":"published","updated_at":""}
// supplier
{"uuid":"","code":null,"name":"","name_en":null,"tax_number":null,"payment_terms":30,"is_active":true,"updated_at":""}
// purchase (items embedded)
{"uuid":"","invoice_number":"","reference_number":null,"supplier_uuid":"","purchase_date":"2026-11-20","status":"received",
 "subtotal":"2575.00","tax_amount":"360.50","discount_amount":"0.00","total":"2935.50","branch_uuid":"","updated_at":"",
 "items":[{"uuid":"","product_uuid":"","uom_name":"جركن 25 كجم","quantity":"2.00","base_quantity":"50.00","unit_cost":"1287.50",
           "discount_amount":"0.00","tax_rate":"14.00","tax_amount":"360.50","subtotal":"2575.00","received_quantity":"2.00",
           "batch_number":"L-7731","production_date":null,"expiry_date":"2028-06-30"}]}
// batch
{"uuid":"","batch_number":"","product_uuid":"","purchase_uuid":null,"supplier_uuid":null,"cost_price":"41.20",
 "initial_quantity":"50.000000","remaining_quantity":"50.000000","base_quantity":"50.000000","remaining_base_quantity":"50.000000",
 "expiry_date":null,"production_date":null,"status":"active","updated_at":""}
// stock_movement
{"uuid":"","product_uuid":"","batch_uuid":null,"branch_uuid":"","type":"production_consume","quantity":"-62.50","unit_cost":"41.20",
 "reference_type":"production_order","reference_id":"po-2026-000123","created_at":"","updated_at":""}
```
Field provenance (Sanad columns): Product `app/Models/Product.php:75`, Purchase `:18`, PurchaseItem `:13`, Supplier `:27`, ProductBatch `:36`, StockMovement `:14`.

## 6. Idempotency (Sanad PR: middleware + `api_idempotency_keys`; FL implements the same for §2/§3)
- Required header `Idempotency-Key` on all non-GET requests. Missing ⇒ `400 {"message":"..."}`.
- Key scope: `(token/tenant, method, path, key)`; retained 7 days at minimum.
- Store: request body hash (sha256 of canonical JSON), response status + body.
- **Replay** (same key, same body hash): return the stored status + body unchanged with header `Idempotent-Replay: true`. No side effects re-run.
- **Same key, different body**: `422 {"message":"مفتاح التكرار مستخدم مع بيانات مختلفة."}`.
- Concurrent in-flight with same key: `409 {"message":"الطلب قيد التنفيذ."}` + `Retry-After: 1`.
- `5xx` responses are not stored (the key can be retried); `4xx` validation responses are stored.
- Domain-level idempotency on top: `consume`/`receive` are also keyed by `{external_id}` — a repeat with the same body is a no-op returning the original result.

## 7. Retries, ordering, limits
- Client retries `429/503/network` with exponential backoff (1s, 2s, 4s … cap 60s, max 8 tries), reusing the same `Idempotency-Key`.
- Cursors advance only after a `2xx`. Both sides must tolerate duplicate delivery.
- Max request body 2 MB; max 200 `items` per push, 100 per outbox page.
