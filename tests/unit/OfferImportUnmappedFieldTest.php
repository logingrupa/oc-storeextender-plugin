<?php namespace Logingrupa\StoreExtender\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Logingrupa\StoreExtender\Classes\Event\Offer\ExtendOfferImportMetadata;

/**
 * The PASS 1 metadata listeners normalise what the 1C feed sent, nothing more.
 *
 * A field the XML mapping does not name belongs to whoever else writes it: on
 * .lt and .no that is GoodsReceived, which owns offer quantity. The fixers used
 * to write their key unconditionally, so an unmapped quantity arrived as an
 * empty string, and Shopaholic's setQuantityField() only guards against null:
 * (int) '' is 0. The 2026-09-08 import zeroed 1523 offers on .no (62458 units)
 * and 620 on .lt before the run was stopped. An absent key now stays absent.
 */
class OfferImportUnmappedFieldTest extends TestCase
{
    /**
     * @return ExtendOfferImportMetadata
     */
    protected function makeHandler()
    {
        return new class extends ExtendOfferImportMetadata
        {
            public function run(array $arImportData): array
            {
                $arImportData = $this->fixQuantity($arImportData);
                $arImportData = $this->fixVariationText($arImportData);
                $arImportData = $this->fixWeight($arImportData);
                $arImportData = $this->fixHeight($arImportData);
                $arImportData = $this->fixLength($arImportData);

                return $this->fixWidth($arImportData);
            }
        };
    }

    public function testUnmappedFieldsAreNotWritten()
    {
        $arResult = $this->makeHandler()->run(['external_id' => 'o-1', 'name' => 'Gel Polish']);

        $this->assertArrayNotHasKey('quantity', $arResult, 'GoodsReceived owns the stock where quantity is unmapped.');
        $this->assertArrayNotHasKey('variation', $arResult);
        $this->assertArrayNotHasKey('weight', $arResult);
        $this->assertArrayNotHasKey('height', $arResult);
        $this->assertArrayNotHasKey('length', $arResult);
        $this->assertArrayNotHasKey('width', $arResult);
    }

    public function testMappedQuantityKeepsItsValueWithoutSpaces()
    {
        $arResult = $this->makeHandler()->run(['external_id' => 'o-1', 'quantity' => '1 234']);

        $this->assertSame('1234', $arResult['quantity']);
    }

    public function testMappedButEmptyQuantityStillReachesTheModel()
    {
        $arResult = $this->makeHandler()->run(['external_id' => 'o-1', 'quantity' => null]);

        $this->assertArrayHasKey('quantity', $arResult, 'A mapped field follows the feed, absent node included.');
        $this->assertSame('', $arResult['quantity']);
    }

    public function testMappedDimensionsKeepNumbersAndDropText()
    {
        $arResult = $this->makeHandler()->run([
            'external_id' => 'o-1',
            'weight'      => '50',
            'height'      => 'Leopard tonis',
            'length'      => '28',
            'width'       => '',
        ]);

        $this->assertSame('50', $arResult['weight']);
        $this->assertNull($arResult['height'], 'A characteristic name is not a measurement.');
        $this->assertSame('28', $arResult['length']);
        $this->assertNull($arResult['width']);
    }

    public function testMappedVariationKeepsTheTextInBrackets()
    {
        $arResult = $this->makeHandler()->run([
            'external_id' => 'o-1',
            'variation'   => 'Gel Polish UV/LED, 12ml (SANDY 14)',
        ]);

        $this->assertSame('SANDY 14', $arResult['variation']);
    }
}
