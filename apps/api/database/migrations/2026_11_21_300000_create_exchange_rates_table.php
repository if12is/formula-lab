<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dated exchange rates into the base currency (EGP): units of EGP per 1 unit of `from_currency`, with a source and a
     * confidence. No rate is ever seeded or defaulted; a price in another currency needs one (cost.fx_rate_required).
     */
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // null tenant = global rate
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants');
            $table->string('from_currency', 3);
            $table->string('to_currency', 3)->default('EGP');
            $table->exactDecimal('rate');
            $table->date('rate_date');
            $table->foreignUuid('source_id')->constrained('sources');
            $table->string('confidence');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['from_currency', 'to_currency', 'rate_date', 'source_id']);
            $table->index(['from_currency', 'rate_date']);
        });

        DB::statement("alter table exchange_rates add constraint exchange_rates_confidence_check check (confidence in ('L1_coa_batch','L2_supplier_tds','L3_standard','L4_regulatory_db','L5_reference','L6_community','L7_estimate'))");
        DB::statement('alter table exchange_rates add constraint exchange_rates_rate_check check (rate > 0)');
        DB::statement("alter table exchange_rates add constraint exchange_rates_to_base_check check (to_currency = 'EGP' and from_currency <> to_currency)");
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
