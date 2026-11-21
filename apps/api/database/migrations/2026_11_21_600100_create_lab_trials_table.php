<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_trials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('formula_version_id')->constrained('formula_versions');
            $table->string('code');
            $table->string('status')->default('in_progress');
            $table->exactDecimal('batch_size');
            $table->string('batch_unit');
            $table->date('trial_date');
            $table->string('operator');
            $table->text('notes')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['formula_version_id', 'status']);
        });

        DB::statement("alter table lab_trials add constraint lab_trials_status_check check (status in ('in_progress','completed'))");
        DB::statement('alter table lab_trials add constraint lab_trials_batch_size_check check (batch_size > 0)');
        DB::statement("alter table lab_trials add constraint lab_trials_completed_check check ((status = 'completed') = (completed_at is not null))");

        // A completed trial is a closed record, and no trial is ever deleted (ALCOA+, ADR 003).
        DB::unprepared(<<<'SQL'
create or replace function fl_guard_completed_trial() returns trigger as $$
begin
    if TG_OP = 'DELETE' or OLD.status = 'completed' then
        raise exception 'lab_trial % is closed or cannot be deleted (% rejected)', OLD.id, TG_OP
            using errcode = 'restrict_violation';
    end if;
    return NEW;
end;
$$ language plpgsql
SQL);
        DB::unprepared('create trigger lab_trials_closed before update or delete on lab_trials for each row execute function fl_guard_completed_trial()');
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_trials');
        DB::unprepared('drop function if exists fl_guard_completed_trial()');
    }
};
