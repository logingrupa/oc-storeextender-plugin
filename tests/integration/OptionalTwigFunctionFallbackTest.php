<?php

declare(strict_types=1);

require_once __DIR__.'/../StoreExtenderPluginTestCase.php';

use Cms\Twig\Extension as CmsTwigExtension;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Logingrupa\StoreExtender\Classes\Twig\OptionalTwigFunctionFallback;
use System\Classes\MarkupManager;
use Twig\Cache\FilesystemCache;
use Twig\Environment as TwigEnvironment;
use Twig\Error\SyntaxError;
use Twig\Loader\ArrayLoader;
use Twig\TwigFunction;

/**
 * Verifies fail-open CMS Twig fallback for optional GA4 functions across cold
 * compile, warm-cache recovery/degradation, escaping parity, strict deploy
 * mode, rate limiting, and logging/cache outage isolation.
 */
class OptionalTwigFunctionFallbackTest extends StoreExtenderPluginTestCase
{
    protected $autoMigrate = false;

    private string $sCacheDir = '';

    public function setUp(): void
    {
        parent::setUp();

        OptionalTwigFunctionFallback::resetRequestState();
        Cache::forget(OptionalTwigFunctionFallback::CACHE_KEY_PREFIX.'gaEvent');
        Cache::forget(OptionalTwigFunctionFallback::CACHE_KEY_PREFIX.'gaListBlock');
        $this->clearStrictEnv();

        $this->sCacheDir = sys_get_temp_dir().'/storeextender-twig-fallback-'.bin2hex(random_bytes(6));
        mkdir($this->sCacheDir, 0777, true);
    }

    public function tearDown(): void
    {
        $this->clearStrictEnv();
        OptionalTwigFunctionFallback::resetRequestState();
        $this->removeDirectory($this->sCacheDir);

        parent::tearDown();
    }

    public function testBootSubscribesToCmsExtendTwigOnly(): void
    {
        $this->assertTrue(Event::hasListeners('cms.extendTwig'));

        $obSystemTwig = new TwigEnvironment(new ArrayLoader([
            'mail.htm' => "{{ gaEvent('begin_checkout', 'cart') }}",
        ]));
        Event::dispatch('system.extendTwig', [$obSystemTwig]);

        $this->expectException(SyntaxError::class);
        $this->expectExceptionMessage('Unknown "gaEvent" function');

        $obSystemTwig->render('mail.htm');
    }

    public function testColdCompileAndRenderDegradeToEmptyAndLogOncePerFunctionPerRequest(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->with(
                'Optional Twig function fallback executed: provider plugin unavailable.',
                ['function' => 'gaEvent', 'plugin' => 'Logingrupa.GoogleAnalytics']
            );
        Log::shouldReceive('error')
            ->once()
            ->with(
                'Optional Twig function fallback executed: provider plugin unavailable.',
                ['function' => 'gaListBlock', 'plugin' => 'Logingrupa.GoogleAnalytics']
            );

        $obTwig = $this->makeCmsTwig([
            'page.htm' => "{{ gaEvent('begin_checkout', 'cart') }}{{ gaEvent('view_item', [{offer: 1}]) }}{{ gaListBlock('view_item_list', [{offer: 1}], {item_list_id: 'category_1'}) }}",
        ]);

        $this->assertSame('', $obTwig->render('page.htm'));
    }

    public function testCompileOnlyBranchDoesNotLogWhenFallbackNeverExecutes(): void
    {
        Log::shouldReceive('error')->never();

        $obTwig = $this->makeCmsTwig([
            'empty_checkout.htm' => "{% if false %}{{ gaEvent('begin_checkout', 'cart') }}{{ gaListBlock('view_item_list', [], {}) }}{% endif %}ok",
        ]);

        $this->assertSame('ok', $obTwig->render('empty_checkout.htm'));
    }

