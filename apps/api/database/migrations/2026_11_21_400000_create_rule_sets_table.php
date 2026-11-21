<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rule_sets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // null tenant = global reference rule set
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants');
            // The rule set's id in the rules DSL.
            $table->string('code', 100);
            $table->string('version', 50);
            $table->string('name');
            $table->text('scope_notes')->nullable();
            $table->string('status')->default('draft');
            // sha256 of the canonical JSON of the published rule set (engine hashRuleSet); null while a draft.
            $table->string('hash', 64)->nullable();
            // The rule set's own source; a draft may still wait for one (the rules carry their own).
            $table->foreignUuid('source_id')->nullable()->constrained('sources');
            $table->timestampTz('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['code', 'version']);
        });

        DB::statement("alter table rule_sets add constraint rule_sets_status_check check (status in ('draft','published'))");
        DB::statement("alter table rule_sets add constraint rule_sets_published_check check ((status = 'published') = (hash is not null and published_at is not null))");

        // Published rule sets are immutable, like approved formula versions (ADR 003 #3). Publishing itself is the
        // one allowed update: it runs while the row is still a draft.
        DB::unprepared(<<<'SQL'
create or replace function fl_guard_published_rule_set() returns trigger as $$
begin
    if OLD.status = 'published' then
        raise exception 'rule_set % is published and immutable (% rejected)', OLD.id, TG_OP
            using errcode = 'restrict_violation';
    end if;
    return case when TG_OP = 'DELETE' then OLD else NEW end;
end;
$$ language plpgsql
SQL);
        DB::unprepared('create trigger rule_sets_published_immutable before update or delete on rule_sets for each row execute function fl_guard_published_rule_set()');
    }

    public function down(): void
    {
        DB::unprepared('drop trigger if exists rule_sets_published_immutable on rule_sets');
        DB::unprepared('drop function if exists fl_guard_published_rule_set()');
        Schema::dropIfExists('rule_sets');
    }
};
