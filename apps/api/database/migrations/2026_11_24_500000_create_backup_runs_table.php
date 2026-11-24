<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * U-05 / U-10. The backup log: every backup, verification, restore and restore test with its result, sizes, SHA-256 and duration.
     * Append-only: the log is evidence that backups ran (and that restores were tried), so a failed run stays visible.
     */
    public function up(): void
    {
        Schema::create('backup_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('kind', 20);
            $table->string('status', 20);
            $table->string('file_name', 255)->nullable();
            $table->string('sha256', 64)->nullable();
            $table->unsignedBigInteger('encrypted_bytes')->nullable();
            $table->unsignedBigInteger('plain_bytes')->nullable();
            $table->string('target_database', 63)->nullable();
            $table->text('failure_reason')->nullable();
            $table->jsonb('detail')->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at');
            $table->unsignedBigInteger('duration_ms');

            $table->index(['kind', 'started_at']);
        });

        DB::unprepared('create trigger backup_runs_append_only before update or delete on backup_runs for each row execute function fl_reject_modification()');
        DB::unprepared('create trigger backup_runs_no_truncate before truncate on backup_runs for each statement execute function fl_reject_modification()');
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_runs');
    }
};
