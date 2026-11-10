<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('nodes:sync-stats')
    ->everyFiveMinutes();

Schedule::command('nodes:backup')
    ->dailyAt('04:00');
