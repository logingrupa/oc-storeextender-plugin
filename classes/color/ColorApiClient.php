<?php namespace Logingrupa\StoreExtender\Classes\Color;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Class ColorApiClient
 *
 * Fetch + cache ONLY for the remote color-lab offers API.
 * Stores body + ETag in Laravel Cache, sends If-None-Match on refresh,
 * extends TTL on 304, and on network error / non-200 / malformed JSON
 * returns the last cached payload (or an empty map). Never throws.
 *
 * Host comes from services.color_lab.host app config (COLOR_LAB_API_HOST
 * env behind it - read via config so a cached production config still
 * resolves it; runtime env() is empty once config:cache has run).
 * Defaults to the local dev host. Grouping is done by OfferColorGrouper.
 *
 * @package Logingrupa\StoreExtender\Classes\Color
 */
class ColorApiClient
{
    const API_PATH = '/api/color-lab/offers.json';
    const DEFAULT_API_HOST = 'https://nailolab.test';

    const CACHE_TTL_SECONDS = 3600;
    const CACHE_TTL_ERROR_SECONDS = 300;

    /**
     * Slowest cold-path response measured against nailolab.com, taken from the
     * nailscosmetics.no box on 2026-08-05: 11.1s while the exporter rebuilt its
     * hourly cache, against a 0.26s steady state over the next five requests.
     */
    const OBSERVED_COLD_EXPORT_SECONDS = 12;

    /**
     * Only the hourly CLI sync holds this client - the storefront reads the
     * local table through ColorMapRepository and never opens a socket - so a
     * generous timeout costs at worst one cron run, never a page render.
     *
     * It MUST stay above OBSERVED_COLD_EXPORT_SECONDS. The sync period and the
     * export's own Cache-Control max-age are both one hour, so runs land on the
     * regenerating cold path often rather than rarely. At the previous 3s this
     * timed out at 3002ms every time and nailscosmetics.no never synced once.
     */
    const REQUEST_TIMEOUT_SECONDS = 30;

    const CACHE_KEY_BODY = 'storeextender.color_api.body';
    const CACHE_KEY_ETAG = 'storeextender.color_api.etag';
    const CACHE_KEY_FRESH = 'storeextender.color_api.fresh';

    /** @var int HTTP status of the last fetch; -1 until one is attempted */
    protected $iLastFetchStatus = -1;

    /**
     * Root key of the payload map this client consumes. Subclasses covering
     * other color-lab endpoints override it (families.json uses 'families').
     *
     * @return string
     */
    protected function payloadRootKey(): string
    {
        return 'offers';
    }

    /**
     * Get the payload map under the root key (offers keyed by bare UUID for
     * this client; families keyed by slug for FamilyApiClient).
     *
     * @return array
     */
    public function getMap(): array
    {
        $arPayload = $this->getPayload();
        $sRootKey = $this->payloadRootKey();
        if (empty($arPayload[$sRootKey]) || !is_array($arPayload[$sRootKey])) {
            return [];
        }

        return $arPayload[$sRootKey];
    }

    /**
     * Get color map keyed by bare offer UUID
     *
     * @return array ['<offerUuid>' => ['family' => string, 'hex' => string, 'hue' => float, 'lightness' => float]]
     */
    public function getColorMap(): array
    {
        return $this->getMap();
    }

    /**
     * Get payload version stamp - changes only when upstream data changes,
     * safe cache invalidation key for anything derived from the color map
     *
     * @return string
     */
    public function getVersion(): string
    {
        $arPayload = $this->getPayload();

        return isset($arPayload['version']) ? (string) $arPayload['version'] : '';
    }

    /**
     * When the color data upstream last changed, as the API reports it
     * (Y-m-d H:i:s in the exporting server's own time).
     *
     * ONE global stamp for the whole payload - there are deliberately no
     * per-offer timestamps. Use it to say WHEN the data changed; use the
     * version stamp to decide WHETHER it changed, since a re-export with no
     * content change moves this and leaves the version alone.
     *
     * @return string empty when the API does not send the field
     */
    public function getLastUpdatedAt(): string
    {
        $arPayload = $this->getPayload();

        return isset($arPayload['last_updated_at']) ? (string) $arPayload['last_updated_at'] : '';
    }

    /**
     * Ask the API now, ignoring the freshness window.
     *
     * getPayload() answers from cache for CACHE_TTL_SECONDS, so a scheduled
     * check running on that same period would keep reporting "nothing
     * changed" for up to an hour after a real change. The request still
     * carries If-None-Match, so an unchanged export costs one 304.
     *
     * @return array
     */
    public function fetchFresh(): array
    {
        return $this->refreshPayload();
    }

