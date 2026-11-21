<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dataset_versions', function (Blueprint $table) {
            $table->string('id', 64)->primary(); // sha256 of the canonical snapshot
            $table->timestampTz('created_at')->useCurrent();
            $table->text('notes')->nullable();
            $table->jsonb('manifest');
        });

        DB::statement("alter table dataset_versions add constraint dataset_versions_id_check check (id ~ '^[0-9a-f]{64}$')");
        // ADR 003: published snapshots are never changed or removed.
        DB::unprepared('create trigger dataset_versions_immutable before update or delete on dataset_versions for each row execute function fl_reject_modification()');
    }

    public function down(): void
    {
        Schema::dropIfExists('dataset_versions');
    }
};
