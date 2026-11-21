<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Tested on formula X" (O-07): the dose and the required HLB a formula version used, recorded when a stability result of
     * one of its completed trials stands on an approved version. A provenance record (local lab history, L1): it never feeds a
     * calculation and never changes a registered value. Append-only; one record per trial, material and kind.
     */
    public function up(): void
    {
        Schema::create('tested_value_marks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('formula_version_id')->constrained('formula_versions');
            $table->foreignUuid('lab_trial_id')->constrained('lab_trials');
            $table->foreignId('lab_measurement_id')->constrained('lab_measurements');
            $table->foreignUuid('trade_material_id')->constrained('trade_materials');
            $table->string('kind');
            $table->exactDecimal('value');
            $table->foreignUuid('source_id')->nullable()->constrained('sources');
            $table->string('confidence')->default('L1_coa_batch');
            $table->date('tested_on');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['lab_trial_id', 'trade_material_id', 'kind']);
            $table->index(['trade_material_id', 'kind']);
            $table->index('formula_version_id');
        });

        DB::statement("alter table tested_value_marks add constraint tested_value_marks_kind_check check (kind in ('dose','required_hlb'))");
        DB::statement("alter table tested_value_marks add constraint tested_value_marks_confidence_check check (confidence = 'L1_coa_batch')");
        DB::unprepared('create trigger tested_value_marks_append_only before update or delete on tested_value_marks for each row execute function fl_reject_modification()');
        DB::unprepared('create trigger tested_value_marks_no_truncate before truncate on tested_value_marks for each statement execute function fl_reject_modification()');
    }

    public function down(): void
    {
        Schema::dropIfExists('tested_value_marks');
    }
};
