<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('substances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('cas')->nullable();
            $table->string('inci')->nullable();
            $table->string('name_en')->nullable();
            $table->string('name_ar')->nullable();
            $table->exactDecimal('mw')->nullable();
            $table->string('formula')->nullable();
            $table->timestamps();
        });

        // A CAS number identifies one substance; several rows may have no CAS yet.
        DB::statement('create unique index substances_cas_unique on substances (cas) where cas is not null');
    }

    public function down(): void
    {
        Schema::dropIfExists('substances');
    }
};
