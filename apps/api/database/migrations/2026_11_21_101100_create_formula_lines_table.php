<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('formula_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('formula_version_id')->constrained('formula_versions');
            $table->unsignedInteger('line_no');
            $table->foreignUuid('trade_material_id')->constrained('trade_materials');
            // % w/w, exact. Null only for a q.s. line, whose share is the remainder (engine computes it).
            $table->exactDecimal('percent_ww')->nullable();
            $table->boolean('is_qs')->default(false);
            $table->string('phase')->nullable();

            $table->unique(['formula_version_id', 'line_no']);
            $table->index('trade_material_id');
        });

        DB::statement('alter table formula_lines add constraint formula_lines_percent_check check (is_qs or percent_ww is not null)');
        DB::statement('alter table formula_lines add constraint formula_lines_percent_range_check check (percent_ww is null or (percent_ww >= 0 and percent_ww <= 100))');
    }

    public function down(): void
    {
        Schema::dropIfExists('formula_lines');
    }
};
