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
    protected $signature = 'storeextender:sync-offer-colors';

    /** @var string */
    protected $description = 'Sync offer color families from the color-lab API into the local table';

    public function handle(): int
    {
        $obApiClient = new ColorApiClient();
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

        (new ColorMapRepository())->markSynced($obApiClient->getVersion());

        $this->info(sprintf('Synced %d offer colors (%d removed).', count($arRowList), $iDeletedCount));

        return self::SUCCESS;
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
