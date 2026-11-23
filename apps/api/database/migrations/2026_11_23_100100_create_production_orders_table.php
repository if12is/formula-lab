<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Q-01: a production order is an approved formula version plus a batch size. It carries a SNAPSHOT of the version (lines,
     * process steps, hash) so it stays what it was whatever happens to the formula later. Once approved only its
     * status / sent_at / sanad_outbox_id move; once sent to Sanad it is closed. No stock lives here: the order is what
     * Sanad is asked to consume and receive (sanad-contract-v1.md section 3.1).
     */
    public function up(): void
    {
        Schema::create('production_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('code');
            $table->string('batch_number');
            $table->foreignUuid('formula_version_id')->constrained('formula_versions');
            $table->string('formula_hash', 64);
            $table->jsonb('snapshot');
            $table->exactDecimal('batch_size');
            $table->string('batch_unit');
            $table->exactDecimal('scale_resolution_g');
            $table->exactDecimal('units_count')->nullable();
            $table->date('planned_on');
            $table->date('expiry_date')->nullable();
            $table->string('branch_code')->nullable();
            $table->string('status')->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->string('sanad_outbox_id')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'batch_number']);
            $table->index(['formula_version_id', 'status']);
        });

        DB::statement("alter table production_orders add constraint production_orders_status_check check (status in ('draft','approved','sent'))");
        DB::statement("alter table production_orders add constraint production_orders_unit_check check (batch_unit in ('kg','g'))");
        DB::statement('alter table production_orders add constraint production_orders_numbers_check check (batch_size > 0 and scale_resolution_g > 0 and (units_count is null or units_count > 0))');
        DB::statement("alter table production_orders add constraint production_orders_state_check check ((status = 'draft') = (approved_at is null) and ((status = 'sent') = (sent_at is not null)))");

        DB::unprepared(<<<'SQL'
create or replace function fl_guard_production_order() returns trigger as $$
begin
    if TG_OP = 'DELETE' then
        if OLD.status <> 'draft' then
            raise exception 'production_order % is % and cannot be deleted', OLD.id, OLD.status using errcode = 'restrict_violation';
        end if;
        return OLD;
    end if;
    if OLD.status = 'sent' then
        raise exception 'production_order % was sent to Sanad and is immutable (% rejected)', OLD.id, TG_OP using errcode = 'restrict_violation';
    end if;
    if OLD.status = 'approved'
        and (to_jsonb(NEW) - array['status','sent_at','sanad_outbox_id','updated_at']) is distinct from (to_jsonb(OLD) - array['status','sent_at','sanad_outbox_id','updated_at']) then
        raise exception 'production_order % is approved: only its sending can change (% rejected)', OLD.id, TG_OP using errcode = 'restrict_violation';
    end if;
    return NEW;
end;
$$ language plpgsql
SQL);
        DB::unprepared('create trigger production_orders_guard before update or delete on production_orders for each row execute function fl_guard_production_order()');
    }

    public function down(): void
    {
        Schema::dropIfExists('production_orders');
        DB::unprepared('drop function if exists fl_guard_production_order()');
    }
};
