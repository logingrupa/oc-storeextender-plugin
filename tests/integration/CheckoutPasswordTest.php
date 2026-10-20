<?php

require_once __DIR__.'/../StoreExtenderUserPluginTestCase.php';

use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Support\Facades\Validator;
use RainLab\User\Models\Setting;
use Logingrupa\StoreExtender\Classes\Helper\CheckoutPassword;

/**
 * Every checkout-generated password passes the active RainLab policy, whatever flags
 * and minimum length the site sets. Uncompromised stays off: it is RainLab's own HIBP
 * call and the generator never runs the rule itself.
 */
class CheckoutPasswordTest extends StoreExtenderUserPluginTestCase
{
    const DRAW_COUNT = 300;

    /**
     * RainLab.User only: the policy is its Setting model.
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessUserPlugin('RainLab.User');
    }

    /**
     * The policy instance is static and outlives the app.
     * @return void
     */
    public function tearDown(): void
    {
        Setting::clearInternalCache();

        parent::tearDown();
    }

    /**
     * @param bool $bMixedCase
     * @param bool $bNumber
     * @param bool $bSymbol
     * @return void
     */
    #[DataProvider('providePolicyFlags')]
    public function testEveryDrawPassesThePolicyForEveryMinimumLength($bMixedCase, $bNumber, $bSymbol)
    {
        foreach ([4, 8, 32, 64, 128] as $iMinLength) {
            $this->setPolicy($iMinLength, $bMixedCase, $bNumber, $bSymbol);

            for ($iDraw = 0; $iDraw < self::DRAW_COUNT; $iDraw++) {
                $sPassword = CheckoutPassword::make();
                $obValidator = Validator::make(['password' => $sPassword], ['password' => [Setting::makePasswordRule()]]);

                $this->assertFalse($obValidator->fails(), 'min '.$iMinLength.': '.$sPassword.' '.implode(' ', $obValidator->errors()->all()));
            }
        }
    }

    /**
     * @return array
     */
    public static function providePolicyFlags()
    {
        $arCaseList = [];
        foreach ([false, true] as $bMixedCase) {
            foreach ([false, true] as $bNumber) {
                foreach ([false, true] as $bSymbol) {
                    $sKey = sprintf('mixed %d number %d symbol %d', $bMixedCase, $bNumber, $bSymbol);
                    $arCaseList[$sKey] = [$bMixedCase, $bNumber, $bSymbol];
                }
            }
        }

        return $arCaseList;
    }

    /**
     * 32 characters, or the policy minimum when that is longer.
     * @return void
     */
    public function testLengthIsThePolicyMinimumOrThirtyTwo()
    {
        $this->setPolicy(8, false, true, false);
        $this->assertSame(32, strlen(CheckoutPassword::make()));

        $this->setPolicy(40, false, true, false);
        $this->assertSame(40, strlen(CheckoutPassword::make()));
    }

    /**
     * @return void
     */
    public function testDrawsAreDistinct()
    {
        $arPasswordList = [];
        for ($iDraw = 0; $iDraw < 100; $iDraw++) {
            $arPasswordList[] = CheckoutPassword::make();
        }

        $this->assertCount(100, array_unique($arPasswordList));
    }

    /**
     * @param int  $iMinLength
     * @param bool $bMixedCase
     * @param bool $bNumber
     * @param bool $bSymbol
     * @return void
     */
    protected function setPolicy($iMinLength, $bMixedCase, $bNumber, $bSymbol)
    {
        $obSetting = Setting::instance();
        $obSetting->password_min_length = $iMinLength;
        $obSetting->password_require_mixed_case = $bMixedCase;
        $obSetting->password_require_number = $bNumber;
        $obSetting->password_require_symbol = $bSymbol;
        $obSetting->password_require_uncompromised = false;
    }
}
