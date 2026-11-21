<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Id mapping to Sanad uuids (plan/02-specs/data-model.sql). Beyond the spec: tenant_id (token
     * scoping), sanad_updated_at (out-of-order detection) and deleted_at (tombstone).
     */
    public function up(): void
    {
        Schema::create('sanad_refs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('entity_type');
            $table->uuid('sanad_uuid');
            $table->string('local_table')->nullable();
            $table->uuid('local_id')->nullable();
            $table->timestampTz('last_synced_at')->nullable();
            $table->string('payload_hash', 64)->nullable();
            $table->timestampTz('sanad_updated_at')->nullable();
            $table->timestampTz('deleted_at')->nullable();

            $table->unique(['tenant_id', 'entity_type', 'sanad_uuid']);
            $table->index(['local_table', 'local_id']);
        });

        DB::statement("alter table sanad_refs add constraint sanad_refs_entity_type_check check (entity_type in ('product','supplier','purchase','purchase_item','batch'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('sanad_refs');
    }
};
