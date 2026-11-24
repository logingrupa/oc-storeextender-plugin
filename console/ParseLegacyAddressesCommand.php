<?php

namespace Logingrupa\StoreExtender\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Logingrupa\StoreExtender\Classes\Helper\LegacyPickupAddressParser;
use Logingrupa\StoreExtender\Classes\Helper\UserAddressHelper;
use Lovata\OrdersShopaholic\Classes\Store\UserAddressListStore;

/**
 * Splits legacy pickup-point strings in lovata_orders_shopaholic_user_addresses.address2
 * into structured address1 / city / postcode / country columns while preserving address2
 * intact for 1C and checkout pickup-point preselection.
 *
 * Existing non-empty structured fields are never overwritten.
 */
class ParseLegacyAddressesCommand extends Command
{
    /** @var string */
    protected $signature = 'storeextender:parse-legacy-addresses
        {--dry-run : Preview parsed updates without modifying the database}';

    /** @var string */
    protected $description = 'Backfill structured address1/city/postcode/country from legacy pickup strings in UserAddress.address2 while keeping address2 intact for 1C';

    public function handle(): int
    {
        if (! Schema::hasTable(UserAddressHelper::TABLE_NAME)) {
            $this->error('Table '.UserAddressHelper::TABLE_NAME.' does not exist.');

            return self::FAILURE;
        }

        $bDryRun = (bool) $this->option('dry-run');
        $iScanned = 0;
        $iUpdated = 0;
        $iSkipped = 0;
        $arSamples = [];
        $arTouchedUserIds = [];

        $obRows = DB::table(UserAddressHelper::TABLE_NAME)
            ->whereNotNull('address2')
            ->where('address2', '!=', '')
            ->orderBy('id')
            ->cursor();

        foreach ($obRows as $obRow) {
            $iScanned++;
            $sCleanAddress2 = UserAddressHelper::cleanValue($obRow->address2);
            if ($sCleanAddress2 === '') {
                $iSkipped++;

                continue;
            }

            $arParsed = LegacyPickupAddressParser::parse($sCleanAddress2);
            $arUpdates = [];

            if ((string) $obRow->address2 !== $sCleanAddress2) {
                $arUpdates['address2'] = $sCleanAddress2;
            }

            foreach (['address1', 'city', 'postcode', 'country'] as $sField) {
                $sCurrent = UserAddressHelper::cleanValue($obRow->{$sField} ?? null);
                if ($sCurrent === '' && $arParsed[$sField] !== '') {
                    $arUpdates[$sField] = $arParsed[$sField];
                }
            }

            if (empty($arUpdates)) {
                $iSkipped++;

                continue;
            }

            $iUpdated++;
            $arTouchedUserIds[(int) $obRow->user_id] = true;

            if (count($arSamples) < 12) {
                $arSamples[] = [
                    'id' => (int) $obRow->id,
                    'address2' => $sCleanAddress2,
                    'address1' => $arUpdates['address1'] ?? UserAddressHelper::cleanValue($obRow->address1 ?? null),
                    'city' => $arUpdates['city'] ?? UserAddressHelper::cleanValue($obRow->city ?? null),
                    'postcode' => $arUpdates['postcode'] ?? UserAddressHelper::cleanValue($obRow->postcode ?? null),
                    'country' => $arUpdates['country'] ?? UserAddressHelper::cleanValue($obRow->country ?? null),
                ];
            }

            if (! $bDryRun) {
                DB::table(UserAddressHelper::TABLE_NAME)
                    ->where('id', (int) $obRow->id)
                    ->update($arUpdates);
            }
        }

        if (! $bDryRun && class_exists(UserAddressListStore::class)) {
            foreach (array_keys($arTouchedUserIds) as $iUserId) {
                UserAddressListStore::instance()->user->clear((int) $iUserId);
            }
        }

        $sMode = $bDryRun ? '[DRY RUN] ' : '';
        $this->info("{$sMode}Scanned {$iScanned} rows with address2: {$iUpdated} updated, {$iSkipped} unchanged (address2 preserved for 1C).");

        if (! empty($arSamples)) {
            $this->table(
                ['ID', 'address1', 'city', 'postcode', 'country', 'address2 (kept for 1C)'],
                array_map(static fn (array $arSample): array => [
                    $arSample['id'],
                    $arSample['address1'],
                    $arSample['city'],
                    $arSample['postcode'],
                    $arSample['country'],
                    mb_strimwidth($arSample['address2'], 0, 55, '...'),
                ], $arSamples)
            );
        }

        return self::SUCCESS;
    }
}
