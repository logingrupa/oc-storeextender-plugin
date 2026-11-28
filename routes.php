<?php

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Logingrupa\StoreExtender\Classes\Helper\LlmsTxtBuilder;
use Logingrupa\StoreExtender\Classes\Helper\ShopIdMap;
use Logingrupa\StoreExtender\Classes\Pickup\PickupPointFeed;
use Logingrupa\StoreExtender\Classes\Pickup\PickupPointRepository;

Route::get('/llms.txt', function () {
    return (new Response(LlmsTxtBuilder::build(), 200))
        ->header('Content-Type', 'text/plain; charset=utf-8')
        ->header('Cache-Control', 'public, max-age=900');
});

/**
 * The 1C id of every row this shop publishes, against this shop's own path.
 * GET /api/shop-id-map.json -> {products: {<guid>: /p/slug}, categories: {...}}
 *
 * The sibling shops read this to pair their hreflang alternates by identity
 * instead of guessing that a slug is the same on every domain, which stopped
 * being true when the .no slugs were translated on 2026-11-15.
 *
 * Deliberately not behind X-Feed-Token, unlike offers.xml. That feed is gated
 * because it carries prices and stock per user group; this carries slugs and
 * 1C ids, both of which are already public in sitemap.xml and the page URLs,
 * and a token would mean every shop holding a credential for every sibling
 * plus a warm that can fail on rotation. Cacheable and cheap matters more.
 */
Route::get('/api/shop-id-map.json', function () {
    $arMap = ShopIdMap::publishedIdMap();
    $iCount = count($arMap[ShopIdMap::BUCKET_PRODUCTS]) + count($arMap[ShopIdMap::BUCKET_CATEGORIES]);

    // An empty map is a fault worth shouting about: a sibling that caches it
    // would silently stop pairing. 503 keeps the last good copy in its cache.
    return (new JsonResponse($arMap, $iCount === 0 ? 503 : 200, [], JSON_UNESCAPED_UNICODE))
        ->header('Cache-Control', $iCount === 0 ? 'no-store' : 'public, max-age=900');
});

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
