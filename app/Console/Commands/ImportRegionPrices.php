<?php

namespace App\Console\Commands;

use App\Support\PriceBook;
use Illuminate\Console\Command;

/**
 * Pull the day's prevailing retail prices for Region I (Ilocos) from the DA
 * Bantay Presyo tables and keep them as the farm's price book.
 *
 * Run it any time; it is cheap and safe to repeat. The Sales page reads the
 * saved copy, so a sale still gets a price when this has not run for a while
 * or the DA site is unreachable.
 */
class ImportRegionPrices extends Command
{
    protected $signature = 'prices:import-region1 {--show : Print every imported commodity}';

    protected $description = 'Import DA Bantay Presyo Region I (Ilocos) retail prices into the price book';

    public function handle(PriceBook $book)
    {
        $this->info('Reading ' . PriceBook::SOURCE . ' ' . PriceBook::REGION_NAME . ' prices...');

        $fetched = $book->fetchRegionI();

        if (empty($fetched['items'])) {
            $this->error('Nothing came back from the DA price tables. The previous price list was left untouched.');

            return self::FAILURE;
        }

        $book->store($fetched);

        $this->info('Imported ' . count($fetched['items']) . ' commodities as of ' . $fetched['asOf'] . '.');

        if ($this->option('show')) {
            $this->table(
                ['Commodity', 'Spec', 'Group', 'Price/kg', 'Low', 'High', 'Markets'],
                array_map(fn ($i) => [
                    $i['name'], $i['spec'], $i['group'],
                    number_format($i['perKg'], 2), number_format($i['low'], 2), number_format($i['high'], 2), $i['markets'],
                ], $fetched['items'])
            );
        }

        return self::SUCCESS;
    }
}
