<?php namespace Logingrupa\StoreExtender\Console;

use Illuminate\Console\Command;
use Logingrupa\StoreExtender\Classes\Helper\CategorySorter;

/**
 * Sort category siblings by the `code` column (1C order prefixes).
 * Usage: php artisan storeextender:sort_categories
 */
class SortCategories extends Command
{
    protected $signature = 'storeextender:sort_categories';
    protected $description = 'Sort category tree siblings by code (1C A/B/C/1/2/3 prefixes)';

    public function handle()
    {
        $this->info('Sorting categories by code prefix...');
        $iMoves = CategorySorter::sort();
        $this->info("Done. Moves performed: {$iMoves}");

        return 0;
    }
}
