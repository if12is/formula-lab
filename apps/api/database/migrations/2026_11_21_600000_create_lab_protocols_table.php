<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Test protocol templates (O-06): reference data seeded from config/lab.php. */
    public function up(): void
    {
        Schema::create('lab_protocols', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('property');
            $table->string('unit');
            $table->string('instrument');
            $table->jsonb('steps');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_protocols');
    }
};
