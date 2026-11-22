<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * G-09 / Y-05. A cost approval is a person's signed statement that a product's unit cost (with its source and confidence) is
     * good, tied to one approved formula version and to the material prices (EGP per kg, as the engine chose them) it rests on:
     * the publish rule compares those prices with today's to decide whether the cost must be approved again. A publication is the
     * record of one push of the finished good to Sanad, with the published details and the hashed decision that allowed it.
     * Both are append-only: a change is a new row.
     */
    public function up(): void
    {
        Schema::create('formula_cost_approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('formula_id')->constrained('formulas');
            $table->foreignUuid('formula_version_id')->constrained('formula_versions');
            $table->exactDecimal('unit_cost');
            $table->foreignUuid('unit_cost_source_id')->constrained('sources');
            $table->string('unit_cost_confidence', 30);
            $table->jsonb('material_prices');
            $table->string('report_input_hash', 64);
            $table->string('report_output_hash', 64);
            $table->text('comment')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestampTz('approved_at')->useCurrent();

            $table->index(['formula_id', 'approved_at']);
        });

        Schema::create('formula_publications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('formula_id')->constrained('formulas');
            $table->foreignUuid('cost_approval_id')->constrained('formula_cost_approvals');
            $table->uuid('sanad_product_uuid');
            $table->string('mode', 10);
            $table->string('outbox_id', 40)->nullable();
            $table->jsonb('details');
            $table->jsonb('decision');
            $table->string('decision_hash', 64);
            $table->foreignId('published_by')->nullable()->constrained('users');
            $table->timestampTz('published_at')->useCurrent();

            $table->index(['formula_id', 'published_at']);
        });

        DB::statement("alter table formula_publications add constraint formula_publications_mode_check check (mode in ('outbox','lan'))");

        foreach (['formula_cost_approvals', 'formula_publications'] as $name) {
            DB::unprepared("create trigger {$name}_append_only before update or delete on {$name} for each row execute function fl_reject_modification()");
            DB::unprepared("create trigger {$name}_no_truncate before truncate on {$name} for each statement execute function fl_reject_modification()");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('formula_publications');
        Schema::dropIfExists('formula_cost_approvals');
    }
};
