<?php namespace Logingrupa\StoreExtender\Tests\Unit\Pickup;

use Logingrupa\StoreExtender\Classes\Pickup\DpdPickupPointFeed;
use Logingrupa\StoreExtender\Classes\Pickup\OmnivaPickupPointFeed;
use Logingrupa\StoreExtender\Classes\Pickup\PickupPointFeed;
use PHPUnit\Framework\TestCase;

/**
 * Both carrier feeds normalise to the same point shape. Rows come from the
 * live feeds sampled 2026-09-05; no CMS needed.
 */
class PickupPointFeedTest extends TestCase
{
    public function testDpdRowBecomesPointWithPrefixedLatvianZip()
    {
        $arPoint = (new DpdPickupPointFeed())->normalizeRow([
            'parcelShopType' => 'PickupStation', 'zipCode' => '3104', 'city' => 'TUKUMS',
            'companyName' => 'Paku Skapis TC Aplis', 'parcelShopId' => 'LV90364',
            'street' => 'Eksporta iela 6', 'countryCode' => 'LV', 'houseNo' => '',
        ]);

        $this->assertSame([
            'id' => 'LV90364', 'name' => 'Paku Skapis TC Aplis', 'address' => 'Eksporta iela 6',
            'city' => 'Tukums', 'zip' => 'LV-3104', 'country' => 'LV',
        ], $arPoint);
    }

    public function testDpdEstonianZipStaysPlainAndHouseNumberJoinsTheStreet()
    {
        $arPoint = (new DpdPickupPointFeed())->normalizeRow([
            'parcelShopId' => 'EE10077', 'companyName' => 'Tallinna Nõmme Loodusand', 'street' => 'Jaama',
            'houseNo' => '3', 'countryCode' => 'EE', 'zipCode' => '11621', 'city' => 'Tallinn',
        ]);

        $this->assertSame('Jaama 3', $arPoint['address']);
        $this->assertSame('11621', $arPoint['zip']);
    }

    public function testOmnivaCityFallsBackThroughTheAddressLevelsAndCarriesNoZip()
    {
        $obFeed = new OmnivaPickupPointFeed();

        $arVillage = $obFeed->normalizeRow([
            'ZIP' => '9595', 'NAME' => 'Aglonas TOP pakomāts', 'A0_NAME' => 'LV', 'A1_NAME' => 'Preiļu novads',
            'A2_NAME' => 'Aglonas pagasts', 'A3_NAME' => 'Aglona', 'A5_NAME' => 'Somersētas iela', 'A7_NAME' => '33',
        ]);
        $arTown = $obFeed->normalizeRow([
            'ZIP' => '9950', 'NAME' => 'Aizkraukles T/C Iga pakomāts', 'A0_NAME' => 'LV', 'A1_NAME' => 'Aizkraukles novads',
            'A2_NAME' => 'Aizkraukle', 'A3_NAME' => '', 'A5_NAME' => 'Gaismas iela', 'A7_NAME' => '35',
        ]);

        $this->assertSame(['id' => '9595', 'name' => 'Aglonas TOP pakomāts', 'address' => 'Somersētas iela 33', 'city' => 'Aglona', 'zip' => null, 'country' => 'LV'], $arVillage);
        $this->assertSame('Aizkraukle', $arTown['city']);
        $this->assertSame('Gaismas iela 35', $arTown['address']);
    }

    public function testRowsWithoutIdCityOrCountryAreDropped()
    {
        $arPointList = (new DpdPickupPointFeed())->normalize([
            ['parcelShopId' => '', 'city' => 'Riga', 'countryCode' => 'LV'],
            ['parcelShopId' => 'LV1', 'city' => '', 'countryCode' => 'LV'],
            ['parcelShopId' => 'LV2', 'city' => 'Riga', 'countryCode' => 'Latvia'],
            'not a row',
            ['parcelShopId' => 'LV3', 'city' => 'RĪGA', 'countryCode' => 'lv', 'zipCode' => '1005', 'street' => 'Uriekstes iela', 'houseNo' => '8a'],
        ]);

        $this->assertCount(1, $arPointList);
        $this->assertSame('Rīga', $arPointList[0]['city']);
        $this->assertSame('LV', $arPointList[0]['country']);
        $this->assertSame('Uriekstes iela 8a', $arPointList[0]['address']);
    }

    public function testFactoryKnowsBothCarriersAndRejectsOthers()
    {
        $this->assertInstanceOf(DpdPickupPointFeed::class, PickupPointFeed::make('dpd'));
        $this->assertInstanceOf(OmnivaPickupPointFeed::class, PickupPointFeed::make('omniva'));

        $this->expectException(\InvalidArgumentException::class);
        PickupPointFeed::make('venipak');
    }
}
