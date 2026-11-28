<?php

declare(strict_types=1);

namespace Logingrupa\StoreExtender\Classes\Event\AgentCommerce;

use Logingrupa\StoreExtender\Classes\Helper\LlmsTxtBuilder;
use Logingrupa\StoreExtender\Classes\Helper\SitePageUrl;
use October\Rain\Support\Facades\Site;
use System\Models\SiteDefinition;
use Throwable;

/**
 * String-event bridge resolving localized checkout URLs via SitePageUrl::make()
 * and publishing AgentCommerce discovery endpoints into /llms.txt when enabled.
 */
class CheckoutUrlBridge
{
    /**
     * @param  mixed  $obEvent  untyped: October passes PriorityDispatcher
     */
    public function subscribe($obEvent): void
    {
        $obEvent->listen('agentcommerce.handoff.resolveCheckoutUrl', function ($obSite, $iSiteId = null): ?string {
            $obTarget = $obSite instanceof SiteDefinition
                ? $obSite
                : (is_numeric($iSiteId) ? Site::getSiteFromId((int) $iSiteId) : null);

            if (!$obTarget instanceof SiteDefinition) {
                return null;
            }

            try {
                return SitePageUrl::make($obTarget, 'checkout', []);
            } catch (Throwable) {
                return null;
            }
        });

        $obEvent->listen(LlmsTxtBuilder::EVENT_EXTEND_SECTIONS, function ($obPrimarySite, $sRootOrigin = ''): ?string {
            return $this->buildLlmsAgentSection(
                $obPrimarySite instanceof SiteDefinition ? $obPrimarySite : null,
                is_string($sRootOrigin) ? $sRootOrigin : ''
            );
        });
    }

    private function buildLlmsAgentSection(?SiteDefinition $obSite, string $sRootOrigin = ''): ?string
    {
        $sSettingsClass = 'Logingrupa\\AgentCommerce\\Models\\Settings';
        if (!class_exists($sSettingsClass) || !method_exists($sSettingsClass, 'lookupForSite')) {
            return null;
        }

        $iSiteId = $obSite !== null ? (int) $obSite->id : null;
        $obConfig = $sSettingsClass::lookupForSite($iSiteId);
        $sOrigin = rtrim($sRootOrigin, '/');
        if ($sOrigin === '' && $obSite !== null) {
            $sOrigin = self::extractOrigin((string) $obSite->base_url);
        }

        $arLines = [];
        if (!empty($obConfig->bFeedEnabled)) {
            $arLines[] = '- Public retail catalog feed: ' . $sOrigin . '/agent-catalog.json';
        }
        if (!empty($obConfig->bCatalogApiEnabled)) {
            $arLines[] = '- Catalog Search API: ' . $sOrigin . '/agent/v1/catalog/search?q={query}';
            $arLines[] = '- Product Detail API: ' . $sOrigin . '/agent/v1/products/{slug}';
            $arLines[] = '- Offer Lookup API (IDs, SKUs, 1C GUID, GTIN, color_family, hex): POST ' . $sOrigin . '/agent/v1/offers/lookup';
            $arLines[] = '- Shipping Options API: ' . $sOrigin . '/agent/v1/shipping/options';
        }
        if (!empty($obConfig->bHandoffEnabled)) {
            $arLines[] = '- Checkout Handoff API: POST ' . $sOrigin . '/agent/v1/carts';
        }
        if (!empty($obConfig->bMcpEnabled)) {
            $arLines[] = '- Streamable HTTP MCP 2025-11-25 endpoint: POST ' . $sOrigin . '/mcp';
        }

        if ($arLines === []) {
            return null;
        }

        return "## AI-Agent Commerce Endpoints\n" . implode("\n", $arLines);
    }

    private static function extractOrigin(string $sUrl): string
    {
        $arParts = parse_url($sUrl);
        if (!is_array($arParts) || empty($arParts['scheme']) || empty($arParts['host'])) {
            return rtrim($sUrl, '/');
        }

        $sPort = isset($arParts['port']) ? ':' . $arParts['port'] : '';

        return $arParts['scheme'] . '://' . $arParts['host'] . $sPort;
    }
}
