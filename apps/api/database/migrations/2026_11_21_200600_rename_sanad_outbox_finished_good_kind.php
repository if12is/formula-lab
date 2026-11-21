<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Contract 3.1: the outbox kind `finished_good` is now `product_upsert` (it carries a product upsert
     * of any item_kind, so the old name was misleading). Existing rows are renamed, the check follows.
     */
    public function up(): void
    {
        DB::statement('alter table sanad_outbox drop constraint sanad_outbox_kind_check');
        DB::table('sanad_outbox')->where('kind', 'finished_good')->update(['kind' => 'product_upsert']);
        DB::statement("alter table sanad_outbox add constraint sanad_outbox_kind_check check (kind in ('production_order','product_upsert','wholesale_prices'))");
    }

    public function down(): void
    {
        DB::statement('alter table sanad_outbox drop constraint sanad_outbox_kind_check');
        DB::table('sanad_outbox')->where('kind', 'product_upsert')->update(['kind' => 'finished_good']);
        DB::statement("alter table sanad_outbox add constraint sanad_outbox_kind_check check (kind in ('production_order','finished_good','wholesale_prices'))");
    }
};
