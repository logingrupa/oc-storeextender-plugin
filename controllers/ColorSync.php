<?php namespace Logingrupa\StoreExtender\Controllers;

use Artisan;
use Backend\Classes\Controller;
use BackendMenu;
use Flash;
use Lang;
use Logingrupa\StoreExtender\Classes\Color\ColorMapRepository;
use Logingrupa\StoreExtender\Models\OfferColor;
use System\Classes\SettingsManager;

/**
 * Class ColorSync
 *
 * Settings page for the offer color sync: shows what the local table holds
 * against the color-lab export and runs the sync command on demand. The
 * schedule pulls once a day, so this button is how a curation change on
 * nailolab reaches the shop the same hour.
 *
 * @package Logingrupa\StoreExtender\Controllers
 */
class ColorSync extends Controller
{
    /** Two API clients with a 30 second budget each, above the default FPM limit. */
    const SYNC_TIME_LIMIT_SECONDS = 120;

    /** @var array */
    public $requiredPermissions = ['logingrupa.storeextender.color_sync'];

    public function __construct()
    {
        parent::__construct();

        BackendMenu::setContext('October.System', 'system', 'settings');
        SettingsManager::setContext('Logingrupa.StoreExtender', 'color_sync');
    }

    public function index(): void
    {
        $this->pageTitle = 'logingrupa.storeextender::lang.color_sync.label';
        $this->vars['arStatus'] = $this->getStatus();
    }

    /**
     * Run the sync command and re-render the status block with its output
     *
     * @return array
     */
    public function onSync(): array
    {
        set_time_limit(self::SYNC_TIME_LIMIT_SECONDS);

        $iExitCode = Artisan::call('storeextender:sync-offer-colors');
        $sOutput = trim(Artisan::output());

        if ($iExitCode === 0) {
            Flash::success(Lang::get('logingrupa.storeextender::lang.color_sync.success'));
        } else {
            Flash::error(Lang::get('logingrupa.storeextender::lang.color_sync.failure'));
        }

        return [
            '#colorSyncStatus' => $this->makePartial('status', [
                'arStatus' => $this->getStatus(),
                'sOutput' => $sOutput,
            ]),
        ];
    }

    /**
     * @return array
     */
    protected function getStatus(): array
    {
        $obRepository = new ColorMapRepository();

        return [
            'version' => $obRepository->getVersion(),
            'export_updated_at' => $obRepository->getExportUpdatedAt(),
            'synced_at' => $obRepository->getSyncedAt(),
            'row_count' => OfferColor::query()->count(),
        ];
    }
}
