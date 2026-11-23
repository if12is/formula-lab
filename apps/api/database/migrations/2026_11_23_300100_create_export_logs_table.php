<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * U-03. The export log: every printed or saved-as-PDF document (the label, the cost report, the weighing sheet, the lot trace) with who, what, when
     * and the hash printed on it (the formula-version hash, or the content hash of a lot trace). `watermark` is the state the page was stamped with.
     * user_id is nullable until accounts exist (U-01). Append-only: the log is evidence.
     */
    public function up(): void
    {
        Schema::create('export_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('kind', 30);
            $table->string('subject_label', 255);
            $table->uuid('subject_id')->nullable();
            $table->foreignUuid('formula_id')->nullable()->constrained('formulas');
            $table->foreignUuid('formula_version_id')->nullable()->constrained('formula_versions');
            $table->string('content_hash', 64);
            $table->string('watermark', 20);
            $table->timestampTz('exported_at');

            $table->index('exported_at');
            $table->index(['kind', 'exported_at']);
            $table->index('content_hash');
        });

        DB::unprepared('create trigger export_logs_append_only before update or delete on export_logs for each row execute function fl_reject_modification()');
        DB::unprepared('create trigger export_logs_no_truncate before truncate on export_logs for each statement execute function fl_reject_modification()');
    }

    public function down(): void
    {
        Schema::dropIfExists('export_logs');
    }
};