    /**
     * HTTP status of the most recent fetch: 200 fresh body, 304 unchanged,
     * 0 network error, -1 nothing fetched yet, otherwise the server's status.
     *
     * Callers need this to tell "the export has not changed" apart from "we
     * could not reach the API", because every failure path here falls back to
     * the last cached body and would otherwise look identical to no change.
     *
     * @return int
     */
    public function getLastFetchStatus(): int
    {
        return $this->iLastFetchStatus;
    }

    /**
     * API host in use, without a trailing slash. Public so a status report can
     * name the environment it just questioned without re-reading the config
     * itself and drifting from what the request actually used.
     *
     * @return string
     */
    public function getHost(): string
    {
        return rtrim((string) Config::get('services.color_lab.host', self::DEFAULT_API_HOST), '/');
    }

    /**
     * Get cached payload, refreshing over HTTP when the TTL window expired
     *
     * @return array
     */
    protected function getPayload(): array
    {
        if (Cache::has(static::CACHE_KEY_FRESH)) {
            $arBody = Cache::get(static::CACHE_KEY_BODY);

            // empty body inside the window = recent failure with cold cache;
            // do NOT refetch per request, wait the retry window out
            return is_array($arBody) ? $arBody : [];
        }

        return $this->refreshPayload();
    }

    /**
     * Refresh payload from the API. Every failure path falls back to the
     * last cached body (or []) and opens a short retry window so an
     * unreachable API cannot slow the storefront down on every request.
     *
     * @return array
     */
    protected function refreshPayload(): array
    {
        $arStaleBody = Cache::get(static::CACHE_KEY_BODY);
        $arStaleBody = is_array($arStaleBody) ? $arStaleBody : [];

        try {
            $obResponse = $this->sendRequest();
        } catch (\Throwable $obException) {
            // silent to caller: offer sheet must render without colors, never 500
            $this->iLastFetchStatus = 0;
            Log::warning('ColorApiClient: request failed - '.$obException->getMessage());
            Cache::put(static::CACHE_KEY_FRESH, true, self::CACHE_TTL_ERROR_SECONDS);

            return $arStaleBody;
        }

        $this->iLastFetchStatus = $obResponse->status();

        if ($obResponse->status() === 304) {
            Cache::put(static::CACHE_KEY_FRESH, true, self::CACHE_TTL_SECONDS);
            Log::info('ColorApiClient: 304 Not Modified, TTL extended');

            return $arStaleBody;
        }

        if (!$obResponse->ok()) {
            Log::warning('ColorApiClient: unexpected status '.$obResponse->status());
            Cache::put(static::CACHE_KEY_FRESH, true, self::CACHE_TTL_ERROR_SECONDS);

            return $arStaleBody;
        }

        return $this->storePayload($obResponse, $arStaleBody);
    }

    /**
     * Validate + store a 200 response body, ETag and fresh flag
     *
     * @param Response $obResponse
     * @param array    $arStaleBody
     * @return array
     */
    protected function storePayload(Response $obResponse, array $arStaleBody): array
    {
        $arBody = $obResponse->json();
        $sRootKey = $this->payloadRootKey();
        if (!is_array($arBody) || !isset($arBody[$sRootKey]) || !is_array($arBody[$sRootKey])) {
            Log::warning('ColorApiClient: malformed JSON payload, keeping last cached body');
            Cache::put(static::CACHE_KEY_FRESH, true, self::CACHE_TTL_ERROR_SECONDS);

            return $arStaleBody;
        }

        Cache::forever(static::CACHE_KEY_BODY, $arBody);
        $sEtag = (string) $obResponse->header('ETag');
        if ($sEtag !== '') {
            Cache::forever(static::CACHE_KEY_ETAG, $sEtag);
        }
        Cache::put(static::CACHE_KEY_FRESH, true, self::CACHE_TTL_SECONDS);

        return $arBody;
    }

    /**
     * Send the GET request, with If-None-Match when an ETag is cached
     *
     * @return Response
     */
    protected function sendRequest(): Response
    {
        $sHost = $this->getHost();
        $obRequest = Http::timeout(self::REQUEST_TIMEOUT_SECONDS)->acceptJson();
        if (str_ends_with($sHost, '.test')) {
            // local dev hosts use self-signed certificates
            $obRequest = $obRequest->withoutVerifying();
        }

        $sEtag = (string) Cache::get(static::CACHE_KEY_ETAG, '');
        if ($sEtag !== '') {
            $obRequest = $obRequest->withHeaders(['If-None-Match' => $sEtag]);
        }

        return $obRequest->get($sHost.static::API_PATH);
    }
}
