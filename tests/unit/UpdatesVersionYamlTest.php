<?php namespace Logingrupa\StoreExtender\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * updates/version.yaml must be valid YAML.
 *
 * October's VersionManager parses this file to decide which migrations to
 * run and what to record in system_plugin_versions. A malformed entry does
 * not fail loudly at the point of writing: the plugin still loads and its
 * code still runs, so local testing looks fine, while october:migrate throws
 * and the deploy dies. On 2026-08-05 a changelog line containing ": " in
 * unquoted prose ("a real gap: the ...") failed one production deploy
 * outright and left another site's registered version one release behind
 * the code it was actually running.
 *
 * Entries whose prose contains a colon followed by a space have to be
 * quoted, which several already are. This test is the thing that says so.
 */
class UpdatesVersionYamlTest extends TestCase
{
    /** @var string */
    protected $sVersionFilePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sVersionFilePath = __DIR__.'/../../updates/version.yaml';
    }

    public function testVersionFileIsValidYaml()
    {
        $this->assertFileExists($this->sVersionFilePath);

        $arVersionList = Yaml::parseFile($this->sVersionFilePath);

        $this->assertIsArray($arVersionList);
        $this->assertNotEmpty($arVersionList);
    }

    public function testEveryEntryIsAVersionKeyWithANonEmptyNote()
    {
        $arVersionList = Yaml::parseFile($this->sVersionFilePath);

        foreach ($arVersionList as $sVersion => $mNote) {
            $this->assertMatchesRegularExpression(
                '/^\d+\.\d+\.\d+$/',
                (string) $sVersion,
                sprintf('"%s" is not a version number, which usually means the previous entry swallowed it', $sVersion)
            );

            // A note is either the changelog line or a list of them, the
            // second form being how a migration file is declared
            $arNoteList = is_array($mNote) ? $mNote : [$mNote];
            foreach ($arNoteList as $sNote) {
                $this->assertIsString($sNote, sprintf('version %s has a non-string note', $sVersion));
                $this->assertNotSame('', trim($sNote), sprintf('version %s has an empty note', $sVersion));
            }
        }
    }
}
