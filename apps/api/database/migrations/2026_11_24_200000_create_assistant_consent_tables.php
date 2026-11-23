<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| P-10: the consent of a browser session to send formula content to the model provider, and the log of what categories were (or were refused to be)
| sent. Both are append-only: a revoke is a new row, never an update of the grant.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_consent_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('session_key', 64);
            $table->string('event', 10);
            $table->jsonb('categories');
            $table->string('end_reason', 20)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->timestampTz('occurred_at');

            $table->index(['session_key', 'occurred_at']);
        });

        Schema::create('assistant_disclosures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('session_key', 64);
            $table->string('surface', 60);
            $table->jsonb('categories');
            $table->string('outcome', 10);
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->timestampTz('occurred_at');

            $table->index(['session_key', 'occurred_at']);
        });

        DB::statement("alter table assistant_consent_events add constraint assistant_consent_events_event_check check (event in ('granted','revoked'))");
        DB::statement("alter table assistant_consent_events add constraint assistant_consent_events_reason_check check ((event = 'revoked' and end_reason in ('user','session_ended','expired')) or (event = 'granted' and end_reason is null))");
        DB::statement("alter table assistant_disclosures add constraint assistant_disclosures_outcome_check check (outcome in ('sent','refused'))");

        foreach (['assistant_consent_events', 'assistant_disclosures'] as $table) {
            DB::unprepared("create trigger {$table}_append_only before update or delete on {$table} for each row execute function fl_reject_modification()");
            DB::unprepared("create trigger {$table}_no_truncate before truncate on {$table} for each statement execute function fl_reject_modification()");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_disclosures');
        Schema::dropIfExists('assistant_consent_events');
    }
};
