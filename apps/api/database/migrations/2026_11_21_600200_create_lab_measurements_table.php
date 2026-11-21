<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One measured value of a trial. A value is always present (a missing measurement is a missing row, never a zero),
     * and a row is append-only: a correction is a newer row of the same property.
     */
    public function up(): void
    {
        Schema::create('lab_measurements', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('lab_trial_id')->constrained('lab_trials');
            $table->string('property');
            $table->exactDecimal('value');
            $table->string('unit');
            $table->foreignUuid('lab_protocol_id')->nullable()->constrained('lab_protocols');
            $table->string('method')->nullable();
            $table->string('instrument');
            $table->timestampTz('measured_at');
            $table->string('confidence')->default('L1_coa_batch');
            $table->text('note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['lab_trial_id', 'property']);
        });

        DB::statement("alter table lab_measurements add constraint lab_measurements_confidence_check check (confidence in ('L1_coa_batch','L2_supplier_tds','L3_standard','L4_regulatory_db','L5_reference','L6_community','L7_estimate'))");
        DB::statement("alter table lab_measurements add constraint lab_measurements_method_check check (lab_protocol_id is not null or (method is not null and btrim(method) <> ''))");
        DB::statement("alter table lab_measurements add constraint lab_measurements_instrument_check check (btrim(instrument) <> '')");

        DB::unprepared('create trigger lab_measurements_append_only before update or delete on lab_measurements for each row execute function fl_reject_modification()');
        DB::unprepared('create trigger lab_measurements_no_truncate before truncate on lab_measurements for each statement execute function fl_reject_modification()');

        DB::unprepared(<<<'SQL'
create or replace function fl_guard_measurement_on_closed_trial() returns trigger as $$
begin
    if exists (select 1 from lab_trials where id = NEW.lab_trial_id and status = 'completed') then
        raise exception 'lab_trial % is completed: no more measurements (% rejected)', NEW.lab_trial_id, TG_OP
            using errcode = 'restrict_violation';
    end if;
    return NEW;
end;
$$ language plpgsql
SQL);
        DB::unprepared('create trigger lab_measurements_trial_open before insert on lab_measurements for each row execute function fl_guard_measurement_on_closed_trial()');
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_measurements');
        DB::unprepared('drop function if exists fl_guard_measurement_on_closed_trial()');
    }
};
