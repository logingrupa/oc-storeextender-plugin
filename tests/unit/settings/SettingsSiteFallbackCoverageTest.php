<?php namespace Logingrupa\StoreExtender\Tests\Unit\Settings;

use Logingrupa\StoreExtender\Classes\Event\Settings\SettingsSiteFallbackHandler;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Every model the fallback handler lists must actually be site-scoped, and
 * XmlImportSettings must be among them.
 *
 * The handler exists because Lovata settings models use the Multisite trait
 * with an empty $propagatable list, so a system_settings row belongs to exactly
 * one site and every other site reads null.
 *
 * XmlImportSettings was left out, and the gap was invisible on nailscosmetics.lv
 * because site 1 there is both where the row lives and the primary site. On
 * nailscosmetics.no the row is site 1 while the primary is site 2, and a console
 * command resolves the primary site - so the 1C importer read null for its file
 * list, its XPaths and its whole field map, and could not run from CLI at all.
 * The web storefront was fine throughout, which is why nothing surfaced it.
 *
 * The second test is what stops this list from becoming decorative: a class that
 * is not site-scoped does not belong in it, and one that is scoped but absent is
 * the bug above.
 */
class SettingsSiteFallbackCoverageTest extends TestCase
{
    public function testXmlImportSettingsIsCoveredSoConsoleCommandsCanReadIt()
    {
        $this->assertContains(
            \Lovata\Shopaholic\Models\XmlImportSettings::class,
            SettingsSiteFallbackHandler::AR_SETTINGS_MODEL_LIST,
            'Without this the 1C import reads null for every setting on any deployment'
                . ' whose settings row is not on the primary site.'
        );
    }

    public function testGoodsReceivedSettingsIsCoveredSoTheRecomputeCliReadsTheRealToggles()
    {
        $this->assertContains(
            \Logingrupa\GoodsReceivedShopaholic\Models\Settings::class,
            SettingsSiteFallbackHandler::AR_SETTINGS_MODEL_LIST,
            'goodsreceived:recompute_active_from_stock resolves the primary site, so without this'
                . ' it reads auto_deactivate_on_zero as false on nailscosmetics.no and reconciles nothing.'
        );
    }

    public function testEveryCoveredModelIsActuallySiteScoped()
    {
        $this->assertNotEmpty(SettingsSiteFallbackHandler::AR_SETTINGS_MODEL_LIST);

        foreach (SettingsSiteFallbackHandler::AR_SETTINGS_MODEL_LIST as $sModelClass) {
            $this->assertTrue(class_exists($sModelClass), $sModelClass.' does not exist');

            $obReflection = new ReflectionClass($sModelClass);

            $this->assertContains(
                \October\Rain\Database\Traits\Multisite::class,
                $this->collectTraitList($obReflection),
                $sModelClass.' is not multisite, so the fallback does nothing for it'
            );

            $obProperty = $obReflection->getProperty('propagatable');
            $obProperty->setAccessible(true);
            $this->assertSame(
                [],
                $obProperty->getValue($obReflection->newInstanceWithoutConstructor()),
                $sModelClass.' propagates its values already and needs no fallback'
            );
        }
    }

    /**
     * Traits used anywhere up the inheritance chain, since the settings models
     * pick Multisite up from CommonSettings rather than declaring it themselves.
     *
     * @param ReflectionClass $obReflection
     * @return string[]
     */
    protected function collectTraitList(ReflectionClass $obReflection): array
    {
        $arTraitList = [];
        for ($obCurrent = $obReflection; $obCurrent !== false; $obCurrent = $obCurrent->getParentClass()) {
            $arTraitList = array_merge($arTraitList, array_keys($obCurrent->getTraits()));
        }

        return $arTraitList;
    }
}
