<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A viscosity reading may state the salt % w/w that was added to the batch when it was taken (O-03): those readings are
     * the points of the formula's own salt curve. Null for every other reading. The table stays append-only.
     */
    public function up(): void
    {
        Schema::table('lab_measurements', function (Blueprint $table) {
            $table->exactDecimal('salt_pct')->nullable()->after('unit');
        });

        DB::statement("alter table lab_measurements add constraint lab_measurements_salt_pct_check check (salt_pct is null or (property = 'viscosity' and salt_pct >= 0))");
    }

    public function down(): void
    {
        DB::statement('alter table lab_measurements drop constraint if exists lab_measurements_salt_pct_check');
        Schema::table('lab_measurements', function (Blueprint $table) {
            $table->dropColumn('salt_pct');
        });
    }
};
