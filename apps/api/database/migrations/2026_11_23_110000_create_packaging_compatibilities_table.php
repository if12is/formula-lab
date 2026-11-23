<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * H-02: the neck finish pairs a person declared compatible (a container's finish with the finish of a cap or pump that
 * fits it), each with its source and confidence. Nothing is seeded and nothing is inferred: a pair that is not a row is
 * not compatible. Finishes compare without case and extra spaces, so the same pair cannot be declared twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packaging_compatibilities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants');
            $table->string('container_neck_finish', 100);
            $table->string('closure_neck_finish', 100);
            $table->foreignUuid('source_id')->constrained('sources');
            $table->string('confidence');
            $table->text('note')->nullable();
            $table->timestamps();
        });

        DB::statement("alter table packaging_compatibilities add constraint packaging_compatibilities_confidence_check check (confidence in ('L1_coa_batch','L2_supplier_tds','L3_standard','L4_regulatory_db','L5_reference','L6_community','L7_estimate'))");
        DB::statement("alter table packaging_compatibilities add constraint packaging_compatibilities_finish_check check (btrim(container_neck_finish) <> '' and btrim(closure_neck_finish) <> '')");
        DB::statement("create unique index packaging_compatibilities_pair_unique on packaging_compatibilities (lower(regexp_replace(btrim(container_neck_finish), '\\s+', ' ', 'g')), lower(regexp_replace(btrim(closure_neck_finish), '\\s+', ' ', 'g')))");
    }

    public function down(): void
    {
        Schema::dropIfExists('packaging_compatibilities');
    }
};
