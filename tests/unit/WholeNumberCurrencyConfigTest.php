<?php namespace Logingrupa\StoreExtender\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Logingrupa\StoreExtender\Classes\Helper\WholeNumberCurrencyConfig;

class WholeNumberCurrencyConfigTest extends TestCase
{
    public function testIsWholeNumberCurrencyForNok()
    {
        $this->assertTrue(WholeNumberCurrencyConfig::isWholeNumberCurrency('NOK'));
    }

    public function testIsWholeNumberCurrencyForSek()
    {
        $this->assertTrue(WholeNumberCurrencyConfig::isWholeNumberCurrency('SEK'));
    }

    public function testIsWholeNumberCurrencyForDkk()
    {
        $this->assertTrue(WholeNumberCurrencyConfig::isWholeNumberCurrency('DKK'));
    }

    public function testIsWholeNumberCurrencyForEur()
    {
        $this->assertFalse(WholeNumberCurrencyConfig::isWholeNumberCurrency('EUR'));
    }

    public function testConstantContainsExpectedCurrencies()
    {
        $this->assertSame(['NOK', 'SEK', 'DKK'], WholeNumberCurrencyConfig::WHOLE_NUMBER_CURRENCY_CODES);
    }

    public function testPriceSuffixConstant()
    {
        $this->assertSame(',-', WholeNumberCurrencyConfig::PRICE_SUFFIX);
    }
}
