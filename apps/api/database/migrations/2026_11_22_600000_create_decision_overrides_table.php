<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Y-04 / Y-07. An override is a signed, reasoned decision to go ahead although the rules blocked: who (user and role), why,
     * when, the failed rules it overrides (kept inside the engine's hashed record) and the e-signature event (its id is the
     * engine record's signatureRef). Append-only like every quality record: an override is never edited, a new one is added.
     * A publication points at the override that allowed it. calc_runs joins the append-only set and dataset_versions gets its missing TRUNCATE guard (Y-07).
     */
    public function up(): void
    {
        // Minimal role carrier until RBAC (U-01) replaces it: the override rule asks OverrideAuthorizer, the one place that reads it.
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 60)->nullable();
        });

        Schema::create('decision_overrides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('kind', 60);
            $table->string('subject_ref', 64);
            $table->string('decision_hash', 64);
            $table->jsonb('record');
            $table->foreignId('signed_by')->constrained('users');
            $table->string('role', 60);
            $table->text('reason');
            $table->string('signature_method', 30);
            $table->timestampTz('signed_at')->useCurrent();

            $table->index(['kind', 'subject_ref', 'decision_hash']);
        });

        Schema::table('formula_publications', function (Blueprint $table) {
            $table->foreignUuid('decision_override_id')->nullable()->constrained('decision_overrides');
        });

        // dataset_versions already refuses update and delete; it did not refuse TRUNCATE.
        DB::unprepared('create trigger dataset_versions_no_truncate before truncate on dataset_versions for each statement execute function fl_reject_modification()');

        foreach (['decision_overrides', 'calc_runs'] as $name) {
            DB::unprepared("create trigger {$name}_append_only before update or delete on {$name} for each row execute function fl_reject_modification()");
            DB::unprepared("create trigger {$name}_no_truncate before truncate on {$name} for each statement execute function fl_reject_modification()");
        }
    }

    public function down(): void
    {
        DB::unprepared('drop trigger if exists dataset_versions_no_truncate on dataset_versions');
        DB::unprepared('drop trigger if exists calc_runs_append_only on calc_runs');
        DB::unprepared('drop trigger if exists calc_runs_no_truncate on calc_runs');
        Schema::table('formula_publications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('decision_override_id');
        });
        Schema::dropIfExists('decision_overrides');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
