<?php namespace Logingrupa\StoreExtender\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Logingrupa\StoreExtender\Classes\Event\Currency\WholeNumberPriceFormatter;
use Lovata\Shopaholic\Classes\Helper\CurrencyHelper;
use Logingrupa\StoreExtender\Classes\Helper\WholeNumberCurrencyConfig;

/**
 * Tests for WholeNumberPriceFormatter::formatWholeNumberPrice()
 *
 * These tests mock CurrencyHelper to isolate the formatting logic.
 * They verify the bug fix for prices >= 1000 (space-separated thousands).
 */
class WholeNumberPriceFormatterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setActiveCurrency('NOK');
    }

    public function testFormatAppendsCommaDashForWholeNumber()
    {
        $this->assertSame('225,-', WholeNumberPriceFormatter::formatWholeNumberPrice(225.0));
    }

    public function testFormatRoundsCorrectly()
    {
        $this->assertSame('226,-', WholeNumberPriceFormatter::formatWholeNumberPrice(225.6));
    }

    public function testFormatHandlesZero()
    {
        $this->assertSame('0,-', WholeNumberPriceFormatter::formatWholeNumberPrice(0));
    }

    public function testFormatHandlesLargeNumbers()
    {
        $this->assertSame('1 225,-', WholeNumberPriceFormatter::formatWholeNumberPrice(1225));
    }

    public function testFormatHandlesStringWithSpaces()
    {
        $this->assertSame('1 225,-', WholeNumberPriceFormatter::formatWholeNumberPrice('1 225'));
    }

    public function testFormatHandlesNegativePrice()
    {
        $this->assertSame('-225,-', WholeNumberPriceFormatter::formatWholeNumberPrice(-225.0));
    }

    /**
     * Helper to inject a mock CurrencyHelper with a specific active currency code.
     *
     * @param string $sCurrencyCode
     */
    protected function setActiveCurrency($sCurrencyCode)
    {
        $obMockHelper = $this->createMock(CurrencyHelper::class);
        $obMockHelper->method('getActiveCurrencyCode')->willReturn($sCurrencyCode);

        $obReflection = new \ReflectionClass(CurrencyHelper::class);
        $obProperty = $obReflection->getProperty('instance');
        $obProperty->setValue(null, $obMockHelper);
    }

    protected function tearDown(): void
    {
        CurrencyHelper::forgetInstance();
        parent::tearDown();
    }
}
