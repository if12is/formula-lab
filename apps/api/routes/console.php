<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('idempotency:prune')->daily();
Schedule::command('assistant:expire-consents')->hourly();

// U-05 / U-10: daily encrypted backup; monthly trial restore of the newest one into a throwaway database that is dropped afterwards.
Schedule::command('backup:run')->dailyAt((string) config('backup.schedule.daily_at'))->withoutOverlapping();
Schedule::command('backup:restore-test')
    ->monthlyOn((int) config('backup.schedule.restore_test_day'), (string) config('backup.schedule.restore_test_at'))
    ->withoutOverlapping();
