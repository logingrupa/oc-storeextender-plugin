<?php namespace Logingrupa\StoreExtender\Tests\Unit;

use Logingrupa\StoreExtender\Classes\Helper\TextHighlighter;
use PHPUnit\Framework\TestCase;
use Twig\Markup;

/**
 * The highlight filter marks the search term inside a name.
 *
 * Both the name and the term come from outside: names from the 1C feed
 * (which already writes "(<Свойства не назначены>)" into offers) and the
 * term from the query string. Neither may reach the page as markup, and the
 * match has to be as forgiving as the accent-insensitive search that found
 * the name.
 */
class TextHighlighterTest extends TestCase
{
    public function testMatchIsWrappedInTheSpanAsMarkup()
    {
        $obResult = TextHighlighter::highlight('Gel polish', 'gel');

        $this->assertInstanceOf(Markup::class, $obResult);
        $this->assertSame('<span class="highlight">Gel</span> polish', (string) $obResult);
    }

    public function testMarkupInTheSubjectIsEscaped()
    {
        $sResult = (string) TextHighlighter::highlight('<img src=x onerror=alert(1)>', 'gel');

        $this->assertStringNotContainsString('<img', $sResult);
        $this->assertSame('&lt;img src=x onerror=alert(1)&gt;', $sResult);
    }

    public function testMarkupInTheTermIsEscapedInsideTheSpan()
    {
        $sResult = (string) TextHighlighter::highlight('Base <b> coat', '<b>');

        $this->assertSame('Base <span class="highlight">&lt;b&gt;</span> coat', $sResult);
    }

    public function testEmptyAndNullTermReturnTheEscapedSubject()
    {
        foreach (['', null, ' ', []] as $mTerm) {
            $sResult = (string) TextHighlighter::highlight('Base & Top', $mTerm);

            $this->assertSame('Base &amp; Top', $sResult);
            $this->assertStringNotContainsString('<span', $sResult);
        }
    }

    public function testRegexMetacharactersInTheTermAreLiteral()
    {
        $this->assertSame(
            'Gel <span class="highlight">(5ml)</span>',
            (string) TextHighlighter::highlight('Gel (5ml)', '(5ml)')
        );
        $this->assertSame(
            'Set <span class="highlight">10+</span>',
            (string) TextHighlighter::highlight('Set 10+', '10+')
        );
        $this->assertSame('Gel polish', (string) TextHighlighter::highlight('Gel polish', '.*'));
    }

    public function testDiacriticsMatchInBothDirections()
    {
        $this->assertSame(
            '<span class="highlight">Sarkanā</span> gēllaka',
            (string) TextHighlighter::highlight('Sarkanā gēllaka', 'sarkana')
        );
        $this->assertSame(
            '<span class="highlight">Sarkana</span>',
            (string) TextHighlighter::highlight('Sarkana', 'sarkanā')
        );
    }

    public function testNonAsciiCaseIsIgnored()
    {
        $this->assertSame(
            '<span class="highlight">ĀBOLS</span>',
            (string) TextHighlighter::highlight('ĀBOLS', 'ābols')
        );
    }

    public function testEveryOccurrenceIsWrappedIncludingCyrillic()
    {
        $this->assertSame(
            '<span class="highlight">Гель</span> <span class="highlight">гель</span>',
            (string) TextHighlighter::highlight('Гель гель', 'гель')
        );
    }

    public function testEveryTermOfAListIsWrapped()
    {
        $this->assertSame(
            '<span class="highlight">Gel</span> <span class="highlight">polish</span> red',
            (string) TextHighlighter::highlight('Gel polish red', ['gel', 'polish'])
        );
    }
}
