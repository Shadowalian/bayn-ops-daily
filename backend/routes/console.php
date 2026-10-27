<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Checks admin-configured morning/evening HH:MM each minute
Schedule::command('ops:reminders')->everyMinute()->timezone('Africa/Addis_Ababa');
