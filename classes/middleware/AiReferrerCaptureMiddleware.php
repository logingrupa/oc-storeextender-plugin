<?php

declare(strict_types=1);

namespace Logingrupa\StoreExtender\Classes\Middleware;

use Closure;
use Illuminate\Http\Request;
use Logingrupa\StoreExtender\Classes\Helper\OrderAttributionContext;

/**
 * Captures external AI assistant referrers or matching utm_source onto the shopper's session
 * without overwriting an active verified agent handoff.
 */
class AiReferrerCaptureMiddleware
{
    /** @var array<string, string> lowercase host suffix => client slug */
    private const HOST_TO_CLIENT = [
        'chatgpt.com' => 'chatgpt',
        'chat.openai.com' => 'chatgpt',
        'perplexity.ai' => 'perplexity',
        'gemini.google.com' => 'gemini',
        'claude.ai' => 'claude',
        'copilot.microsoft.com' => 'copilot',
        'grok.com' => 'grok',
    ];

    /**
     * @param  Request  $obRequest
     * @return mixed
     */
    public function handle($obRequest, Closure $fnNext)
    {
        if ($obRequest instanceof Request && $obRequest->isMethod('GET') && $obRequest->hasSession()) {
            $this->captureIfPresent($obRequest);
        }

        return $fnNext($obRequest);
    }

    private function captureIfPresent(Request $obRequest): void
    {
        $arExisting = $obRequest->session()->get(OrderAttributionContext::SESSION_KEY);
        if (is_array($arExisting) && !empty($arExisting['verified'])) {
            return;
        }

        $sClientSlug = $this->resolveClientFromRequest($obRequest);
        if ($sClientSlug === null) {
            return;
        }

        $obRequest->session()->put(OrderAttributionContext::SESSION_KEY, [
            'order_channel' => OrderAttributionContext::CHANNEL_AI_REFERRAL,
            'agent_client' => $sClientSlug,
            'verified' => false,
            'handoff_id' => null,
        ]);
    }

    private function resolveClientFromRequest(Request $obRequest): ?string
    {
        $sReferer = (string) $obRequest->headers->get('referer', '');
        $sHost = strtolower((string) parse_url($sReferer, PHP_URL_HOST));
        $sFromHost = self::matchHost($sHost);
        if ($sFromHost !== null) {
            return $sFromHost;
        }

        $sUtmSource = strtolower(trim((string) $obRequest->query('utm_source', '')));
        if ($sUtmSource === '') {
            return null;
        }

        $sFromUtmHost = self::matchHost($sUtmSource);
        if ($sFromUtmHost !== null) {
            return $sFromUtmHost;
        }

        return in_array($sUtmSource, array_values(self::HOST_TO_CLIENT), true) ? $sUtmSource : null;
    }

    private static function matchHost(string $sHost): ?string
    {
        if ($sHost === '') {
            return null;
        }

        foreach (self::HOST_TO_CLIENT as $sSuffix => $sSlug) {
            if ($sHost === $sSuffix || str_ends_with($sHost, '.' . $sSuffix)) {
                return $sSlug;
            }
        }

        return null;
    }
}