    public function testWarmCacheSelfHealsWhenProviderReturnsAndPreservesEscapingParity(): void
    {
        $arTemplates = [
            'checkout_and_grid.htm' => "{{ gaEvent('begin_checkout', 'cart') }}|{{ gaListBlock('view_item_list', [{offer: 7}], {item_list_id: 'category_3'}) }}",
        ];

        // 1. Cold compile & render while GA is absent (writes compiled PHP into shared cache dir)
        Log::shouldReceive('error')->twice();
        $obFallbackTwig = $this->makeCmsTwig($arTemplates, $this->sCacheDir);
        $this->assertSame('|', $obFallbackTwig->render('checkout_and_grid.htm'));

        // 2. GA returns on the SAME warm Twig cache without clearing compiled files
        $arCapturedEventArgs = null;
        $arCapturedListArgs = null;
        $sRawScript = '<script type="application/json">{"event":"view_item_list"}</script>';

        $obRestoredTwig = $this->makeCmsTwig($arTemplates, $this->sCacheDir, [
            new TwigFunction('gaEvent', function (mixed ...$arArgs) use (&$arCapturedEventArgs): string {
                $arCapturedEventArgs = $arArgs;

                return '<escaped>';
            }),
            new TwigFunction('gaListBlock', function (mixed ...$arArgs) use (&$arCapturedListArgs, $sRawScript): string {
                $arCapturedListArgs = $arArgs;

                return $sRawScript;
            }, ['is_safe' => ['html']]),
        ]);

        $sRestoredOutput = $obRestoredTwig->render('checkout_and_grid.htm');

        $this->assertSame('&lt;escaped&gt;|'.$sRawScript, $sRestoredOutput);
        $this->assertSame(['begin_checkout', 'cart'], $arCapturedEventArgs);
        $this->assertSame(['view_item_list', [['offer' => 7]], ['item_list_id' => 'category_3']], $arCapturedListArgs);

        // 3. GA disappears again on the SAME warm Twig cache -> degrades cleanly with no fatal error
        OptionalTwigFunctionFallback::resetRequestState();
        $obDegradedAgainTwig = $this->makeCmsTwig($arTemplates, $this->sCacheDir);
        $this->assertSame('|', $obDegradedAgainTwig->render('checkout_and_grid.htm'));
    }

    public function testWarmCacheCompiledUnderOctoberMarkupManagerDegradesCleanlyWhenProviderVanishes(): void
    {
        $arTemplates = [
            'pdp.htm' => "{{ gaEvent('view_item', [{offer: 9}]) }}|{{ gaListBlock('view_item_list', [{offer: 9}], {item_list_id: 'category_2'}) }}",
        ];

        $obMarkup = new MarkupManager;
        $obMarkup->registerFunctions([
            'gaEvent' => [self::class, 'stubMarkupGaEvent'],
            'gaListBlock' => [self::class, 'stubMarkupGaListBlock', false],
        ]);

        // 1. Cold compile with October's real MarkupManager (mirrors Logingrupa.GoogleAnalytics array registrations)
        $obActiveTwig = $this->makeCmsTwig($arTemplates, $this->sCacheDir, $obMarkup->makeTwigFunctions());
        $this->assertSame('&lt;e:view_item&gt;|<script type="application/json">{"list":"category_2"}</script>', $obActiveTwig->render('pdp.htm'));

        // 2. Provider disappears while the compiled PHP template stays warm on disk
        Log::shouldReceive('error')->twice();
        $obMissingTwig = $this->makeCmsTwig($arTemplates, $this->sCacheDir);
        $this->assertSame('|', $obMissingTwig->render('pdp.htm'));
    }

    public static function stubMarkupGaEvent(string $sEvent, mixed $mItems = null): string
    {
        return '<e:'.$sEvent.'>';
    }

    /**
     * @param  array<string, mixed>  $arList
     */
    public static function stubMarkupGaListBlock(string $sEvent, mixed $mItems = null, array $arList = []): string
    {
        return '<script type="application/json">'.json_encode(['list' => $arList['item_list_id'] ?? '']).'</script>';
    }

