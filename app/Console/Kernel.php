protected function schedule(Schedule $schedule)
{
    $schedule->command('inventory:daily-consumption')->dailyAt('00:00');
}