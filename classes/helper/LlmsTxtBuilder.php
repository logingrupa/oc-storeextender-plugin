<?php

declare(strict_types=1);

namespace Logingrupa\StoreExtender\Classes\Helper;

use Illuminate\Support\Facades\Event;
use Lovata\Shopaholic\Classes\Helper\CurrencyHelper;
use Site;
use System\Models\SiteDefinition;

/**
 * Builds the plain-text /llms.txt discovery map for the active shop using only enabled sites.
 */
final class LlmsTxtBuilder
{
    public const EVENT_EXTEND_SECTIONS = 'storeextender.llms_txt.extend';

    public static function build(): string
    {
        $arEnabledSites = self::enabledSites();
        $obPrimarySite = $arEnabledSites[0] ?? null;
        $sBaseUrl = $obPrimarySite !== null ? rtrim((string) $obPrimarySite->base_url, '/') : rtrim((string) url('/'), '/');
        $sRootOrigin = self::extractOrigin($sBaseUrl);
        $sCurrency = self::activeCurrencyCode();

        $arLines = [
            '# NAILS Cosmetics',
            '',
            '> Professional nail care, gel polishes, builder gels, and salon supplies.',
            '',
            '## Store Overview',
            '- Primary URL: ' . $sBaseUrl,
            '- Currency: ' . $sCurrency,
            '- Sitemap: ' . $sRootOrigin . '/sitemap.xml',
            '- Color Collections: ' . $sBaseUrl . '/collections',
            '- Promotions: ' . $sBaseUrl . '/promo',
        ];

        if (!empty($arEnabledSites)) {
            $arLocaleList = [];
            foreach ($arEnabledSites as $obSite) {
                $sLocale = trim((string) $obSite->locale);
                $sUrl = rtrim((string) $obSite->base_url, '/');
                if ($sLocale !== '' && $sUrl !== '') {
                    $arLocaleList[] = sprintf('%s (%s)', $sLocale, $sUrl);
                }
            }
            if (!empty($arLocaleList)) {
                $arLines[] = '- Enabled Locales: ' . implode(', ', $arLocaleList);
            }
        }

        $arLines = array_merge($arLines, [
            '',
            '## Ordering & Product Variants',
            '- Products with multiple shades use distinct purchasable offer IDs (`SKU-{product_id}-{offer_id}`).',
            '- Always confirm exact offer ID / shade name, price, currency, and stock availability before checkout.',
            '- Online payments (Paysera / Vipps) require human confirmation (PSD2/SCA); bank transfer and invoice orders are supported for eligible B2B accounts.',
        ]);

        $arExtraSections = Event::dispatch(self::EVENT_EXTEND_SECTIONS, [$obPrimarySite, $sRootOrigin]);
        foreach ($arExtraSections as $mSection) {
            if (is_string($mSection) && trim($mSection) !== '') {
                $arLines[] = '';
                $arLines[] = trim($mSection);
            }
        }

        return implode("\n", $arLines) . "\n";
    }

    /**
     * @return list<SiteDefinition>
     */
    private static function enabledSites(): array
    {
        $obSiteList = Site::listEnabled();
        $arPrimary = [];
        $arSecondary = [];
        foreach ($obSiteList as $obSite) {
            if ($obSite instanceof SiteDefinition) {
                if (!empty($obSite->is_primary)) {
                    $arPrimary[] = $obSite;
                } else {
                    $arSecondary[] = $obSite;
                }
            }
        }

        return array_merge($arPrimary, $arSecondary);
    }

    private static function extractOrigin(string $sUrl): string
    {
        $arParts = parse_url($sUrl);
        if (!is_array($arParts) || empty($arParts['scheme']) || empty($arParts['host'])) {
            return $sUrl;
        }

        $sPort = isset($arParts['port']) ? ':' . $arParts['port'] : '';

        return $arParts['scheme'] . '://' . $arParts['host'] . $sPort;
    }

    private static function activeCurrencyCode(): string
    {
        try {
            $sCode = trim((string) CurrencyHelper::instance()->getActiveCurrencyCode());
        } catch (\Throwable) {
            // Boundary fallback when Shopaholic currency schema is unavailable.
            return 'EUR';
        }

        return $sCode !== '' ? $sCode : 'EUR';
    }
}
