<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Work waiting for Sanad to pull (contract 3.1). id = "ob_" + ULID, so id order is creation order
     * and doubles as the opaque cursor.
     */
    public function up(): void
    {
        Schema::create('sanad_outbox', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->foreignUuid('device_id')->constrained('sanad_devices');
            $table->string('kind');
            $table->string('external_id')->nullable();
            $table->jsonb('payload');
            $table->string('status')->default('pending');
            $table->unsignedSmallInteger('failures')->default(0);
            // Set on the re-queued copy of an item Sanad could not apply (contract 3.2).
            $table->string('retry_of', 40)->nullable();
            $table->jsonb('result')->nullable();
            $table->jsonb('last_error')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('acked_at')->nullable();

            $table->index(['device_id', 'id']);
            $table->index(['device_id', 'status']);
        });

        DB::statement("alter table sanad_outbox add constraint sanad_outbox_kind_check check (kind in ('production_order','finished_good','wholesale_prices'))");
        DB::statement("alter table sanad_outbox add constraint sanad_outbox_status_check check (status in ('pending','applied','failed','conflict'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('sanad_outbox');
    }
};
