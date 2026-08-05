<?php namespace Logingrupa\StoreExtender\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Logingrupa\StoreExtender\Classes\Color\ColorApiClient;
use Logingrupa\StoreExtender\Classes\Color\ColorMapRepository;
use Logingrupa\StoreExtender\Models\OfferColor;

/**
 * Class SyncOfferColors
 *
 * Pull the color-lab offer color map from nailolab into the local
 * logingrupa_storeextender_offer_colors table. The storefront reads only the
 * table (via ColorMapRepository), so production never blocks on the API.
 * Scheduled hourly; the client sends If-None-Match, unchanged data costs one
 * 304 round trip.
 *
 * @package Logingrupa\StoreExtender\Console
 */
class SyncOfferColors extends Command
{
    const UPSERT_CHUNK_SIZE = 500;

    /** @var string */
    protected $signature = 'storeextender:sync-offer-colors
        {--force : Re-import even when the export has not changed}
        {--status : Report sync state and exit without writing anything}';

    /** @var string */
    protected $description = 'Sync offer color families from the color-lab API into the local table';

    public function handle(): int
    {
        $obApiClient = new ColorApiClient();
        $obRepository = new ColorMapRepository();

        // Go to the network every run. Inside the client's freshness window a
        // cached payload answers "nothing changed" for up to an hour after a
        // real change, which is precisely the blind spot a scheduled check
        // exists to close. If-None-Match keeps an unchanged export at one 304.
        $obApiClient->fetchFresh();
        $sRemoteVersion = $obApiClient->getVersion();
        $sRemoteUpdatedAt = $obApiClient->getLastUpdatedAt();
        $bReachable = in_array($obApiClient->getLastFetchStatus(), [200, 304], true);

        if ($this->option('status')) {
            return $this->reportStatus($obApiClient, $obRepository, $sRemoteVersion, $sRemoteUpdatedAt, $bReachable);
        }

        if (!$this->option('force') && $this->isUpToDate($obRepository, $sRemoteVersion, $bReachable)) {
            $this->info(sprintf(
                'Already in sync: export %s unchanged since our import at %s.',
                $sRemoteUpdatedAt !== '' ? $sRemoteUpdatedAt : '(no timestamp)',
                $obRepository->getSyncedAt() !== '' ? $obRepository->getSyncedAt() : '(unknown)'
            ));

            return self::SUCCESS;
        }

        $arColorMap = $obApiClient->getColorMap();

        // Fail-safe: an unreachable API or empty payload must never wipe the
        // local table - the storefront keeps serving the last good data set
        if (empty($arColorMap)) {
            $this->warn('Color API returned no data - local table left untouched.');

            return self::FAILURE;
        }

        $arRowList = $this->buildRowList($arColorMap);
        if (empty($arRowList)) {
            $this->warn('Color API payload contained no valid entries - local table left untouched.');

            return self::FAILURE;
        }

        $iDeletedCount = 0;
        DB::transaction(function () use ($arRowList, &$iDeletedCount) {
            foreach (array_chunk($arRowList, self::UPSERT_CHUNK_SIZE) as $arChunk) {
                OfferColor::upsert($arChunk, ['offer_uuid'], ['family', 'hex', 'hue', 'lightness', 'updated_at']);
            }

            $iDeletedCount = OfferColor::query()
                ->whereNotIn('offer_uuid', array_column($arRowList, 'offer_uuid'))
                ->delete();
        });

        $obRepository->markSynced($sRemoteVersion, $sRemoteUpdatedAt);

        $this->info(sprintf(
            'Synced %d offer colors (%d removed), export updated %s.',
            count($arRowList),
            $iDeletedCount,
            $sRemoteUpdatedAt !== '' ? $sRemoteUpdatedAt : '(no timestamp)'
        ));

        return self::SUCCESS;
    }

