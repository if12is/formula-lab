<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Q-08: when Sanad is reached directly (LAN mode) the consume and receive answers have no outbox row to live on. `sanad_sync` keeps
     * what Sanad answered for each step (its own statuses and ids, the attempt count and the last refusal) so the order screen can show
     * where the stock effect stands. It is a record of Sanad's answers, never a stock figure. An approved order may change it (it stays
     * approved while a step is refused, so the same body can be sent again: Sanad's consume/receive are idempotent by order code).
     */
    public function up(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->jsonb('sanad_sync')->nullable();
        });

        $this->replaceGuard(['status', 'sent_at', 'sanad_outbox_id', 'sanad_sync', 'updated_at']);
    }

    public function down(): void
    {
        $this->replaceGuard(['status', 'sent_at', 'sanad_outbox_id', 'updated_at']);
        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropColumn('sanad_sync');
        });
    }

    /** @param  list<string>  $movable  The columns an approved order may still change. */
    private function replaceGuard(array $movable): void
    {
        $list = "array['".implode("','", $movable)."']";

        DB::unprepared(<<<SQL
create or replace function fl_guard_production_order() returns trigger as \$\$
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
        and (to_jsonb(NEW) - {$list}) is distinct from (to_jsonb(OLD) - {$list}) then
        raise exception 'production_order % is approved: only its sending can change (% rejected)', OLD.id, TG_OP using errcode = 'restrict_violation';
    end if;
    return NEW;
end;
\$\$ language plpgsql
SQL);
    }
};
