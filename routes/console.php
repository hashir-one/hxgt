<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('gateway:expire-orders')->everyMinute()->withoutOverlapping();
Schedule::command('gateway:dispatch-outbox')->everyMinute()->withoutOverlapping();
Schedule::command('gateway:poll-bank-email')->everyMinute()->withoutOverlapping();

Schedule::command('gateway:maintain')->everyMinute()->withoutOverlapping();
Schedule::command('gateway:check-integrity')->dailyAt('02:00')->withoutOverlapping();