    /**
     * Whether the local table already holds this export.
     *
     * The version stamp is the authority on WHETHER anything changed; the
     * export timestamp only says WHEN, and moves on a re-export that changed
     * nothing. Two cases deliberately report "not up to date": an API we could
     * not reach proves nothing, and a table that has been emptied has to be
     * refilled even when the stamps agree.
     *
     * @param ColorMapRepository $obRepository
     * @param string             $sRemoteVersion
     * @param bool               $bReachable
     * @return bool
     */
    protected function isUpToDate(ColorMapRepository $obRepository, string $sRemoteVersion, bool $bReachable): bool
    {
        if (!$bReachable || $sRemoteVersion === '') {
            return false;
        }

        if ($sRemoteVersion !== $obRepository->getVersion()) {
            return false;
        }

        return OfferColor::query()->exists();
    }

    /**
     * Print what the export says against what we last imported, and exit
     * non-zero on drift so a cron or monitor can alert on it. Writes nothing.
     *
     * @param ColorApiClient     $obApiClient
     * @param ColorMapRepository $obRepository
     * @param string             $sRemoteVersion
     * @param string             $sRemoteUpdatedAt
     * @param bool               $bReachable
     * @return int
     */
    protected function reportStatus(
        ColorApiClient $obApiClient,
        ColorMapRepository $obRepository,
        string $sRemoteVersion,
        string $sRemoteUpdatedAt,
        bool $bReachable
    ): int {
        $iRowCount = OfferColor::query()->count();
        $sLocalVersion = $obRepository->getVersion();

        $this->line('Offer color sync status');
        $this->line(sprintf('  api                  %s (HTTP %d)', $obApiClient->getHost(), $obApiClient->getLastFetchStatus()));
        $this->line(sprintf('  export version       %s', $sRemoteVersion !== '' ? $sRemoteVersion : '(none)'));
        $this->line(sprintf('  export last updated  %s', $sRemoteUpdatedAt !== '' ? $sRemoteUpdatedAt : '(none)'));
        $this->line(sprintf('  imported version     %s', $sLocalVersion !== '' ? $sLocalVersion : '(never synced)'));
        $this->line(sprintf('  imported export from %s', $obRepository->getExportUpdatedAt() !== '' ? $obRepository->getExportUpdatedAt() : '(unknown)'));
        $this->line(sprintf('  imported at          %s', $obRepository->getSyncedAt() !== '' ? $obRepository->getSyncedAt() : '(never)'));
        $this->line(sprintf('  rows in local table  %d', $iRowCount));

        if (!$bReachable) {
            $this->error('  VERDICT              api unreachable, cannot tell whether we are in sync');

            return self::FAILURE;
        }

        if ($this->isUpToDate($obRepository, $sRemoteVersion, $bReachable)) {
            $this->info('  VERDICT              in sync');

            return self::SUCCESS;
        }

        $this->warn('  VERDICT              STALE, the export has moved since our last import');

        return self::FAILURE;
    }

    /**
     * Validate + normalize API entries into upsert rows. Invalid entries are
     * skipped and counted, never imported half-broken.
     *
     * @param array $arColorMap ['<offerUuid>' => ['family' => string, 'hex' => string, 'hue' => float, 'lightness' => float]]
     * @return array
     */
    protected function buildRowList(array $arColorMap): array
    {
        $arRowList = [];
        $iSkippedCount = 0;
        foreach ($arColorMap as $sOfferUuid => $arColor) {
            $sOfferUuid = (string) $sOfferUuid;
            $bValid = $sOfferUuid !== ''
                && strlen($sOfferUuid) <= 64
                && is_array($arColor)
                && !empty($arColor['family'])
                && is_string($arColor['family'])
                && isset($arColor['hue'], $arColor['lightness'])
                && is_numeric($arColor['hue'])
                && is_numeric($arColor['lightness']);
            if (!$bValid) {
                $iSkippedCount++;
                continue;
            }

            $sHex = isset($arColor['hex']) && is_string($arColor['hex']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $arColor['hex']) === 1
                ? $arColor['hex']
                : null;

            $arRowList[] = [
                'offer_uuid' => $sOfferUuid,
                'family'     => mb_substr($arColor['family'], 0, 64),
                'hex'        => $sHex,
                'hue'        => round((float) $arColor['hue'], 2),
                'lightness'  => round((float) $arColor['lightness'], 4),
            ];
        }

        if ($iSkippedCount > 0) {
            $this->warn(sprintf('Skipped %d invalid entries.', $iSkippedCount));
        }

        return $arRowList;
    }
}
