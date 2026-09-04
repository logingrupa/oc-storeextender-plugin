<?php namespace Logingrupa\StoreExtender\Console;

use Illuminate\Console\Command;
use Logingrupa\StoreExtender\Classes\Pickup\PickupPointFeed;
use Logingrupa\StoreExtender\Classes\Pickup\PickupPointRepository;

/**
 * Pull every carrier's pickup point feed into storage/app/pickup-points so the
 * checkout never fetches a carrier feed inside a customer request.
 * Usage: php artisan storeextender:refresh-pickup-points [--carrier=dpd]
 */
class RefreshPickupPoints extends Command
{
    /** @var string */
    protected $signature = 'storeextender:refresh-pickup-points
        {--carrier= : Refresh one carrier only (dpd, omniva)}';

    /** @var string */
    protected $description = 'Refresh the stored DPD and Omniva pickup point lists from the carrier feeds';

    public function handle(): int
    {
        $sOnlyCarrier = (string) $this->option('carrier');
        $arCarrierList = $sOnlyCarrier !== '' ? [$sOnlyCarrier] : array_keys(PickupPointFeed::FEED_CLASS_LIST);

        $obRepository = new PickupPointRepository();
        $iExitCode = 0;
        foreach ($arCarrierList as $sCarrier) {
            $arStored = $obRepository->refresh($sCarrier);
            if ($arStored === null) {
                $this->error($sCarrier.': refresh failed, stored file left as is');
                $iExitCode = 1;
                continue;
            }
            $this->info($sCarrier.': '.count($arStored['points']).' points stored');
        }

        return $iExitCode;
    }
}