    public function testStrictModeFailsClosedOnMissingGaFunctions(): void
    {
        $this->setStrictEnv('1');

        $obTwig = $this->makeCmsTwig([
            'strict.htm' => "{{ gaEvent('begin_checkout', 'cart') }}",
        ]);

        $this->expectException(SyntaxError::class);
        $this->expectExceptionMessage('Unknown "gaEvent" function');

        $obTwig->render('strict.htm');
    }

    public function testNonAllowlistedFunctionsStillThrowSyntaxError(): void
    {
        foreach (['{{ renderDeferredBlocks() }}', "{{ gaEvnet('view_item', []) }}"] as $sSource) {
            $obTwig = $this->makeCmsTwig(['bad.htm' => $sSource]);

            try {
                $obTwig->render('bad.htm');
                $this->fail('Expected SyntaxError for: '.$sSource);
            } catch (SyntaxError $obException) {
                $this->assertStringContainsString('Unknown', $obException->getMessage());
            }
        }
    }

    public function testRateLimitingSuppressesRepeatedAlertsAcrossRequests(): void
    {
        Log::shouldReceive('error')->once();

        $obFirstTwig = $this->makeCmsTwig(['a.htm' => "{{ gaEvent('begin_checkout', 'cart') }}"]);
        $this->assertSame('', $obFirstTwig->render('a.htm'));

        OptionalTwigFunctionFallback::resetRequestState();

        $obSecondTwig = $this->makeCmsTwig(['b.htm' => "{{ gaEvent('begin_checkout', 'cart') }}"]);
        $this->assertSame('', $obSecondTwig->render('b.htm'));
    }

    public function testCacheAndLoggerFailuresNeverBreakStorefrontRendering(): void
    {
        Cache::shouldReceive('add')->once()->andThrow(new RuntimeException('redis down'));
        Log::shouldReceive('error')->once()->andThrow(new RuntimeException('event log table locked'));

        $obTwig = $this->makeCmsTwig(['checkout.htm' => "ok{{ gaEvent('begin_checkout', 'cart') }}"]);

        $this->assertSame('ok', $obTwig->render('checkout.htm'));
    }

    /**
     * Builds a CMS Twig environment wired through cms.extendTwig (unit-test
     * mode does not run full Cms\Classes\Controller bootstrapping).
     *
     * @param  array<string, string>  $arTemplates
     * @param  list<TwigFunction>  $arFunctions
     */
    private function makeCmsTwig(array $arTemplates, ?string $sCacheDir = null, array $arFunctions = []): TwigEnvironment
    {
        $arOptions = ['auto_reload' => false, 'strict_variables' => false];
        if ($sCacheDir !== null) {
            $arOptions['cache'] = new FilesystemCache($sCacheDir);
        }

        $obTwig = new TwigEnvironment(new ArrayLoader($arTemplates), $arOptions);
        foreach ($arFunctions as $obFunction) {
            $obTwig->addFunction($obFunction);
        }

        CmsTwigExtension::addExtensionToTwig($obTwig);

        return $obTwig;
    }

    private function setStrictEnv(string $sValue): void
    {
        putenv(OptionalTwigFunctionFallback::ENV_STRICT_FLAG.'='.$sValue);
        $_ENV[OptionalTwigFunctionFallback::ENV_STRICT_FLAG] = $sValue;
        $_SERVER[OptionalTwigFunctionFallback::ENV_STRICT_FLAG] = $sValue;
    }

    private function clearStrictEnv(): void
    {
        putenv(OptionalTwigFunctionFallback::ENV_STRICT_FLAG);
        unset($_ENV[OptionalTwigFunctionFallback::ENV_STRICT_FLAG], $_SERVER[OptionalTwigFunctionFallback::ENV_STRICT_FLAG]);
    }

    private function removeDirectory(string $sDir): void
    {
        if ($sDir === '' || ! is_dir($sDir)) {
            return;
        }

        $obIterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($obIterator as $obEntry) {
            if ($obEntry->isDir()) {
                rmdir($obEntry->getPathname());
            } else {
                unlink($obEntry->getPathname());
            }
        }

        rmdir($sDir);
    }
}
