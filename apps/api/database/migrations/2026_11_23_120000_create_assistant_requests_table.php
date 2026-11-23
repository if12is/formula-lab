<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per question to the assistant: the totals of every provider call it took. The question and the reply are not stored (P-10).
        Schema::create('assistant_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants');
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('model');
            $table->string('status');
            $table->unsignedInteger('api_calls')->default(0);
            $table->unsignedInteger('tool_calls')->default(0);
            $table->unsignedInteger('guard_retries')->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            // Null until the prices are sourced (config/assistant.php pricing); never 0 for "unknown".
            $table->exactDecimal('cost')->nullable();
            $table->string('cost_currency', 8)->nullable();
            $table->string('error_code')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_requests');
    }
};
