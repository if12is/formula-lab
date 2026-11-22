<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** M-01: the temperature (°C) a line is added at, next to its phase. Additive and nullable: existing lines stay as they are. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('formula_lines', function (Blueprint $table) {
            $table->exactDecimal('addition_temp_c')->nullable()->after('phase');
        });
    }

    public function down(): void
    {
        Schema::table('formula_lines', function (Blueprint $table) {
            $table->dropColumn('addition_temp_c');
        });
    }
};
