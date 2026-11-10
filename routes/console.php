<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('inventory:daily-consumption')->daily();

// Keep the price book on today's DA Region I (Ilocos) prices.
Schedule::command('prices:import-region1')->dailyAt('06:30');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
