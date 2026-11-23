<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * S-16. One row per acceptance of the terms of use: which version and which wording (hash), when (from the injected clock, never the
     * database default) and by whom. There is no sign-in until U-01, so user_id is nullable and the acceptance is tied to the browser
     * session by a hash of its id; once accounts exist the user's own rows are what counts. Append-only: a new version is a new row.
     */
    public function up(): void
    {
        Schema::create('terms_acceptances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('session_key', 64)->nullable();
            $table->string('terms_version', 60);
            $table->string('terms_hash', 64);
            $table->timestampTz('accepted_at');

            $table->index(['user_id', 'terms_version']);
            $table->index(['session_key', 'terms_version']);
        });

        DB::unprepared('create trigger terms_acceptances_append_only before update or delete on terms_acceptances for each row execute function fl_reject_modification()');
        DB::unprepared('create trigger terms_acceptances_no_truncate before truncate on terms_acceptances for each statement execute function fl_reject_modification()');
    }

    public function down(): void
    {
        Schema::dropIfExists('terms_acceptances');
    }
};
