-- =============================================================================
-- RULE (G-02): Formula Lab owns formulation/costing data ONLY. Commerce is Sanad's.
--   Suppliers, purchases (orders/receipts/lines), inventory, stock movements, sales,
--   customers, distributors, wholesale prices, supplier terms/price breaks live in
--   Sanad and are reached through its API (plan/02-specs/sanad-integration.md).
--   Therefore this schema has NO suppliers / purchase_orders / po_lines / receipts /
--   receipt_lines / supplier_items / price_breaks tables.
--   Formula Lab stores only: (a) sanad_refs = id mapping to Sanad uuids,
--   (b) sanad_purchase_lines = READ-ONLY CACHE of Sanad purchase lines used for
--   landed-cost/avg-price calculation (rebuildable, never edited locally),
--   (c) landed_cost_charges = FL-owned overlay keyed by a Sanad purchase uuid.
--   Columns named sanad_*_uuid are soft references (no FK) to Sanad records.
-- =============================================================================
-- PostgreSQL core schema (مختصر)
create table sources (id uuid primary key, kind text not null, title text not null, url text, license text, retrieved_at date not null);
create table substances (id uuid primary key, cas text, inci text, name_en text, name_ar text, mw numeric, formula text);
create table trade_materials (id uuid primary key, substance_id uuid references substances, sanad_supplier_uuid uuid /* Sanad Supplier.uuid, no FK: Sanad owns suppliers */,
  trade_name text not null, grade text, function text, is_mixture boolean default false);
create table property_values (id uuid primary key, entity_type text not null, entity_id uuid not null, property text not null,
  value numeric, min numeric, max numeric, unit text not null, source_id uuid references sources not null,
  confidence text not null check (confidence in ('L1_coa_batch','L2_supplier_tds','L3_standard','L4_regulatory_db','L5_reference','L6_community','L7_estimate')),
  valid_from date not null, lot_no text, created_by uuid, created_at timestamptz default now());
create table prices (id uuid primary key, trade_material_id uuid references trade_materials, sanad_supplier_uuid uuid /* quote reference price; actual purchase prices come from sanad_purchase_lines */,
  amount numeric not null, currency text not null, per_unit text not null, quoted_on date not null, fx_rate numeric, fx_source text);
create table dataset_versions (id text primary key /* sha256 */, created_at timestamptz default now(), notes text);
create table formulas (id uuid primary key, tenant_id uuid not null, name text, product_type text, tier text);
create table formula_versions (id uuid primary key, formula_id uuid references formulas, version int not null, hash text not null,
  status text not null check (status in ('draft','lab_testing','approved','rejected')), reason text, lines jsonb not null,
  created_by uuid, created_at timestamptz default now(), unique(formula_id, version));
create table lab_results (id uuid primary key, formula_version_id uuid references formula_versions, measured_on date,
  property text, value numeric, unit text, method text, operator uuid);
create table rules (id uuid primary key, scope text, rule jsonb not null, source_id uuid references sources, active boolean default true);
create table calc_runs (id uuid primary key, input_hash text, output_hash text, engine_version text, dataset_version text references dataset_versions, created_at timestamptz default now());

-- ===== Procurement, packaging, costing, specialties =====
create table product_families (id text primary key, name_ar text, regulator text check (regulator in ('EOS','EDA','EDA_disinfectant')),
  ifra_category text, required_calcs jsonb, measured_props jsonb);
create table tax_regimes (id uuid primary key, product_family_id text references product_families, tax_type text, rate numeric,
  input_vat_deductible boolean, valid_from date not null, valid_to date, source_id uuid references sources);
create table items (id uuid primary key, kind text not null check (kind in ('raw_material','fragrance','alcohol','container','closure','pump_sprayer','label','sleeve','unit_box','shipping_carton','divider','tape','stretch_film','pallet','consumable')),
  name text not null, trade_material_id uuid references trade_materials, purchase_uom text not null, stock_uom text not null, uom_factor numeric not null,
  hs_code text, attributes jsonb /* capacity_ml, brimful_ml, neck_finish, material, weight_g, color */);
-- ===== Sanad link (reference + read-only cache; NOT source of truth) =====
create table sanad_refs (id uuid primary key, entity_type text not null check (entity_type in ('product','supplier','purchase','purchase_item','batch')),
  sanad_uuid uuid not null, local_table text, local_id uuid, last_synced_at timestamptz, payload_hash text,
  unique (entity_type, sanad_uuid));
-- CACHE ONLY: projection of Sanad Purchase + PurchaseItem (read-only; overwritten by sync; Sanad wins on any conflict).
create table sanad_purchase_lines (sanad_uuid uuid primary key /* PurchaseItem.uuid */, sanad_purchase_uuid uuid not null, sanad_supplier_uuid uuid,
  sanad_product_uuid uuid not null, item_id uuid references items, purchase_date date not null, purchase_status text not null,
  quantity numeric, purchase_uom text, base_quantity numeric /* stock uom, allocation basis */, received_quantity numeric,
  unit_cost numeric, discount_amount numeric, tax_amount numeric, subtotal numeric, currency text default 'EGP',
  batch_number text, expiry_date date, production_date date, sanad_updated_at timestamptz not null, synced_at timestamptz not null default now());
create index on sanad_purchase_lines (sanad_purchase_uuid);
create index on sanad_purchase_lines (sanad_product_uuid, purchase_date);
create table landed_cost_charges (id uuid primary key, sanad_purchase_uuid uuid not null /* Sanad Purchase.uuid; no FK */, charge_type text check (charge_type in ('freight','insurance','customs_duty','clearance','port_storage','inland_transport','inspection_fee','finance','other')),
  amount numeric not null, currency text not null, fx_rate numeric, allocation_basis text check (allocation_basis in ('value','weight','volume','qty')), document_ref text);
create table skus (id uuid primary key, formula_id uuid references formulas, name text, nominal_qty numeric, nominal_uom text, overfill numeric default 0, units_per_carton int, cartons_per_pallet int);
create table packaging_boms (id uuid primary key, sku_id uuid references skus, item_id uuid references items, qty_per_unit numeric not null, reject_pct numeric default 0);
create table one_time_costs (id uuid primary key, sku_id uuid references skus, description text, amount numeric, amortize_over_units numeric);
create table cost_centers (id uuid primary key, name text, rate numeric, uom text /* EGP/min, EGP/kWh, EGP/L water */, valid_from date);
create table sku_conversion (id uuid primary key, sku_id uuid references skus, cost_center_id uuid references cost_centers, qty_per_batch numeric, qty_per_unit numeric);
create table price_waterfalls (id uuid primary key, sku_id uuid references skus, factory_margin_pct numeric, distribution_cost numeric,
  wholesaler_margin_pct numeric, retailer_margin_pct numeric, returns_pct numeric, promo_pct numeric, tax_regime_id uuid references tax_regimes);
