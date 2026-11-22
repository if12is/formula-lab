<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * L-01: the IFRA certificate of a fragrance oil, additive to trade_materials. The certificate is the document (number,
 * amendment as printed, issue date, issuer) with its source and confidence; the limits per category stay in
 * property_values (`ifra_limit`, one row per category) and point at the certificate they were read from. A limit with no
 * certificate (older rows) stays valid data, a new limit must name one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ifra_certificates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants');
            $table->foreignUuid('trade_material_id')->constrained('trade_materials')->cascadeOnDelete();
            $table->string('certificate_number', 100);
            $table->string('ifra_amendment', 50)->nullable();
            $table->string('issuer', 150)->nullable();
            $table->date('issued_on');
            $table->foreignUuid('source_id')->constrained('sources');
            $table->string('confidence');
            $table->timestamps();

            $table->unique(['trade_material_id', 'certificate_number']);
        });

        DB::statement("alter table ifra_certificates add constraint ifra_certificates_confidence_check check (confidence in ('L1_coa_batch','L2_supplier_tds','L3_standard','L4_regulatory_db','L5_reference','L6_community','L7_estimate'))");

        Schema::table('property_values', function (Blueprint $table) {
            $table->foreignUuid('ifra_certificate_id')->nullable()->after('category')->constrained('ifra_certificates')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('property_values', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ifra_certificate_id');
        });
        Schema::dropIfExists('ifra_certificates');
    }
};
