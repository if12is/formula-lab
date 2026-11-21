<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per Sanad install linked to a tenant. Only the sha256 of the bearer token is stored.
     * push_cursor is a map entity => opaque Sanad cursor (Sanad pages each entity separately).
     */
    public function up(): void
    {
        Schema::create('sanad_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('name');
            $table->string('token_hash', 64)->unique();
            $table->jsonb('abilities');
            // The Sanad-side device uuid (X-Device-Uuid); bound on the first request that sends it.
            $table->uuid('sanad_device_uuid')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->jsonb('push_cursor')->nullable();
            $table->string('pull_cursor')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sanad_devices');
    }
};
