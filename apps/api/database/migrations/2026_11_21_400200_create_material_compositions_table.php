<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_compositions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('trade_material_id')->constrained('trade_materials')->cascadeOnDelete();
            // At least one identifier: the compliance rules target a CAS number or an INCI name.
            $table->string('cas', 50)->nullable();
            $table->string('inci')->nullable();
            // % w/w of the trade material, exact.
            $table->exactDecimal('pct_ww');
            $table->foreignUuid('source_id')->constrained('sources');
            $table->string('confidence');
            // The engine refuses an undated composition.
            $table->date('valid_from');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index('trade_material_id');
        });

        DB::statement("alter table material_compositions add constraint material_compositions_confidence_check check (confidence in ('L1_coa_batch','L2_supplier_tds','L3_standard','L4_regulatory_db','L5_reference','L6_community','L7_estimate'))");
        DB::statement('alter table material_compositions add constraint material_compositions_pct_check check (pct_ww >= 0 and pct_ww <= 100)');
        DB::statement("alter table material_compositions add constraint material_compositions_identifier_check check ((cas is not null and btrim(cas) <> '') or (inci is not null and btrim(inci) <> ''))");

        Schema::table('trade_materials', function (Blueprint $table) {
            // true: the listed components are the whole material, so a substance that is not listed is absent (0%).
            $table->boolean('composition_complete')->default(false);
            // A fragrance oil is checked against its own IFRA certificate (property `ifra_limit` per category).
            $table->boolean('is_fragrance')->default(false);
        });

        Schema::table('formulas', function (Blueprint $table) {
            // The product's IFRA category as the certificate names it; free text, no built-in list of categories.
            $table->string('ifra_category', 50)->nullable();
        });

        Schema::table('property_values', function (Blueprint $table) {
            // What the value applies to when one property has several (the IFRA category of an `ifra_limit`).
            $table->string('category', 50)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('property_values', fn (Blueprint $table) => $table->dropColumn('category'));
        Schema::table('formulas', fn (Blueprint $table) => $table->dropColumn('ifra_category'));
        Schema::table('trade_materials', fn (Blueprint $table) => $table->dropColumn(['composition_complete', 'is_fragrance']));
        Schema::dropIfExists('material_compositions');
    }
};
