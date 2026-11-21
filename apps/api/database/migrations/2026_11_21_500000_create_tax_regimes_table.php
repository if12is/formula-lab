<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_regimes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tax_type', 50);
            // Percent, 0..100, exact decimal.
            $table->decimal('rate_pct', 7, 4);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->foreignUuid('source_id')->constrained('sources');
            $table->string('confidence', 30);
            // 'pending' until the legal source of the rate is attached and checked (HUMAN-TODO).
            $table->string('verification', 20)->default('pending');
            $table->timestamps();

            $table->unique(['tax_type', 'rate_pct', 'valid_from']);
        });

        DB::statement('alter table tax_regimes add constraint tax_regimes_rate_check check (rate_pct >= 0 and rate_pct <= 100)');
        DB::statement('alter table tax_regimes add constraint tax_regimes_window_check check (valid_to is null or valid_to >= valid_from)');
        DB::statement("alter table tax_regimes add constraint tax_regimes_verification_check check (verification in ('pending','verified'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_regimes');
    }
};
