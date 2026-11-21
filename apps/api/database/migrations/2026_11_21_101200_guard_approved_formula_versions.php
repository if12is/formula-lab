<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** ADR 003 #3: an approved version, and its lines, can never be changed or deleted. */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
create or replace function fl_guard_approved_version() returns trigger as $$
begin
    if OLD.status = 'approved' then
        raise exception 'formula_version % is approved and immutable (% rejected)', OLD.id, TG_OP
            using errcode = 'restrict_violation';
    end if;
    return case when TG_OP = 'DELETE' then OLD else NEW end;
end;
$$ language plpgsql
SQL);
        DB::unprepared('create trigger formula_versions_approved_immutable before update or delete on formula_versions for each row execute function fl_guard_approved_version()');

        DB::unprepared(<<<'SQL'
create or replace function fl_guard_approved_lines() returns trigger as $$
begin
    if TG_OP in ('UPDATE', 'DELETE') and exists (
        select 1 from formula_versions where id = OLD.formula_version_id and status = 'approved') then
        raise exception 'formula_lines of approved version % are immutable (% rejected)', OLD.formula_version_id, TG_OP
            using errcode = 'restrict_violation';
    end if;
    if TG_OP in ('INSERT', 'UPDATE') and exists (
        select 1 from formula_versions where id = NEW.formula_version_id and status = 'approved') then
        raise exception 'formula_lines of approved version % are immutable (% rejected)', NEW.formula_version_id, TG_OP
            using errcode = 'restrict_violation';
    end if;
    return case when TG_OP = 'DELETE' then OLD else NEW end;
end;
$$ language plpgsql
SQL);
        DB::unprepared('create trigger formula_lines_approved_immutable before insert or update or delete on formula_lines for each row execute function fl_guard_approved_lines()');
    }

    public function down(): void
    {
        DB::unprepared('drop trigger if exists formula_lines_approved_immutable on formula_lines');
        DB::unprepared('drop trigger if exists formula_versions_approved_immutable on formula_versions');
        DB::unprepared('drop function if exists fl_guard_approved_lines()');
        DB::unprepared('drop function if exists fl_guard_approved_version()');
    }
};
