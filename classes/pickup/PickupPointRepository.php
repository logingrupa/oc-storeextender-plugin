<?php namespace Logingrupa\StoreExtender\Classes\Pickup;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Normalised pickup points per carrier, kept as one JSON file per carrier on
 * the local disk (storage/app/pickup-points/{carrier}.json).
 *
 * The file is the durable copy: it survives cache:clear and deploys, and it
 * is what the checkout serves when the carrier feed is down. A file older
 * than MAX_AGE_SECONDS is refreshed on the next read; a failed refresh keeps
 * the stale file and logs a warning. Never throws to the checkout.
 */
class PickupPointRepository
{
    const DISK = 'local';
    const DIRECTORY = 'pickup-points';
    const MAX_AGE_SECONDS = 86400;
    const REQUEST_TIMEOUT_SECONDS = 30;

    /**
     * Points of one carrier in one country, freshest copy available.
     *
     * @param string $sCarrier
     * @param string $sCountry ISO 3166-1 alpha-2
     * @return array
     */
    public function get(string $sCarrier, string $sCountry): array
    {
        $sCountry = strtoupper($sCountry);
        if (preg_match('/^[A-Z]{2}$/', $sCountry) !== 1) {
            throw new \InvalidArgumentException('Country must be an ISO 3166-1 alpha-2 code, got "'.$sCountry.'"');
        }

        $arStored = $this->readStored($sCarrier);
        if ($arStored === null || $this->isExpired($arStored)) {
            $arStored = $this->refresh($sCarrier) ?? $arStored ?? ['points' => []];
        }

        return array_values(array_filter($arStored['points'], function (array $arPoint) use ($sCountry) {
            return $arPoint['country'] === $sCountry;
        }));
    }

    /**
     * Fetch the carrier feed and replace the stored file. Null when the fetch
     * or the payload failed; the previous file is then left in place.
     *
     * @param string $sCarrier
     * @return array|null
     */
    public function refresh(string $sCarrier): ?array
    {
        $obFeed = PickupPointFeed::make($sCarrier);

        try {
            $obResponse = Http::timeout(self::REQUEST_TIMEOUT_SECONDS)->get($obFeed->getUrl());
        } catch (\Throwable $obException) {
            Log::warning('PickupPointRepository: '.$sCarrier.' feed request failed - '.$obException->getMessage());

            return null;
        }

        if (!$obResponse->ok()) {
            Log::warning('PickupPointRepository: '.$sCarrier.' feed returned HTTP '.$obResponse->status());

            return null;
        }

        $arRowList = $obResponse->json();
        if (!is_array($arRowList) || $arRowList === []) {
            Log::warning('PickupPointRepository: '.$sCarrier.' feed payload is not a JSON list');

            return null;
        }

        $arPointList = $obFeed->normalize($arRowList);
        if ($arPointList === []) {
            Log::warning('PickupPointRepository: '.$sCarrier.' feed normalised to zero points, keeping the stored file');

            return null;
        }

        $arStored = [
            'carrier'    => $sCarrier,
            'fetched_at' => time(),
            'points'     => $arPointList,
        ];
        Storage::disk(self::DISK)->put($this->path($sCarrier), json_encode($arStored, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $arStored;
    }

    /**
     * @param string $sCarrier
     * @return array|null
     */
    public function readStored(string $sCarrier): ?array
    {
        $obDisk = Storage::disk(self::DISK);
        if (!$obDisk->exists($this->path($sCarrier))) {
            return null;
        }

        $arStored = json_decode((string) $obDisk->get($this->path($sCarrier)), true);
        if (!is_array($arStored) || !isset($arStored['points']) || !is_array($arStored['points'])) {
            return null;
        }

        return $arStored;
    }

    /**
     * @param array $arStored
     * @return bool
     */
    protected function isExpired(array $arStored): bool
    {
        return (time() - (int) ($arStored['fetched_at'] ?? 0)) > self::MAX_AGE_SECONDS;
    }

    /**
     * @param string $sCarrier
     * @return string
     */
    protected function path(string $sCarrier): string
    {
        return self::DIRECTORY.'/'.$sCarrier.'.json';
    }
}
