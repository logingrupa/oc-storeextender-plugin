<?php

declare(strict_types=1);

namespace Logingrupa\StoreExtender\Classes\Twig;

use Illuminate\Support\Env;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;
use Twig\Environment as TwigEnvironment;
use Twig\TwigFunction;

/**
 * Fallback resolver for optional storefront analytics Twig functions so a
 * disabled or temporarily absent analytics plugin degrades to empty output
 * instead of breaking revenue-critical pages with a Twig SyntaxError.
 */
class OptionalTwigFunctionFallback
{
    public const ENV_STRICT_FLAG = 'STOREEXTENDER_STRICT_TWIG_FUNCTIONS';

    public const LOG_COOLDOWN_SECONDS = 900;

    public const CACHE_KEY_PREFIX = 'storeextender:missing_twig_function:';

    /**
     * Exact Twig options must mirror the real plugin registration so templates
     * compiled while the provider is absent preserve escaping when it returns.
     *
     * @var array<string, array{plugin: string, options: array<string, mixed>}>
     */
    public const OPTIONAL_FUNCTIONS = [
        'gaEvent' => [
            'plugin' => 'Logingrupa.GoogleAnalytics',
            'options' => [],
        ],
        'gaListBlock' => [
            'plugin' => 'Logingrupa.GoogleAnalytics',
            'options' => ['is_safe' => ['html']],
        ],
    ];

    /** @var array<string, true> */
    private static array $arLoggedInRequest = [];

    private static bool $bForceStrict = false;

    /**
     * @param  mixed  $obEvent
     */
    public function subscribe($obEvent): void
    {
        $obEvent->listen('cms.extendTwig', [$this, 'registerOnTwig']);
    }

    public function registerOnTwig(TwigEnvironment $obTwig): void
    {
        $obTwig->registerUndefinedFunctionCallback(
            fn (string $sName): TwigFunction|false => $this->resolveUndefinedFunction($sName)
        );
    }

    public function resolveUndefinedFunction(string $sName): TwigFunction|false
    {
        if ($this->isStrictMode() || ! isset(self::OPTIONAL_FUNCTIONS[$sName])) {
            return false;
        }

        $arConfig = self::OPTIONAL_FUNCTIONS[$sName];

        return new TwigFunction(
            $sName,
            fn (mixed ...$arArgs): string => $this->invokeFallback($sName, $arConfig['plugin']),
            $arConfig['options']
        );
    }

    public static function resetRequestState(): void
    {
        self::$arLoggedInRequest = [];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $fnCallback
     * @return T
     */
    public static function withStrictMode(callable $fnCallback): mixed
    {
        $bPrevious = self::$bForceStrict;
        self::$bForceStrict = true;

        try {
            return $fnCallback();
        } finally {
            self::$bForceStrict = $bPrevious;
        }
    }

    protected function isStrictMode(): bool
    {
        return self::$bForceStrict || filter_var(Env::get(self::ENV_STRICT_FLAG, false), FILTER_VALIDATE_BOOLEAN);
    }

    protected function invokeFallback(string $sFunction, string $sPlugin): string
    {
        $this->reportMissingFunction($sFunction, $sPlugin);

        return '';
    }

    protected function reportMissingFunction(string $sFunction, string $sPlugin): void
    {
        if (isset(self::$arLoggedInRequest[$sFunction])) {
            return;
        }

        self::$arLoggedInRequest[$sFunction] = true;

        try {
            if (! Cache::add(self::CACHE_KEY_PREFIX.$sFunction, true, self::LOG_COOLDOWN_SECONDS)) {
                return;
            }
        } catch (Throwable) {
            // Proceed to best-effort logging; static guard already caps to once per request.
        }

        try {
            Log::error('Optional Twig function fallback executed: provider plugin unavailable.', [
                'function' => $sFunction,
                'plugin' => $sPlugin,
            ]);
        } catch (Throwable) {
            // Never allow EventLog DB or logger failures to break storefront rendering.
        }
    }
}
