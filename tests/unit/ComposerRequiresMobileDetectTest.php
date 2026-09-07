<?php declare(strict_types=1);

namespace Logingrupa\StoreExtender\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ASSET-04: storeextender declares mobiledetect/mobiledetectlib itself.
 *
 * DeviceHint uses Detection\MobileDetect, and the library currently reaches
 * vendor/ transitively through rainlab/user-plugin. A plugin that depends on
 * another plugin's dependency breaks the moment that plugin is removed or
 * changes its own requirements, so the declaration belongs here.
 *
 * The constraint is deliberately identical to the one rainlab/user-plugin
 * already carries, so composer resolution cannot move: 4.11.0 is what is
 * installed and locked today.
 */
class ComposerRequiresMobileDetectTest extends TestCase
{
    const PACKAGE_NAME = 'mobiledetect/mobiledetectlib';
    const PACKAGE_CONSTRAINT = '^4.8';

    /** @var string */
    protected $sComposerFilePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sComposerFilePath = __DIR__.'/../../composer.json';
    }

    public function testComposerFileIsValidJson()
    {
        $this->assertFileExists($this->sComposerFilePath);

        $arComposer = json_decode(file_get_contents($this->sComposerFilePath), true);

        $this->assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
        $this->assertIsArray($arComposer);
    }

    public function testComposerRequiresMobileDetect()
    {
        $arComposer = json_decode(file_get_contents($this->sComposerFilePath), true);

        $this->assertArrayHasKey('require', $arComposer);
        $this->assertArrayHasKey(
            self::PACKAGE_NAME,
            $arComposer['require'],
            sprintf(
                'Add "%s": "%s" to the require block of the plugin composer.json by hand.'
                    . ' Do not run composer to introduce it: nothing needs installing, the'
                    . ' package is already resolved and locked at the root.',
                self::PACKAGE_NAME,
                self::PACKAGE_CONSTRAINT
            )
        );
        $this->assertSame(
            self::PACKAGE_CONSTRAINT,
            $arComposer['require'][self::PACKAGE_NAME],
            sprintf(
                'The constraint must stay "%s", byte-identical to the one rainlab/user-plugin'
                    . ' resolves, so no resolution can move off the installed 4.11.0',
                self::PACKAGE_CONSTRAINT
            )
        );
    }

    public function testComposerKeepsItsExistingRequirements()
    {
        $arComposer = json_decode(file_get_contents($this->sComposerFilePath), true);

        $this->assertArrayHasKey('composer/installers', $arComposer['require']);
        $this->assertArrayHasKey('logingrupa/oc-customxmlimportpricing-plugin', $arComposer['require']);
    }
}
