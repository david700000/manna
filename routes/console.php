<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('orders:process-delayed')->cron('*/14 * * * *');

// Cancel orders that are still unpaid after 24 hours (runs every hour)
Schedule::command('orders:cancel-unpaid')->hourly();
