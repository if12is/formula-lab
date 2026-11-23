<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Q-11 / Q-03 / Q-04 / Q-09: a batch made from a production order. It is a snapshot of the approved version (hash included);
     * its lines record what was planned, what the operator weighed and the raw-material lot used; its checks are append-only
     * readings; a deviation records why, who and when and, above the configured threshold, waits for an approval. Nothing here
     * moves stock: consumption and receipt are Sanad calls made from the production order.
     */
    public function up(): void
    {
        Schema::create('production_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('production_order_id')->unique()->constrained('production_orders');
            $table->string('batch_number');
            $table->string('status')->default('in_progress');
            $table->foreignUuid('formula_version_id')->constrained('formula_versions');
            $table->string('formula_hash', 64);
            $table->jsonb('snapshot');
            $table->string('operator');
            $table->timestampTz('started_at');
            $table->timestampTz('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users');
            $table->exactDecimal('planned_waste_pct')->nullable();
            $table->exactDecimal('actual_waste_g')->nullable();
            $table->exactDecimal('actual_units')->nullable();
            $table->exactDecimal('actual_conversion_egp')->nullable();
            $table->text('conversion_note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['tenant_id', 'batch_number']);
        });

        DB::statement("alter table production_batches add constraint production_batches_status_check check (status in ('in_progress','completed'))");
        DB::statement("alter table production_batches add constraint production_batches_completed_check check ((status = 'completed') = (completed_at is not null))");
        DB::statement('alter table production_batches add constraint production_batches_numbers_check check ((planned_waste_pct is null or (planned_waste_pct >= 0 and planned_waste_pct < 100)) and (actual_waste_g is null or actual_waste_g >= 0) and (actual_units is null or actual_units > 0) and (actual_conversion_egp is null or actual_conversion_egp >= 0))');

        DB::unprepared(<<<'SQL'
create or replace function fl_guard_completed_batch() returns trigger as $$
begin
    if TG_OP = 'DELETE' or OLD.status = 'completed' then
        raise exception 'production_batch % is closed or cannot be deleted (% rejected)', OLD.id, TG_OP using errcode = 'restrict_violation';
    end if;
    return NEW;
end;
$$ language plpgsql
SQL);
        DB::unprepared('create trigger production_batches_closed before update or delete on production_batches for each row execute function fl_guard_completed_batch()');

        Schema::create('production_batch_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('production_batch_id')->constrained('production_batches');
            $table->unsignedInteger('line_no');
            $table->string('kind');
            $table->foreignUuid('trade_material_id')->constrained('trade_materials');
            $table->string('phase')->nullable();
            $table->string('uom');
            $table->exactDecimal('planned_quantity');
            $table->exactDecimal('weigh_quantity');
            $table->exactDecimal('actual_quantity')->nullable();
            $table->string('lot_number')->nullable();
            $table->exactDecimal('unit_cost')->nullable();
            $table->string('cost_uom')->nullable();
            $table->string('unit_cost_source')->nullable();
            $table->uuid('sanad_purchase_line_uuid')->nullable();
            $table->string('weighed_by')->nullable();
            $table->timestampTz('weighed_at')->nullable();
            $table->timestamps();

            $table->unique(['production_batch_id', 'line_no']);
            $table->index('lot_number');
            $table->index('trade_material_id');
        });

        DB::statement("alter table production_batch_lines add constraint production_batch_lines_kind_check check (kind in ('raw_material','packaging'))");
        DB::statement("alter table production_batch_lines add constraint production_batch_lines_uom_check check (uom in ('g','unit'))");
        DB::statement('alter table production_batch_lines add constraint production_batch_lines_numbers_check check (planned_quantity > 0 and weigh_quantity >= 0 and (actual_quantity is null or actual_quantity >= 0) and (unit_cost is null or unit_cost >= 0))');
        // A cost names where it came from and what it is per; a lot is recorded with the weight it belongs to.
        DB::statement('alter table production_batch_lines add constraint production_batch_lines_cost_check check (unit_cost is null or (cost_uom is not null and unit_cost_source is not null))');
        DB::statement("alter table production_batch_lines add constraint production_batch_lines_lot_check check (lot_number is null or btrim(lot_number) <> '')");

        DB::unprepared(<<<'SQL'
create or replace function fl_guard_lines_of_completed_batch() returns trigger as $$
declare
    batch_id uuid;
begin
    batch_id := case when TG_OP = 'DELETE' then OLD.production_batch_id else NEW.production_batch_id end;
    if exists (select 1 from production_batches where id = batch_id and status = 'completed') then
        raise exception 'production_batch % is completed: its lines are closed (% rejected)', batch_id, TG_OP using errcode = 'restrict_violation';
    end if;
    return case when TG_OP = 'DELETE' then OLD else NEW end;
end;
$$ language plpgsql
SQL);
        DB::unprepared('create trigger production_batch_lines_open before insert or update or delete on production_batch_lines for each row execute function fl_guard_lines_of_completed_batch()');

        Schema::create('production_batch_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('production_batch_id')->constrained('production_batches');
            $table->unsignedInteger('step_no');
            $table->unsignedInteger('check_no');
            $table->string('property');
            $table->exactDecimal('value');
            $table->string('unit');
            $table->string('status');
            $table->string('measured_by');
            $table->timestampTz('measured_at');
            $table->text('note')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['production_batch_id', 'step_no', 'check_no']);
        });

        DB::statement("alter table production_batch_checks add constraint production_batch_checks_status_check check (status in ('PASS','FAIL','UNKNOWN'))");
        DB::statement("alter table production_batch_checks add constraint production_batch_checks_who_check check (btrim(measured_by) <> '')");
        DB::unprepared('create trigger production_batch_checks_append_only before update or delete on production_batch_checks for each row execute function fl_reject_modification()');
        DB::unprepared('create trigger production_batch_checks_no_truncate before truncate on production_batch_checks for each statement execute function fl_reject_modification()');

        Schema::create('production_deviations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('production_batch_id')->constrained('production_batches');
            $table->string('kind');
            $table->string('subject');
            $table->exactDecimal('planned')->nullable();
            $table->exactDecimal('actual')->nullable();
            $table->string('unit')->nullable();
            $table->exactDecimal('deviation_pct')->nullable();
            $table->exactDecimal('threshold_pct')->nullable();
            $table->string('verdict');
            $table->boolean('requires_approval');
            $table->string('approval_status');
            $table->text('reason');
            $table->string('recorded_by_name');
            $table->foreignId('recorded_by')->nullable()->constrained('users');
            $table->timestampTz('recorded_at')->useCurrent();
            $table->string('decided_by_name')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->index(['production_batch_id', 'approval_status']);
        });

        DB::statement("alter table production_deviations add constraint production_deviations_kind_check check (kind in ('weight','process','check','waste','alkali','other'))");
        DB::statement("alter table production_deviations add constraint production_deviations_verdict_check check (verdict in ('none','within','exceeds','threshold_not_set','not_evaluated'))");
        DB::statement("alter table production_deviations add constraint production_deviations_approval_check check (approval_status in ('not_required','pending','approved','rejected') and (requires_approval = (approval_status <> 'not_required')))");
        DB::statement("alter table production_deviations add constraint production_deviations_reason_check check (btrim(reason) <> '' and btrim(recorded_by_name) <> '')");
        DB::statement("alter table production_deviations add constraint production_deviations_decision_check check ((approval_status in ('approved','rejected')) = (decided_at is not null))");

        DB::unprepared(<<<'SQL'
create or replace function fl_guard_production_deviation() returns trigger as $$
declare
    decision_columns text[] := array['approval_status','decided_by_name','decided_by','decided_at','decision_note','updated_at'];
begin
    if TG_OP = 'DELETE' then
        raise exception 'production_deviation % cannot be deleted', OLD.id using errcode = 'restrict_violation';
    end if;
    if OLD.approval_status <> 'pending' then
        raise exception 'production_deviation % is final (% rejected)', OLD.id, TG_OP using errcode = 'restrict_violation';
    end if;
    if (to_jsonb(NEW) - decision_columns) is distinct from (to_jsonb(OLD) - decision_columns) then
        raise exception 'production_deviation % keeps what was recorded: only its decision can be set', OLD.id using errcode = 'restrict_violation';
    end if;
    return NEW;
end;
$$ language plpgsql
SQL);
        DB::unprepared('create trigger production_deviations_guard before update or delete on production_deviations for each row execute function fl_guard_production_deviation()');
    }

    public function down(): void
    {
        Schema::dropIfExists('production_deviations');
        Schema::dropIfExists('production_batch_checks');
        Schema::dropIfExists('production_batch_lines');
        Schema::dropIfExists('production_batches');
        DB::unprepared('drop function if exists fl_guard_production_deviation()');
        DB::unprepared('drop function if exists fl_guard_lines_of_completed_batch()');
        DB::unprepared('drop function if exists fl_guard_completed_batch()');
    }
};
