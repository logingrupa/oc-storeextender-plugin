<?php declare(strict_types=1);

require_once __DIR__ . '/../StoreExtenderPluginTestCase.php';

use Illuminate\Support\Facades\Log;
use Logingrupa\StoreExtender\Classes\Helper\SeoMetaText;
use Logingrupa\StoreExtender\Plugin;
use Lovata\MightySeo\Classes\TemplateProcessor;

/**
 * The seo_meta_text filter the description, og:description and
 * twitter:description metas print through.
 *
 * The .lt product template is {{ product.description }}, so MightySeo answers
 * the editor HTML escaped once; .lv and .no strip tags first, so the source's
 * own entities come back escaped twice. Values run through the real
 * TemplateProcessor, which needs the Twig facade and no table.
 */
class SeoMetaTextTest extends StoreExtenderPluginTestCase
{
    /** @var bool the Twig facade needs no table */
    protected $autoMigrate = false;

    const LT_HTML = '<div style="line-height:1.4; margin:0; padding:0;">  <p style="margin:0;"><strong>Priemonė kutikulėms suminkštinti.</strong></p><p style="margin:2px 0 0 0;">Litchi &amp; Jasmine&nbsp;aliejus</p></div>';

    public function testAnHtmlTemplateComesBackEscapedOnceAndPrintsAsPlainText()
    {
        $sSeoValue = (new TemplateProcessor('{{ product.description }}', ['product' => ['description' => self::LT_HTML]], true))->run();

        $this->assertStringContainsString('&lt;div', $sSeoValue, 'MightySeo escapes {{ }} output once');
        $this->assertSame('Priemonė kutikulėms suminkštinti. Litchi & Jasmine aliejus', SeoMetaText::fromSeoValue($sSeoValue));
    }

    public function testAStriptagsTemplateLosesTheDoubleEscape()
    {
        $sSeoValue = (new TemplateProcessor('{{ product.description|striptags }}', ['product' => ['description' => '<p>Litchi &amp; Jasmine</p>']], true))->run();

        $this->assertSame('Litchi &amp;amp; Jasmine', $sSeoValue);
        $this->assertSame('Litchi & Jasmine', SeoMetaText::fromSeoValue($sSeoValue));
    }

    public function testLiteralTemplateTextComesBackUnescapedAndStaysAsItIs()
    {
        $sSeoValue = (new TemplateProcessor('Base & Glue komplekts', [], true))->run();

        $this->assertSame('Base & Glue komplekts', $sSeoValue);
        $this->assertSame('Base & Glue komplekts', SeoMetaText::fromSeoValue($sSeoValue));
    }

    public function testALongTextIsCutOnAWordBoundaryWithAnEllipsis()
    {
        $sSource = trim(str_repeat('Gellaka ar spīdumu — ', 20));

        $sText = SeoMetaText::fromSeoValue($sSource);

        $this->assertLessThanOrEqual(SeoMetaText::LENGTH_LIMIT, mb_strlen($sText));
        $this->assertMatchesRegularExpression('/\p{L}…$/u', $sText);
        $this->assertStringStartsWith(mb_substr($sText, 0, -1), $sSource);
    }

    public function testAWordThatEndsAtTheCutIsKept()
    {
        $sFiller = str_repeat('a', 150);

        $this->assertSame($sFiller . ' bbbbbbbb…', SeoMetaText::fromSeoValue($sFiller . ' bbbbbbbb cccc'), 'the 159th character ends a word');
        $this->assertSame($sFiller . '…', SeoMetaText::fromSeoValue($sFiller . ' ' . str_repeat('b', 20)), 'a word the cut splits is dropped');
        $this->assertSame(str_repeat('x', 159) . '…', SeoMetaText::fromSeoValue(str_repeat('x', 200)), 'no space to back up to');
    }

    public function testEmptyValuesGiveNothingAndAFullLengthTextStaysWhole()
    {
        $this->assertSame('', SeoMetaText::fromSeoValue(null));
        $this->assertSame('', SeoMetaText::fromSeoValue('<p> </p>'));

        $sFullLength = str_repeat('w', SeoMetaText::LENGTH_LIMIT);
        $this->assertSame($sFullLength, SeoMetaText::fromSeoValue($sFullLength));
    }

    public function testAValueThatIsNotTextIsLoggedAndPrintsNothing()
    {
        Log::shouldReceive('warning')->once()->withArgs(fn ($sMessage, $arContext) => str_contains($sMessage, 'not text') && $arContext['type'] === 'array');

        $this->assertSame('', SeoMetaText::fromSeoValue(['description' => 'x']));
    }

    public function testInvalidUtf8IsLoggedAndItsBadBytesReplaced()
    {
        Log::shouldReceive('warning')->once()->withArgs(fn ($sMessage) => str_contains($sMessage, 'not valid UTF-8'));

        $this->assertSame('Gel ? laka', SeoMetaText::fromSeoValue('Gel ' . chr(0xB1) . ' laka'));
    }

    public function testSeoMetaTextIsRegisteredAsATwigFilter()
    {
        $arFilterList = (new Plugin(App::make('app')))->registerMarkupTags()['filters'];

        $this->assertSame([SeoMetaText::class, 'fromSeoValue'], $arFilterList['seo_meta_text']);
    }
}
