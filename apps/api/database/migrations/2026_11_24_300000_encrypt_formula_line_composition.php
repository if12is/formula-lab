<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * U-02: formula composition encrypted at rest with a per-tenant key.
 *
 * - tenants.wrapped_data_key: the tenant's data key, wrapped by the master key from the environment.
 * - formula_lines.trade_material_id / percent_ww / addition_temp_c become text (they hold "enc:v1:..." values). This drops what a
 *   database can no longer check on ciphertext: the foreign key and index on trade_material_id and the 0..100 range check (the form
 *   request still enforces the range; the non-null check for a non-q.s. line stays).
 * - The approved-lines guard still refuses every change to an approved version's lines, except the in-place sealing done by
 *   `crypto:encrypt-compositions`: an UPDATE inside a transaction that sets fl.reencrypt = on and changes nothing but the encrypted columns.
 *
 * Existing rows stay as they are until `php artisan crypto:encrypt-compositions` has run (see plan/02-specs/composition-encryption.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->text('wrapped_data_key')->nullable();
        });

        Schema::table('formula_lines', function (Blueprint $table) {
            $table->dropForeign(['trade_material_id']);
            $table->dropIndex(['trade_material_id']);
        });
        DB::statement('alter table formula_lines drop constraint formula_lines_percent_range_check');
        DB::statement('alter table formula_lines alter column trade_material_id type text using trade_material_id::text');
        DB::statement('alter table formula_lines alter column percent_ww type text using percent_ww::text');
        DB::statement('alter table formula_lines alter column addition_temp_c type text using addition_temp_c::text');

        DB::unprepared(<<<'SQL'
create or replace function fl_guard_approved_lines() returns trigger as $$
begin
    if TG_OP = 'UPDATE'
        and current_setting('fl.reencrypt', true) = 'on'
        and NEW.id = OLD.id
        and NEW.formula_version_id = OLD.formula_version_id
        and NEW.line_no = OLD.line_no
        and NEW.is_qs is not distinct from OLD.is_qs
        and NEW.phase is not distinct from OLD.phase then
        return NEW;
    end if;
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
    }

    /** Only possible while no column holds ciphertext (decrypt first): the casts below fail on "enc:v1:" values. */
    public function down(): void
    {
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

        DB::statement('alter table formula_lines alter column addition_temp_c type numeric using addition_temp_c::numeric');
        DB::statement('alter table formula_lines alter column percent_ww type numeric using percent_ww::numeric');
        DB::statement('alter table formula_lines alter column trade_material_id type uuid using trade_material_id::uuid');
        DB::statement('alter table formula_lines add constraint formula_lines_percent_range_check check (percent_ww is null or (percent_ww >= 0 and percent_ww <= 100))');
        Schema::table('formula_lines', function (Blueprint $table) {
            $table->foreign('trade_material_id')->references('id')->on('trade_materials');
            $table->index('trade_material_id');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('wrapped_data_key');
        });
    }
};
