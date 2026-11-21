<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('rule_set_id')->constrained('rule_sets')->cascadeOnDelete();
            // One rule exactly as the engine's rules DSL reads it (plan/02-specs/rules-dsl.md). The single source of truth:
            // the columns below are generated from it, never written.
            $table->jsonb('rule');
            // Wording for a rule typed in the interface; a rule loaded from a file words itself through its messageKey.
            $table->text('message')->nullable();
            // An inactive rule is left out of the assembled rule set (and of its hash).
            $table->boolean('active')->default(true);
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->unique(['rule_set_id', 'position']);
        });

        // Generated columns so the rules list can search, filter and sort in SQL.
        DB::statement("alter table rules add column rule_key text generated always as (rule ->> 'id') stored");
        DB::statement("alter table rules add column operator text generated always as (rule ->> 'operator') stored");
        DB::statement("alter table rules add column unit text generated always as (rule ->> 'unit') stored");
        DB::statement("alter table rules add column group_key text generated always as (rule ->> 'group') stored");
        DB::statement("alter table rules add column pending_source boolean generated always as ((rule ->> 'pendingSource') = 'true') stored");
        DB::statement('create unique index rules_rule_set_rule_key_unique on rules (rule_set_id, rule_key)');

        DB::unprepared(<<<'SQL'
create or replace function fl_guard_published_rules() returns trigger as $$
begin
    if TG_OP in ('UPDATE', 'DELETE') and exists (
        select 1 from rule_sets where id = OLD.rule_set_id and status = 'published') then
        raise exception 'rules of published rule_set % are immutable (% rejected)', OLD.rule_set_id, TG_OP
            using errcode = 'restrict_violation';
    end if;
    if TG_OP in ('INSERT', 'UPDATE') and exists (
        select 1 from rule_sets where id = NEW.rule_set_id and status = 'published') then
        raise exception 'rules of published rule_set % are immutable (% rejected)', NEW.rule_set_id, TG_OP
            using errcode = 'restrict_violation';
    end if;
    return case when TG_OP = 'DELETE' then OLD else NEW end;
end;
$$ language plpgsql
SQL);
        DB::unprepared('create trigger rules_published_immutable before insert or update or delete on rules for each row execute function fl_guard_published_rules()');
    }

    public function down(): void
    {
        DB::unprepared('drop trigger if exists rules_published_immutable on rules');
        DB::unprepared('drop function if exists fl_guard_published_rules()');
        Schema::dropIfExists('rules');
    }
};
