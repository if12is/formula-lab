<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * READ-ONLY CACHE of Sanad purchase lines (plan/02-specs/data-model.sql). Rebuildable, Sanad wins.
     * item_id has no FK yet: the `items` table does not exist until the procurement phase.
     */
    public function up(): void
    {
        Schema::create('sanad_purchase_lines', function (Blueprint $table) {
            $table->uuid('sanad_uuid')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->uuid('sanad_purchase_uuid');
            $table->uuid('sanad_supplier_uuid')->nullable();
            $table->uuid('sanad_product_uuid');
            $table->uuid('item_id')->nullable();
            $table->date('purchase_date');
            $table->string('purchase_status');
            $table->exactDecimal('quantity')->nullable();
            $table->string('purchase_uom')->nullable();
            $table->exactDecimal('base_quantity')->nullable();
            $table->exactDecimal('received_quantity')->nullable();
            $table->exactDecimal('unit_cost')->nullable();
            $table->exactDecimal('discount_amount')->nullable();
            $table->exactDecimal('tax_amount')->nullable();
            $table->exactDecimal('subtotal')->nullable();
            $table->string('currency', 3)->default('EGP');
            $table->string('batch_number')->nullable();
            $table->date('expiry_date')->nullable();
            $table->date('production_date')->nullable();
            $table->timestampTz('sanad_updated_at');
            $table->timestampTz('synced_at')->useCurrent();

            $table->index('sanad_purchase_uuid');
            $table->index(['sanad_product_uuid', 'purchase_date']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sanad_purchase_lines');
    }
};
