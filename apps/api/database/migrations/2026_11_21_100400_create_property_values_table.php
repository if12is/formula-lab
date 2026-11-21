<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_values', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // null tenant = global reference data
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants');
            $table->string('entity_type');
            $table->uuid('entity_id');
            $table->string('property');
            $table->exactDecimal('value')->nullable();
            $table->exactDecimal('min')->nullable();
            $table->exactDecimal('max')->nullable();
            $table->string('unit');
            $table->foreignUuid('source_id')->constrained('sources');
            $table->string('confidence');
            $table->date('valid_from');
            $table->string('lot_no')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['entity_type', 'entity_id', 'property']);
        });

        DB::statement("alter table property_values add constraint property_values_entity_type_check check (entity_type in ('substance','trade_material'))");
        DB::statement("alter table property_values add constraint property_values_confidence_check check (confidence in ('L1_coa_batch','L2_supplier_tds','L3_standard','L4_regulatory_db','L5_reference','L6_community','L7_estimate'))");
        // A value, or a (half-)open range, must be present; a closed range must be ordered.
        DB::statement('alter table property_values add constraint property_values_has_value_check check ("value" is not null or "min" is not null or "max" is not null)');
        DB::statement('alter table property_values add constraint property_values_range_order_check check ("min" is null or "max" is null or "min" <= "max")');
    }

    public function down(): void
    {
        Schema::dropIfExists('property_values');
    }
};
