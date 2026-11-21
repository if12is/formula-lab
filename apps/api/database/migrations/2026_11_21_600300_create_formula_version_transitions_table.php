<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The signed trail of a version's workflow (O-02): every status change with who, when, the comment and the content
     * hash of the version at that moment. Append-only; the status on formula_versions is only the latest entry.
     */
    public function up(): void
    {
        Schema::create('formula_version_transitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('formula_version_id')->constrained('formula_versions');
            $table->string('from_status');
            $table->string('to_status');
            $table->foreignId('signed_by')->nullable()->constrained('users');
            $table->text('comment')->nullable();
            $table->string('version_hash', 64);
            $table->jsonb('basis')->nullable();
            $table->timestampTz('signed_at')->useCurrent();

            $table->index(['formula_version_id', 'signed_at']);
        });

        DB::statement("alter table formula_version_transitions add constraint fvt_status_check check (from_status in ('draft','lab_testing','approved','rejected') and to_status in ('draft','lab_testing','approved','rejected'))");
        DB::statement("alter table formula_version_transitions add constraint fvt_rejection_reason_check check (to_status <> 'rejected' or (comment is not null and btrim(comment) <> ''))");

        DB::unprepared('create trigger formula_version_transitions_append_only before update or delete on formula_version_transitions for each row execute function fl_reject_modification()');
        DB::unprepared('create trigger formula_version_transitions_no_truncate before truncate on formula_version_transitions for each statement execute function fl_reject_modification()');
    }

    public function down(): void
    {
        Schema::dropIfExists('formula_version_transitions');
    }
};
