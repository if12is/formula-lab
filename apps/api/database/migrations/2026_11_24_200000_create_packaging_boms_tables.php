<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S-12 (on H-03): the packaging BOM of one SKU and its lines. A BOM holds the packing configuration (units per carton, cartons per
 * pallet) and the pack image kept on a private disk; a line is one packaging component with the quantity it takes at its level
 * (unit, carton or pallet), its piece price and reject %. Every figure of a line may be left empty: the engine then reports the gap,
 * never a zero. A figure that is filled comes with its source and confidence (the engine dates it from the source).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packaging_boms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants');
            $table->string('name');
            $table->string('sku_code', 100)->nullable();
            $table->uuid('sanad_product_uuid')->nullable();
            $table->unsignedInteger('units_per_carton')->nullable();
            $table->unsignedInteger('cartons_per_pallet')->nullable();
            $table->string('image_path')->nullable();
            $table->string('image_name')->nullable();
            $table->string('image_mime', 100)->nullable();
            $table->unsignedBigInteger('image_size')->nullable();
            $table->string('image_sha256', 64)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('packaging_bom_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants');
            $table->foreignUuid('packaging_bom_id')->constrained('packaging_boms')->cascadeOnDelete();
            $table->foreignUuid('trade_material_id')->constrained('trade_materials');
            $table->string('level')->default('unit');
            $table->decimal('qty_per_unit', 14, 6)->nullable();
            $table->decimal('reject_pct', 9, 6)->nullable();
            $table->decimal('price_egp', 16, 6)->nullable();
            $table->foreignUuid('source_id')->nullable()->constrained('sources');
            $table->string('confidence')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['packaging_bom_id', 'trade_material_id']);
        });

        DB::statement("alter table packaging_bom_lines add constraint packaging_bom_lines_level_check check (level in ('unit','carton','pallet'))");
        DB::statement("alter table packaging_bom_lines add constraint packaging_bom_lines_confidence_check check (confidence is null or confidence in ('L1_coa_batch','L2_supplier_tds','L3_standard','L4_regulatory_db','L5_reference','L6_community','L7_estimate'))");
        DB::statement('alter table packaging_bom_lines add constraint packaging_bom_lines_qty_check check (qty_per_unit is null or qty_per_unit > 0)');
        DB::statement('alter table packaging_bom_lines add constraint packaging_bom_lines_reject_check check (reject_pct is null or (reject_pct >= 0 and reject_pct < 100))');
        DB::statement('alter table packaging_bom_lines add constraint packaging_bom_lines_price_check check (price_egp is null or price_egp >= 0)');
        // A filled figure is a sourced fact: the table refuses one without its source and confidence.
        DB::statement('alter table packaging_bom_lines add constraint packaging_bom_lines_sourced_check check ((qty_per_unit is null and reject_pct is null and price_egp is null) or (source_id is not null and confidence is not null))');
        DB::statement('alter table packaging_boms add constraint packaging_boms_packing_check check ((units_per_carton is null or units_per_carton > 0) and (cartons_per_pallet is null or cartons_per_pallet > 0))');
    }

    public function down(): void
    {
        Schema::dropIfExists('packaging_bom_lines');
        Schema::dropIfExists('packaging_boms');
    }
};
