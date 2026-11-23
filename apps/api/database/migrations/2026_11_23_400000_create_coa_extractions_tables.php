<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * P-05. A COA read by the model: the stored file (and its SHA-256), what the model proposed (one row per field, as extracted) and the events
     * that say where it stands. None of these rows is ever updated or deleted: the status is the latest event (extracted -> confirmed | rejected),
     * and the values a person confirmed (with their edits) are in the confirming event, next to the property_values rows they created.
     * Until it is confirmed nothing is in property_values, so no calculation can read an extracted figure.
     */
    public function up(): void
    {
        Schema::create('coa_extractions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('trade_material_id')->constrained('trade_materials');
            $table->foreignId('uploaded_by')->nullable()->constrained('users');
            $table->string('file_path', 500);
            $table->string('file_name', 255);
            $table->string('file_mime', 100);
            $table->unsignedInteger('file_size');
            $table->string('file_sha256', 64);
            $table->string('supplier', 200)->nullable();
            $table->string('lot_no', 100)->nullable();
            $table->date('document_date')->nullable();
            // Whether the server could read the document's text to check the numbers: 'verified' | 'unverified' (a scan or an image).
            $table->string('text_check', 12);
            $table->string('model', 100);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            // The model's answer exactly as received, for audit.
            $table->jsonb('raw_answer');
            $table->timestampTz('created_at');

            $table->index('file_sha256');
            $table->index(['trade_material_id', 'created_at']);
        });

        Schema::create('coa_extraction_fields', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('coa_extraction_id')->constrained('coa_extractions');
            $table->unsignedSmallInteger('position');
            $table->string('property_as_printed', 200)->nullable();
            $table->string('property_key', 50)->nullable();
            $table->exactDecimal('value')->nullable();
            $table->exactDecimal('min')->nullable();
            $table->exactDecimal('max')->nullable();
            $table->string('unit', 30)->nullable();
            // value / min / max / unit exactly as the model returned them.
            $table->jsonb('as_printed');
            $table->string('converted_from', 30)->nullable();
            $table->string('method', 300)->nullable();
            $table->text('quote')->nullable();
            $table->unsignedSmallInteger('page')->nullable();
            $table->string('reading_confidence', 10);
            $table->jsonb('issues');

            $table->unique(['coa_extraction_id', 'position']);
        });

        Schema::create('coa_extraction_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('coa_extraction_id')->constrained('coa_extractions');
            $table->string('status', 12);
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->timestampTz('occurred_at');
            $table->text('reason')->nullable();
            // On confirmation: what the person decided per field, and the property_values rows written.
            $table->jsonb('decisions')->nullable();
            $table->jsonb('property_value_ids')->nullable();

            $table->index(['coa_extraction_id', 'id']);
        });

        DB::statement("alter table coa_extraction_events add constraint coa_extraction_events_status_check check (status in ('extracted','confirmed','rejected'))");
        DB::statement("alter table coa_extraction_fields add constraint coa_extraction_fields_confidence_check check (reading_confidence in ('high','medium','low'))");
        // An extraction is decided once: at most one final event, and exactly one `extracted` event opens it.
        DB::statement("create unique index coa_extraction_events_one_final on coa_extraction_events (coa_extraction_id) where status in ('confirmed','rejected')");
        DB::statement("create unique index coa_extraction_events_one_opening on coa_extraction_events (coa_extraction_id) where status = 'extracted'");

        foreach (['coa_extractions', 'coa_extraction_fields', 'coa_extraction_events'] as $table) {
            DB::unprepared("create trigger {$table}_append_only before update or delete on {$table} for each row execute function fl_reject_modification()");
            DB::unprepared("create trigger {$table}_no_truncate before truncate on {$table} for each statement execute function fl_reject_modification()");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('coa_extraction_events');
        Schema::dropIfExists('coa_extraction_fields');
        Schema::dropIfExists('coa_extractions');
    }
};
