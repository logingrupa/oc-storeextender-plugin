<?php declare(strict_types=1);

namespace Logingrupa\StoreExtender\Classes\Helper;

use InvalidArgumentException;

/**
 * The schema.org BreadcrumbList block of a product page: Home at position 1,
 * then the page's crumbs as the template built them, root category first and
 * the product last. Twig, registered in Plugin::registerMarkupTags() as
 * breadcrumb_json_ld.
 */
class BreadcrumbStructuredData
{
    const ABSOLUTE_URL_PATTERN = '#^https?://#i';

    /**
     * @param array  $arCrumbList [['title' => string, 'url' => string], ...] root first, product last
     * @param string $sHomeName   visible name of the shop's front page
     * @param string $sHomeUrl    absolute URL of the shop's front page
     * @return string JSON, safe to inline inside a script element
     */
    public static function render(array $arCrumbList, string $sHomeName, string $sHomeUrl): string
    {
        $arData = self::build($arCrumbList, $sHomeName, $sHomeUrl);

        return json_encode($arData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array  $arCrumbList
     * @param string $sHomeName
     * @param string $sHomeUrl
     * @return array schema.org BreadcrumbList
     */
    public static function build(array $arCrumbList, string $sHomeName, string $sHomeUrl): array
    {
        self::assertInput($arCrumbList, $sHomeName, $sHomeUrl);

        $arItemList = [self::listItem(1, $sHomeName, $sHomeUrl)];
        foreach (array_values($arCrumbList) as $iIndex => $arCrumb) {
            self::assertCrumb($arCrumb, $iIndex);
            $arItemList[] = self::listItem($iIndex + 2, trim((string) $arCrumb['title']), $arCrumb['url']);
        }

        return [
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $arItemList,
        ];
    }

    /**
     * @param int    $iPosition 1-based
     * @param string $sName
     * @param string $sUrl
     * @return array schema.org ListItem
     */
    protected static function listItem(int $iPosition, string $sName, string $sUrl): array
    {
        return [
            '@type'    => 'ListItem',
            'position' => $iPosition,
            'name'     => $sName,
            'item'     => $sUrl,
        ];
    }

    /**
     * @param array  $arCrumbList
     * @param string $sHomeName
     * @param string $sHomeUrl
     * @return void
     */
    protected static function assertInput(array $arCrumbList, string $sHomeName, string $sHomeUrl): void
    {
        if (empty($arCrumbList)) {
            throw new InvalidArgumentException('BreadcrumbStructuredData: crumb list is empty');
        }
        if (trim($sHomeName) === '') {
            throw new InvalidArgumentException('BreadcrumbStructuredData: home name is empty');
        }
        if (!self::isAbsoluteUrl($sHomeUrl)) {
            throw new InvalidArgumentException(sprintf('BreadcrumbStructuredData: home url "%s" is not absolute', $sHomeUrl));
        }
    }

    /**
     * @param mixed $arCrumb one entry of the crumb list
     * @param int   $iIndex  0-based position in the list, for the message
     * @return void
     */
    protected static function assertCrumb($arCrumb, int $iIndex): void
    {
        if (!is_array($arCrumb)) {
            throw new InvalidArgumentException(sprintf('BreadcrumbStructuredData: crumb %d is not an array', $iIndex));
        }
        if (!isset($arCrumb['title']) || !is_string($arCrumb['title']) || trim($arCrumb['title']) === '') {
            throw new InvalidArgumentException(sprintf('BreadcrumbStructuredData: crumb %d has no title', $iIndex));
        }
        if (!isset($arCrumb['url']) || !is_string($arCrumb['url'])) {
            throw new InvalidArgumentException(sprintf('BreadcrumbStructuredData: crumb %d url is not a string', $iIndex));
        }
        if (!self::isAbsoluteUrl($arCrumb['url'])) {
            throw new InvalidArgumentException(sprintf('BreadcrumbStructuredData: crumb %d url "%s" is not absolute', $iIndex, $arCrumb['url']));
        }
    }

    /**
     * @param string $sUrl
     * @return bool
     */
    protected static function isAbsoluteUrl(string $sUrl): bool
    {
        return preg_match(self::ABSOLUTE_URL_PATTERN, $sUrl) === 1;
    }
}
