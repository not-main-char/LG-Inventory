<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('inventory:daily-consumption')->daily();

// Keep the price book on today's DA Region I (Ilocos) prices. Three attempts a
// day: the DA tables occasionally time out at dawn, and one failure used to
// leave the whole farm on a stale list until the next morning.
Schedule::command('prices:import-region1')->cron('30 6,12,18 * * *')->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
