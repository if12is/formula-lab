<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The claims a product makes (codes from the disinfectant flag data, N-12), kept on the formula like its IFRA category and tax:
     * they are a fact about the product, not about one version. Null = none recorded.
     */
    public function up(): void
    {
        Schema::table('formulas', function (Blueprint $table) {
            $table->json('claims')->nullable()->after('ifra_category');
        });
    }

    public function down(): void
    {
        Schema::table('formulas', function (Blueprint $table) {
            $table->dropColumn('claims');
        });
    }
};
