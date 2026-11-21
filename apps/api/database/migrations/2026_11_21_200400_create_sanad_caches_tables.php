<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Read-only caches of Sanad products and suppliers needed for materials. Sanad wins. */
    public function up(): void
    {
        Schema::create('sanad_products_cache', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->string('sku')->nullable();
            $table->string('barcode')->nullable();
            $table->string('item_kind');
            $table->boolean('is_sellable')->default(false);
            $table->string('uom')->nullable();
            $table->jsonb('units')->nullable();
            $table->exactDecimal('cost_price')->nullable();
            $table->exactDecimal('tax_rate')->nullable();
            // Sanad's own updated_at, not a Laravel timestamp.
            $table->timestampTz('updated_at');
            $table->boolean('deleted')->default(false);
            $table->timestampTz('synced_at')->useCurrent();

            $table->index(['tenant_id', 'item_kind']);
        });
        DB::statement("alter table sanad_products_cache add constraint sanad_products_cache_item_kind_check check (item_kind in ('merchandise','raw_material','packaging','finished_good'))");

        Schema::create('sanad_suppliers_cache', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('code')->nullable();
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->string('tax_number')->nullable();
            $table->unsignedSmallInteger('payment_terms')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('updated_at');
            $table->boolean('deleted')->default(false);
            $table->timestampTz('synced_at')->useCurrent();

            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sanad_suppliers_cache');
        Schema::dropIfExists('sanad_products_cache');
    }
};
