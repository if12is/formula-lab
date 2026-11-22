<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * H-01: packaging components live on trade_materials as additive columns (an existing row stays a raw material).
 * `item_kind` says what the row is; the spec columns (type, material, capacity, brimful, weight, neck finish) are
 * only filled for packaging. The spec as a whole comes from one source with one confidence (a supplier drawing or
 * datasheet), the same source/confidence pair the rest of the app uses. The supplier is the existing sanad_supplier_uuid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trade_materials', function (Blueprint $table) {
            $table->string('item_kind', 30)->default('raw_material')->after('trade_name');
            $table->string('packaging_material', 30)->nullable()->after('item_kind');
            $table->decimal('nominal_capacity_ml', 14, 4)->nullable()->after('packaging_material');
            $table->decimal('brimful_capacity_ml', 14, 4)->nullable()->after('nominal_capacity_ml');
            $table->decimal('unit_weight_g', 14, 4)->nullable()->after('brimful_capacity_ml');
            $table->string('neck_finish', 100)->nullable()->after('unit_weight_g');
            $table->foreignUuid('packaging_source_id')->nullable()->after('neck_finish')->constrained('sources');
            $table->string('packaging_confidence', 30)->nullable()->after('packaging_source_id');
            $table->index('item_kind');
        });

        DB::statement("alter table trade_materials add constraint trade_materials_item_kind_check check (item_kind in ('raw_material','container','closure','pump_sprayer','label','sleeve','unit_box','shipping_carton','divider','tape','stretch_film','pallet','consumable'))");
        DB::statement("alter table trade_materials add constraint trade_materials_packaging_material_check check (packaging_material is null or packaging_material in ('pet','hdpe','glass','aluminium','carton'))");
        // Spec values are packaging only, and any spec value needs its source and confidence.
        DB::statement("alter table trade_materials add constraint trade_materials_packaging_spec_check check (
            (item_kind <> 'raw_material' or (packaging_material is null and nominal_capacity_ml is null and brimful_capacity_ml is null and unit_weight_g is null and neck_finish is null and packaging_source_id is null and packaging_confidence is null))
            and ((packaging_material is null and nominal_capacity_ml is null and brimful_capacity_ml is null and unit_weight_g is null and neck_finish is null) or (packaging_source_id is not null and packaging_confidence is not null))
        )");
    }

    public function down(): void
    {
        DB::statement('alter table trade_materials drop constraint if exists trade_materials_packaging_spec_check');
        DB::statement('alter table trade_materials drop constraint if exists trade_materials_packaging_material_check');
        DB::statement('alter table trade_materials drop constraint if exists trade_materials_item_kind_check');
        Schema::table('trade_materials', function (Blueprint $table) {
            $table->dropConstrainedForeignId('packaging_source_id');
            $table->dropIndex(['item_kind']);
            $table->dropColumn(['item_kind', 'packaging_material', 'nominal_capacity_ml', 'brimful_capacity_ml', 'unit_weight_g', 'neck_finish', 'packaging_confidence']);
        });
    }
};
