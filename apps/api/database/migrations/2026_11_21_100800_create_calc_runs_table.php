<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calc_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants');
            $table->string('input_hash', 64);
            $table->string('output_hash', 64);
            $table->string('engine_version');
            $table->string('dataset_version', 64);
            $table->foreign('dataset_version')->references('id')->on('dataset_versions');
            $table->timestampTz('created_at')->useCurrent();

            $table->index('input_hash');
            $table->index('dataset_version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calc_runs');
    }
};
