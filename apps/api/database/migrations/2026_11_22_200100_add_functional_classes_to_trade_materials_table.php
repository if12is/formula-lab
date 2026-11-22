<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** M-02: the functional classes of a material (App\Enums\FunctionalClass values), a JSON list. The free-text `function` stays. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trade_materials', function (Blueprint $table) {
            $table->jsonb('functional_classes')->nullable()->after('function');
        });
    }

    public function down(): void
    {
        Schema::table('trade_materials', function (Blueprint $table) {
            $table->dropColumn('functional_classes');
        });
    }
};
