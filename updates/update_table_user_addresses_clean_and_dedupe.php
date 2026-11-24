<?php

namespace Logingrupa\StoreExtender\Updates;

use Logingrupa\StoreExtender\Classes\Helper\UserAddressHelper;
use October\Rain\Database\Updates\Migration;
use Schema;

/**
 * Class UpdateTableUserAddressesCleanAndDedupe
 *
 * Cleans legacy checkout JS "undefined"/"NaN" artifacts from Shopaholic
 * lovata_orders_shopaholic_user_addresses rows, drops completely empty rows,
 * and deduplicates exact duplicate rows created before the MakeOrder type-match
 * patch — keeping every unique street and pickup-point address intact.
 */
class UpdateTableUserAddressesCleanAndDedupe extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable(UserAddressHelper::TABLE_NAME)) {
            return;
        }

        UserAddressHelper::cleanAndDedupeTable();
    }

    public function down(): void
    {
        // Data cleanup is one-way; restore from DB backup if rollback is ever needed.
    }
}
