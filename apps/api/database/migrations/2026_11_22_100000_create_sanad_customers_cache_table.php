<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Read-only cache of the Sanad customers feed (contract 8.5): only what pricing needs, no balances, phones or
     * addresses. Sanad wins; customers are never created or edited here. `customer` joins the sanad_refs entity types and
     * `price_list_upsert` the outbox kinds (contract 8.6).
     */
    public function up(): void
    {
        Schema::create('sanad_customers_cache', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('code')->nullable();
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_wholesale')->default(false);
            // The list the customer is on in Sanad; Sanad lists FL did not create appear here too (contract 8.7).
            $table->uuid('price_list_uuid')->nullable();
            // Sanad's own updated_at, not a Laravel timestamp.
            $table->timestampTz('updated_at');
            $table->boolean('deleted')->default(false);
            $table->timestampTz('synced_at')->useCurrent();

            $table->index('tenant_id');
            $table->index('price_list_uuid');
        });

        DB::statement('alter table sanad_refs drop constraint sanad_refs_entity_type_check');
        DB::statement("alter table sanad_refs add constraint sanad_refs_entity_type_check check (entity_type in ('product','supplier','customer','purchase','purchase_item','batch'))");

        DB::statement('alter table sanad_outbox drop constraint sanad_outbox_kind_check');
        DB::statement("alter table sanad_outbox add constraint sanad_outbox_kind_check check (kind in ('production_order','product_upsert','wholesale_prices','price_list_upsert'))");
    }

    public function down(): void
    {
        DB::table('sanad_outbox')->where('kind', 'price_list_upsert')->delete();
        DB::table('sanad_refs')->where('entity_type', 'customer')->delete();

        DB::statement('alter table sanad_outbox drop constraint sanad_outbox_kind_check');
        DB::statement("alter table sanad_outbox add constraint sanad_outbox_kind_check check (kind in ('production_order','product_upsert','wholesale_prices'))");
        DB::statement('alter table sanad_refs drop constraint sanad_refs_entity_type_check');
        DB::statement("alter table sanad_refs add constraint sanad_refs_entity_type_check check (entity_type in ('product','supplier','purchase','purchase_item','batch'))");
        Schema::dropIfExists('sanad_customers_cache');
    }
};
