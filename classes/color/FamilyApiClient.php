<?php namespace Logingrupa\StoreExtender\Classes\Color;

/**
 * Class FamilyApiClient
 *
 * Fetch + cache for the color-lab FAMILY taxonomy API (families.json):
 * per family a stable slug, localized names, per-locale synonyms and a
 * canonical hex. Same ETag / TTL / never-throw properties as the offers
 * client it extends - only the endpoint, the cache keys and the payload
 * root differ.
 *
 * @package Logingrupa\StoreExtender\Classes\Color
 */
class FamilyApiClient extends ColorApiClient
{
    const API_PATH = '/api/color-lab/families.json';

    const CACHE_KEY_BODY = 'storeextender.family_api.body';
    const CACHE_KEY_ETAG = 'storeextender.family_api.etag';
    const CACHE_KEY_FRESH = 'storeextender.family_api.fresh';

    /**
     * @return string
     */
    protected function payloadRootKey(): string
    {
        return 'families';
    }

    /**
     * Get family map keyed by stable family slug
     *
     * @return array ['<slug>' => ['name' => string, 'names' => array, 'synonyms' => array, 'hex' => string]]
     */
    public function getFamilyMap(): array
    {
        return $this->getMap();
    }
}
