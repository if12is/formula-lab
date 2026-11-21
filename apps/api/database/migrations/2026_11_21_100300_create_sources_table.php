<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // null tenant = global reference source
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants');
            $table->string('kind');
            $table->string('title');
            $table->text('url')->nullable();
            $table->string('license')->nullable();
            $table->date('retrieved_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};
