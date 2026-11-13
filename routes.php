<?php

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use Logingrupa\StoreExtender\Classes\Helper\ShopIdMap;
use Logingrupa\StoreExtender\Classes\Helper\SiblingShopMap;
use Logingrupa\StoreExtender\Classes\Pickup\PickupPointFeed;
use Logingrupa\StoreExtender\Classes\Pickup\PickupPointRepository;

/**
 * Pickup points of one carrier in one country for the checkout dropdown.
 * GET /api/pickup-points/{carrier}/{country} -> {carrier, country, points: [...]}
 */
Route::get('/api/pickup-points/{carrier}/{country}', function (string $sCarrier, string $sCountry) {
    if (!array_key_exists($sCarrier, PickupPointFeed::FEED_CLASS_LIST) || preg_match('/^[A-Za-z]{2}$/', $sCountry) !== 1) {
        return new JsonResponse(['message' => 'Unknown carrier or country'], 404);
    }

    $arPointList = (new PickupPointRepository())->get($sCarrier, $sCountry);

    return (new JsonResponse([
        'carrier' => $sCarrier,
        'country' => strtoupper($sCountry),
        'points'  => $arPointList,
    ], $arPointList === [] ? 503 : 200, [], JSON_UNESCAPED_UNICODE))
        ->header('Cache-Control', $arPointList === [] ? 'no-store' : 'public, max-age=3600');
})->where(['carrier' => '[a-z]+', 'country' => '[A-Za-z]{2}']);

/**
 * Which 1C external_id lives at which absolute URL on this shop, read by the
 * sibling shops so their hreflang block names this shop by id instead of by an
 * identical path. A plugin route, so the URL is the same on all three shops
 * whatever their site route prefix.
 *
 * GET /api/shop-id-map -> {shop, generated_at, map: {external_id: url}}
 *
 * The body is cached for ten minutes under a key carrying the catalogue stamp,
 * so a crawler does not re-run the build and a catalogue change makes the old
 * body unreachable. The cold build is two catalogue queries over roughly 900
 * rows - cheap, not free, which is why the warm command primes it.
 * no-store keeps an edge cache from holding a map past a change.
 */
Route::get(SiblingShopMap::ROUTE_PATH, function () {
    return (new JsonResponse(
        ShopIdMap::publishedBody(),
        200,
        [],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ))->header('Cache-Control', 'no-store');
});
