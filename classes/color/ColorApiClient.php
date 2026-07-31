<?php namespace Logingrupa\StoreExtender\Classes\Color;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
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
 * Host comes from COLOR_LAB_API_HOST env (production), defaults to the
 * local dev host. Grouping is done by OfferColorGrouper - not here.
 *
 * @package Logingrupa\StoreExtender\Classes\Color
 */
class ColorApiClient
{
    const API_PATH = '/api/color-lab/offers.json';
    const DEFAULT_API_HOST = 'https://nailolab.test';

    const CACHE_TTL_SECONDS = 3600;
    const CACHE_TTL_ERROR_SECONDS = 300;
    const REQUEST_TIMEOUT_SECONDS = 3;

    const CACHE_KEY_BODY = 'storeextender.color_api.body';
    const CACHE_KEY_ETAG = 'storeextender.color_api.etag';
    const CACHE_KEY_FRESH = 'storeextender.color_api.fresh';

    /**
     * Get color map keyed by bare offer UUID
     *
     * @return array ['<offerUuid>' => ['family' => string, 'hex' => string, 'hue' => float, 'lightness' => float]]
     */
    public function getColorMap(): array
    {
        $arPayload = $this->getPayload();
        if (empty($arPayload['offers']) || !is_array($arPayload['offers'])) {
            return [];
        }

        return $arPayload['offers'];
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
     * Get cached payload, refreshing over HTTP when the TTL window expired
     *
     * @return array
     */
    protected function getPayload(): array
    {
        if (Cache::has(self::CACHE_KEY_FRESH)) {
            $arBody = Cache::get(self::CACHE_KEY_BODY);

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
        $arStaleBody = Cache::get(self::CACHE_KEY_BODY);
        $arStaleBody = is_array($arStaleBody) ? $arStaleBody : [];

        try {
            $obResponse = $this->sendRequest();
        } catch (\Throwable $obException) {
            // silent to caller: offer sheet must render without colors, never 500
            Log::warning('ColorApiClient: request failed - '.$obException->getMessage());
            Cache::put(self::CACHE_KEY_FRESH, true, self::CACHE_TTL_ERROR_SECONDS);

            return $arStaleBody;
        }

        if ($obResponse->status() === 304) {
            Cache::put(self::CACHE_KEY_FRESH, true, self::CACHE_TTL_SECONDS);
            Log::info('ColorApiClient: 304 Not Modified, TTL extended');

            return $arStaleBody;
        }

        if (!$obResponse->ok()) {
            Log::warning('ColorApiClient: unexpected status '.$obResponse->status());
            Cache::put(self::CACHE_KEY_FRESH, true, self::CACHE_TTL_ERROR_SECONDS);

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
        if (!is_array($arBody) || !isset($arBody['offers']) || !is_array($arBody['offers'])) {
            Log::warning('ColorApiClient: malformed JSON payload, keeping last cached body');
            Cache::put(self::CACHE_KEY_FRESH, true, self::CACHE_TTL_ERROR_SECONDS);

            return $arStaleBody;
        }

        Cache::forever(self::CACHE_KEY_BODY, $arBody);
        $sEtag = (string) $obResponse->header('ETag');
        if ($sEtag !== '') {
            Cache::forever(self::CACHE_KEY_ETAG, $sEtag);
        }
        Cache::put(self::CACHE_KEY_FRESH, true, self::CACHE_TTL_SECONDS);

        return $arBody;
    }

    /**
     * Send the GET request, with If-None-Match when an ETag is cached
     *
     * @return Response
     */
    protected function sendRequest(): Response
    {
        $sHost = rtrim((string) env('COLOR_LAB_API_HOST', self::DEFAULT_API_HOST), '/');
        $obRequest = Http::timeout(self::REQUEST_TIMEOUT_SECONDS)->acceptJson();
        if (str_ends_with($sHost, '.test')) {
            // local dev hosts use self-signed certificates
            $obRequest = $obRequest->withoutVerifying();
        }

        $sEtag = (string) Cache::get(self::CACHE_KEY_ETAG, '');
        if ($sEtag !== '') {
            $obRequest = $obRequest->withHeaders(['If-None-Match' => $sEtag]);
        }

        return $obRequest->get($sHost.self::API_PATH);
    }
}
