<?php namespace Logingrupa\StoreExtender\Tests\Unit;

use Logingrupa\Storeextender\Components\OfferSheet;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The strip windowing, which decides WHICH shades a product page draws.
 *
 * These methods used to take a list of materialized OfferItems, which meant a
 * 230-shade product had to build 230 items to choose 12 - and meant this
 * arithmetic could not be tested without a database, so it never was. They
 * now take plain rows (id + colour family) and the items are made for the
 * dozen that actually render, which puts the whole decision inside reach of a
 * unit test.
 *
 * No CMS: the component is instantiated without its constructor, because
 * nothing here touches the controller or the component properties.
 */
class OfferSheetWindowTest extends TestCase
{
    protected OfferSheet $obComponent;

    protected ReflectionClass $obReflection;

    protected function setUp(): void
    {
        $this->obReflection = new ReflectionClass(OfferSheet::class);
        $this->obComponent = $this->obReflection->newInstanceWithoutConstructor();
    }

    /**
     * @return mixed
     */
    protected function call(string $sMethod, array $arArgumentList)
    {
        $obMethod = $this->obReflection->getMethod($sMethod);
        $obMethod->setAccessible(true);

        return $obMethod->invokeArgs($this->obComponent, $arArgumentList);
    }

    /**
     * @return array [['iOfferId' => int, 'sFamily' => string|null]]
     */
    protected function makeRowList(int $iCount, ?string $sFamily = null): array
    {
        $arRowList = [];
        for ($iIndex = 0; $iIndex < $iCount; $iIndex++) {
            $arRowList[] = ['iOfferId' => 1000 + $iIndex, 'sFamily' => $sFamily];
        }

        return $arRowList;
    }

    /**
     * @return array
     */
    protected function readOfferIdList(array $arRowList): array
    {
        return array_column($arRowList, 'iOfferId');
    }

    public function testWindowKeepsFiveShadesBeforeTheSelectedOne()
    {
        $arRowList = $this->makeRowList(230);

        $arWindow = $this->call('getWindowedRowList', [$arRowList, 1100]); // index 100

        $this->assertCount(OfferSheet::INLINE_LIMIT, $arWindow);
        $this->assertSame(1095, $arWindow[0]['iOfferId']);
        $this->assertSame(1106, $arWindow[count($arWindow) - 1]['iOfferId']);
        $this->assertContains(1100, $this->readOfferIdList($arWindow));
    }

    public function testWindowClampsToTheHeadOfTheList()
    {
        $arRowList = $this->makeRowList(230);

        // index 2 has nothing like five shades before it
        $arWindow = $this->call('getWindowedRowList', [$arRowList, 1002]);

        $this->assertCount(OfferSheet::INLINE_LIMIT, $arWindow);
        $this->assertSame(1000, $arWindow[0]['iOfferId']);
        $this->assertContains(1002, $this->readOfferIdList($arWindow));
    }

    public function testWindowClampsToTheTailOfTheList()
    {
        $arRowList = $this->makeRowList(230);

        $arWindow = $this->call('getWindowedRowList', [$arRowList, 1229]); // last

        $this->assertCount(OfferSheet::INLINE_LIMIT, $arWindow);
        $this->assertSame(1229, $arWindow[count($arWindow) - 1]['iOfferId']);
        $this->assertContains(1229, $this->readOfferIdList($arWindow));
    }

    public function testWindowFallsBackToTheHeadWhenNothingIsSelected()
    {
        $arRowList = $this->makeRowList(230);

        $arWindow = $this->call('getWindowedRowList', [$arRowList, 0]);

        $this->assertSame(1000, $arWindow[0]['iOfferId']);
        $this->assertCount(OfferSheet::INLINE_LIMIT, $arWindow);
    }

    public function testWindowOfAShortListIsTheWholeList()
    {
        $arRowList = $this->makeRowList(5);

        $arWindow = $this->call('getWindowedRowList', [$arRowList, 1003]);

        $this->assertCount(5, $arWindow);
    }

    public function testFamilyListHoldsEveryShadeOfThatFamilyAndNoOther()
    {
        $arRowList = array_merge(
            $this->makeRowList(3, 'Red'),
            array_map(function (array $arRow) {
                return ['iOfferId' => $arRow['iOfferId'] + 100, 'sFamily' => 'Blue'];
            }, $this->makeRowList(20, 'Blue'))
        );

        $arFamilyList = $this->call('getFamilyRowList', [$arRowList, 'Blue']);

        // deliberately NOT capped at INLINE_LIMIT: a family strip shows the
        // whole family, which is the difference between it and a window
        $this->assertCount(20, $arFamilyList);
        foreach ($arFamilyList as $arRow) {
            $this->assertSame('Blue', $arRow['sFamily']);
        }
    }

    public function testAnExplicitOfferSnapsTheFilterToItsOwnFamily()
    {
        $arRowList = [
            ['iOfferId' => 1, 'sFamily' => 'Red'],
            ['iOfferId' => 2, 'sFamily' => 'Blue'],
        ];

        // a shared URL naming offer 2 AND family Red: the offer wins
        $sFamily = $this->call('resolveActiveFamily', [$arRowList, 'Red', 2]);

        $this->assertSame('Blue', $sFamily);
    }

    public function testAnUnknownFamilyFallsBackToNoFilter()
    {
        $arRowList = [['iOfferId' => 1, 'sFamily' => 'Red']];

        $this->assertSame('', $this->call('resolveActiveFamily', [$arRowList, 'Chartreuse', 0]));
        $this->assertSame('', $this->call('resolveActiveFamily', [$arRowList, '', 0]));
        $this->assertSame('Red', $this->call('resolveActiveFamily', [$arRowList, 'Red', 0]));
    }

    /**
     * The batch handler and the strip window must not disagree about how many
     * shades "the visible window" is, or the page would prime a set that is
     * not the set it renders.
     */
    public function testTheBatchCeilingIsTheStripWindow()
    {
        $arRowList = $this->makeRowList(230);
        $arWindow = $this->call('getWindowedRowList', [$arRowList, 1100]);

        $this->assertCount(OfferSheet::INLINE_LIMIT, $arWindow);
        $this->assertSame(12, OfferSheet::INLINE_LIMIT);
    }
}
