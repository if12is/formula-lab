<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_materials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // null tenant = global reference catalogue
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants');
            // null substance = mixture (is_mixture) or a draft not yet linked
            $table->foreignUuid('substance_id')->nullable()->constrained('substances');
            // Soft references into Sanad (no FK): Sanad owns products and suppliers.
            $table->uuid('sanad_product_uuid')->nullable()->index();
            $table->uuid('sanad_supplier_uuid')->nullable();
            $table->string('trade_name');
            $table->string('grade')->nullable();
            $table->string('function')->nullable();
            $table->boolean('is_mixture')->default(false);
            $table->string('status')->default('draft');
            $table->timestamps();

            $table->index('substance_id');
        });

        DB::statement("alter table trade_materials add constraint trade_materials_status_check check (status in ('draft','active'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_materials');
    }
};
