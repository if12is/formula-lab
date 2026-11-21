<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('formulas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('code');
            $table->string('name');
            $table->string('product_type');
            $table->string('tier')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        DB::statement("alter table formulas add constraint formulas_tier_check check (tier is null or tier in ('economy','mid','premium'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('formulas');
    }
};
