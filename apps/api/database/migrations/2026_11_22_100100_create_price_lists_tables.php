<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-merchant price lists (contract section 8). A list is a named set of per-product prices that Formula Lab owns and
     * publishes to Sanad under its own uuid. Each price is either a margin rule from config (applied to the product's unit
     * cost by the engine) or an explicit override with a source and a confidence; nothing is stored as a computed price.
     * Merchants are Sanad customers: only their uuid is kept here (the name comes from the read-only cache).
     *
     * The unit cost the engine prices against is a sourced fact about the product, kept on the formula like its tax setting:
     * all three columns are set together or not at all.
     */
    public function up(): void
    {
        Schema::create('price_lists', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('name');
            $table->string('currency', 3)->default('EGP');
            $table->boolean('is_active')->default(true);
            $table->string('sync_status', 20)->default('draft');
            $table->string('sync_outbox_id', 40)->nullable();
            $table->jsonb('sync_detail')->nullable();
            $table->string('published_hash', 64)->nullable();
            $table->timestampTz('published_at')->nullable();
            // What the last publish sent: product uuids with a price and customer uuids on the list (to send removals next time).
            $table->jsonb('published_items')->default('[]');
            $table->jsonb('published_customers')->default('[]');
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });
        DB::statement("alter table price_lists add constraint price_lists_currency_check check (currency = 'EGP')");
        DB::statement("alter table price_lists add constraint price_lists_sync_status_check check (sync_status in ('draft','pending','applied','failed','conflict'))");

        Schema::create('price_list_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('price_list_id')->constrained('price_lists')->cascadeOnDelete();
            $table->foreignUuid('formula_id')->constrained('formulas');
            $table->string('mode', 20);
            $table->string('rule_key', 100)->nullable();
            $table->exactDecimal('override_price')->nullable();
            $table->foreignUuid('override_source_id')->nullable()->constrained('sources');
            $table->string('override_confidence')->nullable();
            $table->timestamps();

            $table->unique(['price_list_id', 'formula_id']);
        });
        DB::statement("alter table price_list_items add constraint price_list_items_mode_check check (
            (mode = 'rule' and rule_key is not null and override_price is null and override_source_id is null and override_confidence is null)
            or (mode = 'override' and rule_key is null and override_price is not null and override_price > 0
                and override_source_id is not null and override_confidence is not null)
        )");
        DB::statement("alter table price_list_items add constraint price_list_items_confidence_check check (override_confidence is null or override_confidence in ('L1_coa_batch','L2_supplier_tds','L3_standard','L4_regulatory_db','L5_reference','L6_community','L7_estimate'))");

        // A Sanad customer points at one list, so a customer belongs to at most one Formula Lab list.
        Schema::create('price_list_customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('price_list_id')->constrained('price_lists')->cascadeOnDelete();
            $table->foreignUuid('customer_uuid')->constrained('sanad_customers_cache', 'uuid');
            $table->timestamps();

            $table->unique('customer_uuid');
            $table->index('price_list_id');
        });

        Schema::table('formulas', function (Blueprint $table) {
            $table->exactDecimal('unit_cost')->nullable()->after('sanad_product_uuid');
            $table->foreignUuid('unit_cost_source_id')->nullable()->after('unit_cost')->constrained('sources');
            $table->string('unit_cost_confidence')->nullable()->after('unit_cost_source_id');
        });
        DB::statement("alter table formulas add constraint formulas_unit_cost_check check (
            (unit_cost is null and unit_cost_source_id is null and unit_cost_confidence is null)
            or (unit_cost is not null and unit_cost > 0 and unit_cost_source_id is not null and unit_cost_confidence is not null
                and unit_cost_confidence in ('L1_coa_batch','L2_supplier_tds','L3_standard','L4_regulatory_db','L5_reference','L6_community','L7_estimate'))
        )");
    }

    public function down(): void
    {
        DB::statement('alter table formulas drop constraint if exists formulas_unit_cost_check');
        Schema::table('formulas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unit_cost_source_id');
            $table->dropColumn(['unit_cost', 'unit_cost_confidence']);
        });
        Schema::dropIfExists('price_list_customers');
        Schema::dropIfExists('price_list_items');
        Schema::dropIfExists('price_lists');
    }
};
