<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The tax choice of a product lives on the formula (the product's identity row), like its IFRA category: null mode =
     * not chosen yet (pricing reports it as missing), 'disabled' = no tax line, 'rate' = a rate. The Sanad product the
     * finished good was published as is kept here so a later publish updates it instead of creating a second one.
     */
    public function up(): void
    {
        Schema::table('formulas', function (Blueprint $table) {
            $table->string('tax_mode', 20)->nullable()->after('ifra_category');
            $table->decimal('tax_rate_pct', 7, 4)->nullable()->after('tax_mode');
            $table->uuid('sanad_product_uuid')->nullable()->unique()->after('tax_rate_pct');
        });

        DB::statement("alter table formulas add constraint formulas_tax_check check (
            (tax_mode is null and tax_rate_pct is null)
            or (tax_mode = 'disabled' and tax_rate_pct is null)
            or (tax_mode = 'rate' and tax_rate_pct is not null and tax_rate_pct >= 0 and tax_rate_pct <= 100)
        )");
    }

    public function down(): void
    {
        DB::statement('alter table formulas drop constraint if exists formulas_tax_check');
        Schema::table('formulas', function (Blueprint $table) {
            $table->dropColumn(['tax_mode', 'tax_rate_pct', 'sanad_product_uuid']);
        });
    }
};
