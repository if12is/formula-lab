<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** M-08: the ionic character of a material (App\Enums\Ionicity values), null until a person states it. It picks the emulsifier calculation path. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trade_materials', function (Blueprint $table) {
            $table->string('ionicity')->nullable()->after('functional_classes');
        });

        DB::statement("alter table trade_materials add constraint trade_materials_ionicity_check check (ionicity is null or ionicity in ('nonionic','anionic','cationic','amphoteric','other'))");
    }

    public function down(): void
    {
        DB::statement('alter table trade_materials drop constraint if exists trade_materials_ionicity_check');

        Schema::table('trade_materials', function (Blueprint $table) {
            $table->dropColumn('ionicity');
        });
    }
};
