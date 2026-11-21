<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->string('table_name');
            $table->string('record_id');
            $table->string('action');
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            // Plain column, no FK: the trail must outlive the user row.
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->text('reason')->nullable();
            $table->timestampTz('at')->useCurrent();

            $table->index(['table_name', 'record_id']);
        });

        DB::statement("alter table audit_log add constraint audit_log_action_check check (action in ('create','update','delete'))");

        // Shared by every append-only table (ALCOA+, ADR 003): reject UPDATE, DELETE, TRUNCATE.
        DB::unprepared(<<<'SQL'
create or replace function fl_reject_modification() returns trigger as $$
begin
    raise exception '% is append-only: % is not allowed', TG_TABLE_NAME, TG_OP
        using errcode = 'restrict_violation';
end;
$$ language plpgsql
SQL);
        DB::unprepared('create trigger audit_log_append_only before update or delete on audit_log for each row execute function fl_reject_modification()');
        DB::unprepared('create trigger audit_log_no_truncate before truncate on audit_log for each statement execute function fl_reject_modification()');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
        DB::unprepared('drop function if exists fl_reject_modification()');
    }
};
