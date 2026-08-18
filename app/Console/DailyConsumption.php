<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Controllers\InventoryController;

class DailyConsumption extends Command
{
    protected $signature = 'inventory:daily-consumption';
    protected $description = 'Deduct daily consumption for fish items';

    public function handle(InventoryController $controller)
    {
        $controller->runDailyConsumption();
        $this->info('Daily consumption processed.');
    }
}