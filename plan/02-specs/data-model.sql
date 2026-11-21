-- PostgreSQL core schema (مختصر)
create table sources (id uuid primary key, kind text not null, title text not null, url text, license text, retrieved_at date not null);
create table substances (id uuid primary key, cas text, inci text, name_en text, name_ar text, mw numeric, formula text);
create table suppliers (id uuid primary key, name text not null, country text default 'EG', verified boolean default false);
create table trade_materials (id uuid primary key, substance_id uuid references substances, supplier_id uuid references suppliers,
  trade_name text not null, grade text, function text, is_mixture boolean default false);
create table property_values (id uuid primary key, entity_type text not null, entity_id uuid not null, property text not null,
  value numeric, min numeric, max numeric, unit text not null, source_id uuid references sources not null,
  confidence text not null check (confidence in ('L1_coa_batch','L2_supplier_tds','L3_standard','L4_regulatory_db','L5_reference','L6_community','L7_estimate')),
  valid_from date not null, lot_no text, created_by uuid, created_at timestamptz default now());
create table prices (id uuid primary key, trade_material_id uuid references trade_materials, supplier_id uuid references suppliers,
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
create table supplier_items (id uuid primary key, item_id uuid references items, supplier_id uuid references suppliers,
  moq numeric, lead_time_days int, payment_terms_days int, currency text);
create table price_breaks (id uuid primary key, supplier_item_id uuid references supplier_items, min_qty numeric not null, unit_price numeric not null, valid_from date not null, valid_to date);
create table purchase_orders (id uuid primary key, supplier_id uuid references suppliers, status text, currency text, fx_rate numeric, fx_date date, created_at timestamptz default now());
create table po_lines (id uuid primary key, po_id uuid references purchase_orders, item_id uuid references items, qty numeric, unit_price numeric);
create table receipts (id uuid primary key, po_id uuid references purchase_orders, received_on date, shipment_ref text);
create table receipt_lines (id uuid primary key, receipt_id uuid references receipts, po_line_id uuid references po_lines, qty_received numeric, qty_rejected numeric, lot_no text, coa_id uuid);
create table landed_cost_charges (id uuid primary key, receipt_id uuid references receipts, charge_type text check (charge_type in ('freight','insurance','customs_duty','clearance','port_storage','inland_transport','inspection_fee','finance','other')),
  amount numeric not null, currency text not null, fx_rate numeric, allocation_basis text check (allocation_basis in ('value','weight','volume','qty')), document_ref text);
create table skus (id uuid primary key, formula_id uuid references formulas, name text, nominal_qty numeric, nominal_uom text, overfill numeric default 0, units_per_carton int, cartons_per_pallet int);
create table packaging_boms (id uuid primary key, sku_id uuid references skus, item_id uuid references items, qty_per_unit numeric not null, reject_pct numeric default 0);
create table one_time_costs (id uuid primary key, sku_id uuid references skus, description text, amount numeric, amortize_over_units numeric);
create table cost_centers (id uuid primary key, name text, rate numeric, uom text /* EGP/min, EGP/kWh, EGP/L water */, valid_from date);
create table sku_conversion (id uuid primary key, sku_id uuid references skus, cost_center_id uuid references cost_centers, qty_per_batch numeric, qty_per_unit numeric);
create table price_waterfalls (id uuid primary key, sku_id uuid references skus, factory_margin_pct numeric, distribution_cost numeric,
  wholesaler_margin_pct numeric, retailer_margin_pct numeric, returns_pct numeric, promo_pct numeric, tax_regime_id uuid references tax_regimes);
