<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('formula_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('formula_id')->constrained('formulas');
            $table->unsignedInteger('version');
            $table->string('hash', 64);
            $table->string('status')->default('draft');
            $table->text('reason')->nullable();
            $table->uuid('parent_version_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['formula_id', 'version']);
        });

        // Self-reference needs the primary key to exist first.
        Schema::table('formula_versions', function (Blueprint $table) {
            $table->foreign('parent_version_id')->references('id')->on('formula_versions');
        });

        DB::statement("alter table formula_versions add constraint formula_versions_status_check check (status in ('draft','lab_testing','approved','rejected'))");
        DB::statement("alter table formula_versions add constraint formula_versions_reason_check check (version = 1 or (reason is not null and btrim(reason) <> ''))");
    }

    public function down(): void
    {
        Schema::dropIfExists('formula_versions');
    }
};
