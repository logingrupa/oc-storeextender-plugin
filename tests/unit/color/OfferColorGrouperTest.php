<?php namespace Logingrupa\StoreExtender\Tests\Unit\Color;

use PHPUnit\Framework\TestCase;
use Logingrupa\StoreExtender\Classes\Color\OfferColorGrouper;

/**
 * OfferColorGrouper is pure (no HTTP, no cache, no DB), so offers are plain
 * stubs exposing the two properties the grouper reads: id + external_id.
 */
class OfferColorGrouperTest extends TestCase
{
    /** @var OfferColorGrouper */
    protected $obGrouper;

    public function setUp(): void
    {
        $this->obGrouper = new OfferColorGrouper();
    }

    protected function makeOffer(int $iOfferId, string $sExternalId): object
    {
        return new class($iOfferId, $sExternalId) {
            public $id;
            public $external_id;

            public function __construct(int $iOfferId, string $sExternalId)
            {
                $this->id = $iOfferId;
                $this->external_id = $sExternalId;
            }
        };
    }

    protected function getOfferIdList(array $arGroup): array
    {
        return array_map(function ($obOffer) {
            return $obOffer->id;
        }, $arGroup['arOfferList']);
    }

    public function testHappyPathGroupsByFamilyAndOrdersByHueThenLightness()
    {
        $arOfferList = [
            $this->makeOffer(1, 'uuid-pink-dark'),
            $this->makeOffer(2, 'uuid-green-a'),
            $this->makeOffer(3, 'uuid-pink-light'),
            $this->makeOffer(4, 'uuid-green-b'),
        ];
        $arColorMap = [
            'uuid-pink-dark'  => ['family' => 'Pink', 'hex' => '#EC407A', 'hue' => 354.2, 'lightness' => 0.34],
            'uuid-pink-light' => ['family' => 'Pink', 'hex' => '#F8BBD0', 'hue' => 340.0, 'lightness' => 0.86],
            'uuid-green-a'    => ['family' => 'Green', 'hex' => '#66BB6A', 'hue' => 122.0, 'lightness' => 0.57],
            'uuid-green-b'    => ['family' => 'Green', 'hex' => '#2E7D32', 'hue' => 120.0, 'lightness' => 0.34],
        ];

        $arGroupList = $this->obGrouper->group($arOfferList, $arColorMap);

        $this->assertCount(2, $arGroupList);
        // Green (min hue 120) before Pink (min hue 340)
        $this->assertSame('Green', $arGroupList[0]['sFamily']);
        $this->assertSame('Pink', $arGroupList[1]['sFamily']);
        // inside group: hue asc
        $this->assertSame([4, 2], $this->getOfferIdList($arGroupList[0]));
        $this->assertSame([3, 1], $this->getOfferIdList($arGroupList[1]));
        // representative swatch = hex of first offer after sort
        $this->assertSame('#2E7D32', $arGroupList[0]['sHex']);
        $this->assertSame('#F8BBD0', $arGroupList[1]['sHex']);
    }

    public function testInvalidHexBecomesNullSwatch()
    {
        $arOfferList = [$this->makeOffer(1, 'uuid-bad-hex')];
        $arColorMap = [
            'uuid-bad-hex' => ['family' => 'Red', 'hex' => 'url(javascript:1)', 'hue' => 0.0, 'lightness' => 0.5],
        ];

        $arGroupList = $this->obGrouper->group($arOfferList, $arColorMap);

        $this->assertSame('Red', $arGroupList[0]['sFamily']);
        $this->assertNull($arGroupList[0]['sHex']);
    }

