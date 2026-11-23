<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The constraints of one tier (economy, mid, premium) of one product type, as the optimizer reads them (J-01, plan/02-specs/tier-profiles.md).
     * Every figure in `profile` is `null` until a person writes it, and a written figure has a source: one source record for the whole
     * profile (who decided these limits), which the optimizer receives for every field that holds a value. The app ships no profile.
     */
    public function up(): void
    {
        Schema::create('tier_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('product_type');
            $table->string('tier', 20);
            $table->jsonb('profile');
            $table->foreignUuid('source_id')->nullable()->constrained('sources');
            $table->timestamps();

            $table->unique(['tenant_id', 'product_type', 'tier']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tier_profiles');
    }
};
