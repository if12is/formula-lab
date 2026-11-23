<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Q-10: the manufacturing steps of a formula version and their check points. They are part of the version: they are in its
     * content hash and, once the version is approved, they can never change (the same guard as formula_lines). A step's
     * numbers (temperature, mixing speed, time) are the formulator's own and stay null when not given; a check point's limits
     * must name their source, otherwise they stay null ("not evaluated").
     */
    public function up(): void
    {
        Schema::create('formula_process_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('formula_version_id')->constrained('formula_versions');
            $table->unsignedInteger('step_no');
            $table->string('kind');
            $table->string('phase')->nullable();
            $table->foreignUuid('formula_line_id')->nullable()->constrained('formula_lines');
            $table->exactDecimal('temperature_c')->nullable();
            $table->exactDecimal('mixing_speed_rpm')->nullable();
            $table->exactDecimal('duration_minutes')->nullable();
            $table->text('note')->nullable();

            $table->unique(['formula_version_id', 'step_no']);
        });

        DB::statement("alter table formula_process_steps add constraint formula_process_steps_kind_check check (kind in ('heat','cool','add','mix','homogenize','hold','adjust','check'))");
        DB::statement("alter table formula_process_steps add constraint formula_process_steps_phase_check check (phase is null or phase in ('water','oil','cool_down'))");
        DB::statement('alter table formula_process_steps add constraint formula_process_steps_numbers_check check ((temperature_c is null or temperature_c >= 0) and (mixing_speed_rpm is null or mixing_speed_rpm > 0) and (duration_minutes is null or duration_minutes > 0))');
        DB::statement("alter table formula_process_steps add constraint formula_process_steps_add_line_check check (kind <> 'add' or formula_line_id is not null)");

        Schema::create('formula_process_checks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('formula_process_step_id')->constrained('formula_process_steps');
            $table->unsignedInteger('check_no');
            $table->string('property');
            $table->string('unit');
            $table->exactDecimal('limit_min')->nullable();
            $table->exactDecimal('limit_max')->nullable();
            $table->foreignUuid('source_id')->nullable()->constrained('sources');
            $table->string('confidence')->nullable();

            $table->unique(['formula_process_step_id', 'check_no']);
        });

        DB::statement("alter table formula_process_checks add constraint formula_process_checks_confidence_check check (confidence is null or confidence in ('L1_coa_batch','L2_supplier_tds','L3_standard','L4_regulatory_db','L5_reference','L6_community','L7_estimate'))");
        DB::statement('alter table formula_process_checks add constraint formula_process_checks_range_check check (limit_min is null or limit_max is null or limit_min <= limit_max)');
        // A limit is a number somebody stands behind: it names its source and confidence, or it is not there.
        DB::statement('alter table formula_process_checks add constraint formula_process_checks_sourced_limits_check check ((limit_min is null and limit_max is null) or (source_id is not null and confidence is not null))');

        DB::unprepared(<<<'SQL'
create or replace function fl_guard_approved_process_steps() returns trigger as $$
declare
    version_id uuid;
begin
    version_id := case when TG_OP = 'DELETE' then OLD.formula_version_id else NEW.formula_version_id end;
    if exists (select 1 from formula_versions where id = version_id and status = 'approved')
        or (TG_OP = 'UPDATE' and exists (select 1 from formula_versions where id = OLD.formula_version_id and status = 'approved')) then
        raise exception 'formula_process_steps of approved version % are immutable (% rejected)', version_id, TG_OP
            using errcode = 'restrict_violation';
    end if;
    return case when TG_OP = 'DELETE' then OLD else NEW end;
end;
$$ language plpgsql
SQL);
        DB::unprepared('create trigger formula_process_steps_approved_immutable before insert or update or delete on formula_process_steps for each row execute function fl_guard_approved_process_steps()');

        DB::unprepared(<<<'SQL'
create or replace function fl_guard_approved_process_checks() returns trigger as $$
declare
    step_id uuid;
begin
    step_id := case when TG_OP = 'DELETE' then OLD.formula_process_step_id else NEW.formula_process_step_id end;
    if exists (
        select 1 from formula_process_steps s join formula_versions v on v.id = s.formula_version_id
        where s.id = step_id and v.status = 'approved') then
        raise exception 'formula_process_checks of an approved version are immutable (% rejected)', TG_OP
            using errcode = 'restrict_violation';
    end if;
    return case when TG_OP = 'DELETE' then OLD else NEW end;
end;
$$ language plpgsql
SQL);
        DB::unprepared('create trigger formula_process_checks_approved_immutable before insert or update or delete on formula_process_checks for each row execute function fl_guard_approved_process_checks()');
    }

    public function down(): void
    {
        Schema::dropIfExists('formula_process_checks');
        Schema::dropIfExists('formula_process_steps');
        DB::unprepared('drop function if exists fl_guard_approved_process_checks()');
        DB::unprepared('drop function if exists fl_guard_approved_process_steps()');
    }
};
