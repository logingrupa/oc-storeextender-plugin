<?php

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
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
