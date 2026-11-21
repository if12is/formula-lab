<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reference price quotes only. Actual purchase prices come from Sanad
     * (sanad_purchase_lines cache), never from this table.
     */
    public function up(): void
    {
        Schema::create('prices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants');
            $table->foreignUuid('trade_material_id')->constrained('trade_materials');
            $table->uuid('sanad_supplier_uuid')->nullable();
            $table->exactDecimal('amount');
            $table->string('currency', 3);
            $table->string('per_unit');
            $table->date('quoted_on');
            $table->exactDecimal('fx_rate')->nullable();
            $table->string('fx_source')->nullable();
            $table->foreignUuid('source_id')->constrained('sources');
            $table->string('confidence');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['trade_material_id', 'quoted_on']);
        });

        DB::statement("alter table prices add constraint prices_confidence_check check (confidence in ('L1_coa_batch','L2_supplier_tds','L3_standard','L4_regulatory_db','L5_reference','L6_community','L7_estimate'))");
        DB::statement('alter table prices add constraint prices_amount_check check (amount >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('prices');
    }
};
