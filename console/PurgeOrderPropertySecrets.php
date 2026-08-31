<?php namespace Logingrupa\StoreExtender\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\InputOption;
use Logingrupa\StoreExtender\Classes\Event\Order\OrderPropertySecretHandler;

/**
 * Class PurgeOrderPropertySecrets
 *
 * Removes credential keys from the historic order property snapshot.
 *
 * MakeOrder merges the whole checkout user_data array into Order::$property, so a
 * guest registering during checkout had their raw password written to the orders
 * table from 2020-01-31 onwards. OrderPropertySecretHandler stops new rows; this
 * command cleans the ones already stored.
 *
 * Rows are rewritten with a JSON_REMOVE UPDATE rather than through the model: the
 * model path would touch updated_at and fire save events across every affected
 * order, and only this one key is being dropped.
 *
 * @package Logingrupa\StoreExtender\Console
 */
class PurgeOrderPropertySecrets extends Command
{
    const TABLE = 'lovata_orders_shopaholic_orders';
    const CHUNK_SIZE = 500;

    protected $name = 'storeextender:purge-order-property-secrets';
    protected $description = 'Strip password fields from the stored order property JSON';

    public function handle()
    {
        $bDryRun = (bool) $this->option('dry-run');
        $arFieldList = OrderPropertySecretHandler::SECRET_FIELD_LIST;

        $iAffected = $this->countAffected($arFieldList);
        $this->info(sprintf('Orders carrying a credential key: %d', $iAffected));

        if ($iAffected == 0) {
            $this->info('Nothing to purge.');
            return 0;
        }

        if ($bDryRun) {
            $this->warn('Dry run - no rows written.');
            return 0;
        }

        $iCleaned = $this->purge($arFieldList);
        $iRemaining = $this->countAffected($arFieldList);

        $this->info(sprintf('Rows rewritten: %d', $iCleaned));
        $this->info(sprintf('Orders still carrying a credential key: %d', $iRemaining));

        return $iRemaining == 0 ? 0 : 1;
    }

    protected function countAffected($arFieldList)
    {
        $obQuery = DB::table(self::TABLE)->whereNotNull('property');
        $obQuery->where(function ($obWhere) use ($arFieldList) {
            foreach ($arFieldList as $sField) {
                $obWhere->orWhereRaw("JSON_EXTRACT(property, ?) IS NOT NULL", ['$."'.$sField.'"']);
            }
        });

        return $obQuery->count();
    }

    protected function purge($arFieldList)
    {
        $arPathList = [];
        foreach ($arFieldList as $sField) {
            $arPathList[] = '$."'.$sField.'"';
        }
        $sPathBind = implode(', ', array_fill(0, count($arPathList), '?'));

        $iCleaned = 0;
        do {
            $arIdList = DB::table(self::TABLE)
                ->whereNotNull('property')
                ->where(function ($obWhere) use ($arFieldList) {
                    foreach ($arFieldList as $sField) {
                        $obWhere->orWhereRaw("JSON_EXTRACT(property, ?) IS NOT NULL", ['$."'.$sField.'"']);
                    }
                })
                ->limit(self::CHUNK_SIZE)
                ->pluck('id')
                ->all();

            if (empty($arIdList)) {
                break;
            }

            // DB::raw() carries no bindings, so the JSON paths go through a raw
            // statement rather than an update() array.
            $sIdBind = implode(', ', array_fill(0, count($arIdList), '?'));
            DB::update(
                "UPDATE ".self::TABLE." SET property = JSON_REMOVE(property, ".$sPathBind.") WHERE id IN (".$sIdBind.")",
                array_merge($arPathList, $arIdList)
            );

            $iCleaned += count($arIdList);
            $this->output->write('.');
        } while (true);

        $this->output->writeln('');

        return $iCleaned;
    }

    protected function getOptions()
    {
        return [
            ['dry-run', null, InputOption::VALUE_NONE, 'Report the affected row count without writing'],
        ];
    }
}