    public function testOfferMissingFromColorMapLandsInFinalOtherGroup()
    {
        $arOfferList = [
            $this->makeOffer(1, 'uuid-unknown'),
            $this->makeOffer(2, 'uuid-green'),
            $this->makeOffer(3, ''),
        ];
        $arColorMap = [
            'uuid-green' => ['family' => 'Green', 'hex' => '#66BB6A', 'hue' => 120.0, 'lightness' => 0.5],
        ];

        $arGroupList = $this->obGrouper->group($arOfferList, $arColorMap);

        $this->assertCount(2, $arGroupList);
        $this->assertSame('Green', $arGroupList[0]['sFamily']);
        $this->assertSame(OfferColorGrouper::OTHER_FAMILY, $arGroupList[1]['sFamily']);
        $this->assertSame([1, 3], $this->getOfferIdList($arGroupList[1]));
    }

    public function testEmptyColorMapReturnsSingleUngroupedResultInOriginalOrder()
    {
        $arOfferList = [
            $this->makeOffer(7, 'uuid-a'),
            $this->makeOffer(3, 'uuid-b'),
            $this->makeOffer(9, 'uuid-c'),
        ];

        $arGroupList = $this->obGrouper->group($arOfferList, []);

        $this->assertCount(1, $arGroupList);
        $this->assertNull($arGroupList[0]['sFamily']);
        $this->assertSame([7, 3, 9], $this->getOfferIdList($arGroupList[0]));
    }

    public function testEqualHueOrdersByLightnessThenOfferIdDeterministically()
    {
        $arOfferList = [
            $this->makeOffer(5, 'uuid-c'),
            $this->makeOffer(2, 'uuid-a'),
            $this->makeOffer(9, 'uuid-b'),
        ];
        $arColorMap = [
            'uuid-a' => ['family' => 'Red', 'hex' => '#E53935', 'hue' => 0.0, 'lightness' => 0.5],
            'uuid-b' => ['family' => 'Red', 'hex' => '#EF5350', 'hue' => 0.0, 'lightness' => 0.5],
            'uuid-c' => ['family' => 'Red', 'hex' => '#FFCDD2', 'hue' => 0.0, 'lightness' => 0.9],
        ];

        $arFirstRun = $this->obGrouper->group($arOfferList, $arColorMap);
        $arSecondRun = $this->obGrouper->group(array_reverse($arOfferList), $arColorMap);

        // equal hue + lightness -> offer id ascending, identical for any input order
        $this->assertSame([2, 9, 5], $this->getOfferIdList($arFirstRun[0]));
        $this->assertSame([2, 9, 5], $this->getOfferIdList($arSecondRun[0]));
    }

    public function testCompositeExternalIdMatchesOnBareOfferUuid()
    {
        // legacy 1C rows store "parentUuid#offerUuid" - API keys are bare offer UUIDs
        $arOfferList = [$this->makeOffer(1, 'parent-uuid#offer-uuid')];
        $arColorMap = [
            'offer-uuid' => ['family' => 'Blue', 'hex' => '#1E88E5', 'hue' => 210.0, 'lightness' => 0.5],
        ];

        $arGroupList = $this->obGrouper->group($arOfferList, $arColorMap);

        $this->assertSame('Blue', $arGroupList[0]['sFamily']);
        $this->assertSame([1], $this->getOfferIdList($arGroupList[0]));
    }

    public function testMalformedColorEntryLandsInOtherGroup()
    {
        $arOfferList = [
            $this->makeOffer(1, 'uuid-no-hue'),
            $this->makeOffer(2, 'uuid-green'),
        ];
        $arColorMap = [
            'uuid-no-hue' => ['family' => 'Pink', 'hex' => '#EC407A'],
            'uuid-green'  => ['family' => 'Green', 'hex' => '#66BB6A', 'hue' => 120.0, 'lightness' => 0.5],
        ];

        $arGroupList = $this->obGrouper->group($arOfferList, $arColorMap);

        $this->assertCount(2, $arGroupList);
        $this->assertSame(OfferColorGrouper::OTHER_FAMILY, $arGroupList[1]['sFamily']);
        $this->assertSame([1], $this->getOfferIdList($arGroupList[1]));
    }
}
