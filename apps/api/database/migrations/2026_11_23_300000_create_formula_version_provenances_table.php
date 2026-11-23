<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a formula version came from when it was not typed (J-09): the optimizer's inputs, the tier profile it read, the solver version and
     * the input hash, kept beside the version and never changed. The version's own hash stays the hash of its lines.
     */
    public function up(): void
    {
        Schema::create('formula_version_provenances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('formula_version_id')->unique()->constrained('formula_versions');
            $table->string('origin', 30);
            $table->foreignUuid('tier_profile_id')->nullable()->constrained('tier_profiles')->nullOnDelete();
            $table->string('tier', 20);
            $table->jsonb('optimizer_inputs');
            $table->string('optimizer_version');
            $table->string('solver');
            $table->string('input_hash');
            $table->string('output_hash');
            $table->string('cost_per_kg');
            $table->timestamp('created_at')->useCurrent();
        });

        DB::unprepared('create trigger formula_version_provenances_append_only before update or delete on formula_version_provenances for each row execute function fl_reject_modification()');
        DB::unprepared('create trigger formula_version_provenances_no_truncate before truncate on formula_version_provenances for each statement execute function fl_reject_modification()');
    }

    public function down(): void
    {
        Schema::dropIfExists('formula_version_provenances');
    }
};
