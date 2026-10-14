<?php namespace Logingrupa\StoreExtender\Tests\Unit;

use Logingrupa\StoreExtender\Classes\Helper\PlainText;
use PHPUnit\Framework\TestCase;

/**
 * The one rule that turns editor or visitor HTML into the plain text a
 * schema.org property carries.
 *
 * The product description and a review comment both go through it. Editors
 * write one paragraph per block with nothing between the blocks, and a review
 * comment can hold an anchor with inline style, so the rule has to keep the
 * sentences apart and leave no markup behind.
 */
class PlainTextTest extends TestCase
{
    public function testBlockBoundariesBecomeSpacesAndEntitiesAreDecoded()
    {
        $sHtml = "<p>Bezsk&#257;bes  \n\n praimeris &amp; gels</p><p>Otrs teikums.<br>Trešais</p>";

        $this->assertSame('Bezskābes praimeris & gels Otrs teikums. Trešais', PlainText::fromHtml($sHtml));
    }

    public function testAnInlineTagLeavesItsTextAndNoMarkup()
    {
        $sText = PlainText::fromHtml('Laba <a href="https://x.test" style="color:red">saite</a>');

        $this->assertSame('Laba saite', $sText);
    }

    public function testNothingButMarkupOrWhitespaceIsAnEmptyString()
    {
        $this->assertSame('', PlainText::fromHtml(''));
        $this->assertSame('', PlainText::fromHtml('<p> </p>'));
        $this->assertSame('', PlainText::fromHtml(" \n\t "));
    }

    public function testTheProductDescriptionFixtureDropsTheScriptBody()
    {
        $sHtml = "<p>Bezsk&#257;bes  \n\n praimeris &amp; gels</p><p>Otrs teikums.<br>Trešais</p><script>alert(1)</script></script>";

        $this->assertSame('Bezskābes praimeris & gels Otrs teikums. Trešais', PlainText::fromHtml($sHtml));
    }

    public function testAStyleBlockLeavesNoCssRules()
    {
        $sHtml = '<style type="text/css">p { color: red; }</style><p>Gels</p><STYLE>.x{margin:0}</STYLE ><p>Otrs</p>';

        $this->assertSame('Gels Otrs', PlainText::fromHtml($sHtml));
    }

    public function testTemplateAndNoscriptBodiesAreNotText()
    {
        $sHtml = '<p>Laka</p><template><p>slēpts</p></template><noscript>Ieslēdziet JavaScript</noscript><p>Otrs</p>';

        $this->assertSame('Laka Otrs', PlainText::fromHtml($sHtml));
    }

    public function testAnUnclosedScriptRunsToTheEndOfTheMarkup()
    {
        $this->assertSame('Atsauksme', PlainText::fromHtml('<p>Atsauksme</p><script>alert(document.cookie)'));
    }

    public function testAScriptWordInsideTextIsNotAnElement()
    {
        $this->assertSame('Subscript un styles paliek', PlainText::fromHtml('<p>Subscript un styles paliek</p>'));
    }
}
