<?php

declare(strict_types=1);

namespace Logingrupa\StoreExtender\Classes\StudentOffers;

use Cms\Classes\Theme;
use Illuminate\Support\Collection;
use Lovata\CouponsShopaholic\Models\Coupon;
use Lovata\CouponsShopaholic\Models\CouponGroup;
use Lovata\Shopaholic\Classes\Collection\OfferCollection;
use Lovata\Shopaholic\Classes\Collection\ProductCollection;
use Lovata\Shopaholic\Classes\Item\OfferItem;
use Lovata\Shopaholic\Classes\Item\ProductItem;
use Lovata\Toolbox\Classes\Helper\UserHelper;
use October\Rain\Support\Facades\Html;
use System\Classes\PluginManager;
use Throwable;

/**
 * Resolves /online-student-offers sections from ThemeData + Shopaholic CouponGroups
 * with batched active product/offer priming, checkout-aligned coupon eligibility,
 * and server-side subtitle sanitization.
 */
class StudentOfferSectionResolver
{
    /**
     * Twig entry point: reads active ThemeData and resolves visible sections.
     *
     * @return list<array{title: string, subtitle: string, card_class: string, cards: list<array{product: ProductItem, offer: OfferItem, coupon: string}>}>
     */
    public static function resolveForActiveTheme(): array
    {
        $arRawSections = self::readThemeSections();

        return self::resolve($arRawSections);
    }

    /**
     * Resolves an explicit raw section list into render-ready sections.
     *
     * @param  array<int|string, mixed>|null  $arRawSections
     * @return list<array{title: string, subtitle: string, card_class: string, cards: list<array{product: ProductItem, offer: OfferItem, coupon: string}>}>
     */
    public static function resolve(?array $arRawSections = null, ?int $iUserID = null): array
    {
        if ($arRawSections === null) {
            $arRawSections = self::readThemeSections();
        }

        $arActiveSections = self::normalizeActiveSections($arRawSections);
        if ($arActiveSections === []) {
            return [];
        }

        $iResolvedUserID = $iUserID ?? self::currentUserID();
        $arLoadedGroups = self::loadEligibleCouponGroups($arActiveSections, $iResolvedUserID);
        $arCandidateProductIds = self::collectCandidateProductIds($arActiveSections, $arLoadedGroups);

        if ($arCandidateProductIds === []) {
            return [];
        }

        $arActiveProducts = self::primeActiveProducts($arCandidateProductIds);
        if ($arActiveProducts === []) {
            return [];
        }

        $arActiveOffers = self::primeActiveOffers($arActiveProducts);
        if ($arActiveOffers === []) {
            return [];
        }

        $arResult = [];
        foreach ($arActiveSections as $arSection) {
            $arCards = self::buildSectionCards($arSection, $arLoadedGroups, $arActiveProducts, $arActiveOffers);
            if ($arCards === []) {
                continue;
            }

            $arResult[] = [
                'title' => $arSection['title'],
                'subtitle' => $arSection['subtitle'],
                'card_class' => $arSection['card_class'],
                'cards' => $arCards,
            ];
        }

        return $arResult;
    }

    /**
     * Reads stored student_offer_sections from raw ThemeData::data so an explicitly
     * cleared repeater (saved as null by October) stays empty instead of falling back.
     *
     * @return array<int|string, mixed>
     */
    private static function readThemeSections(): array
    {
        $obTheme = Theme::getActiveTheme();
        if (! $obTheme) {
            return StudentOfferDefaults::isLatvianStorefront() ? StudentOfferDefaults::sections() : [];
        }

        $obData = $obTheme->getCustomData();
        $arRawData = is_array($obData?->data) ? $obData->data : [];

        if (array_key_exists(StudentOfferDefaults::THEME_FIELD, $arRawData)) {
            return StudentOfferDefaults::decodeRepeaterValue($arRawData[StudentOfferDefaults::THEME_FIELD]) ?? [];
        }

        return StudentOfferDefaults::isLatvianStorefront() ? StudentOfferDefaults::sections() : [];
    }

    /**
     * @param  array<int|string, mixed>  $arRawSections
     * @return list<array{title: string, subtitle: string, card_class: string, coupon_code: string, coupon_group_ids: list<int>, products: list<array{product_id: int, coupon_code: string, is_hidden: bool}>}>
     */
    private static function normalizeActiveSections(array $arRawSections): array
    {
        $arNormalized = [];

        foreach ($arRawSections as $mSection) {
            if (! is_array($mSection)) {
                continue;
            }

            $bIsActive = array_key_exists('is_active', $mSection) && $mSection['is_active'] !== null && $mSection['is_active'] !== ''
                ? filter_var($mSection['is_active'], FILTER_VALIDATE_BOOLEAN)
                : true;

            if (! $bIsActive) {
                continue;
            }

            $arGroupIds = self::normalizeIntList($mSection['coupon_group_ids'] ?? null);
            $arRawProducts = StudentOfferDefaults::decodeRepeaterValue($mSection['products'] ?? null) ?? [];
            $arProducts = [];

            foreach ($arRawProducts as $mRow) {
                if (! is_array($mRow)) {
                    continue;
                }

                $iProductId = (int) ($mRow['product_id'] ?? 0);
                if ($iProductId <= 0) {
                    continue;
                }

                $arProducts[] = [
                    'product_id' => $iProductId,
                    'coupon_code' => trim((string) ($mRow['coupon_code'] ?? '')),
                    'is_hidden' => filter_var($mRow['is_hidden'] ?? false, FILTER_VALIDATE_BOOLEAN),
                ];
            }

            $sSubtitleRaw = trim((string) ($mSection['subtitle'] ?? ''));

            $arNormalized[] = [
                'title' => trim((string) ($mSection['title'] ?? '')),
                'subtitle' => $sSubtitleRaw !== '' ? (string) Html::clean($sSubtitleRaw) : '',
                'card_class' => StudentOfferDefaults::cardClassFor($mSection['cards_per_row'] ?? '5'),
                'coupon_code' => trim((string) ($mSection['coupon_code'] ?? '')),
                'coupon_group_ids' => $arGroupIds,
                'products' => $arProducts,
            ];
        }

        return $arNormalized;
    }

    /**
     * @param  list<array<string, mixed>>  $arSections
     * @return array<int, array{code: string, product_ids: list<int>, offers: list<array{id: int, product_id: int}>}>
     */
    private static function loadEligibleCouponGroups(array $arSections, ?int $iUserID): array
    {
        $arAllGroupIds = [];
        foreach ($arSections as $arSection) {
            foreach ($arSection['coupon_group_ids'] as $iGroupId) {
                $arAllGroupIds[$iGroupId] = $iGroupId;
            }
        }

        if ($arAllGroupIds === []) {
            return [];
        }

        if (! PluginManager::instance()->exists('Lovata.CouponsShopaholic') || ! class_exists(CouponGroup::class)) {
            return [];
        }

        $obGroups = CouponGroup::query()
            ->active()
            ->currentActive()
            ->whereHas('mechanism')
            ->whereIn('id', array_values($arAllGroupIds))
            ->with([
                'coupon' => static function ($obQuery) use ($iUserID): void {
                    $obQuery->where('hidden', false)
                        ->whereNull('user_id')
                        ->orderBy('id');

                    if ($iUserID !== null && $iUserID > 0) {
                        $obQuery->withCount([
                            'order as effective_usage_count' => static function ($obOrderQuery) use ($iUserID): void {
                                $obOrderQuery->where('user_id', $iUserID);
                            },
                        ]);
                    } else {
                        $obQuery->withCount('order as effective_usage_count');
                    }
                },
                'product' => static function ($obQuery): void {
                    $obQuery->active()
                        ->orderBy('lovata_shopaholic_products.id')
                        ->select(['lovata_shopaholic_products.id']);
                },
                'offer' => static function ($obQuery): void {
                    $obQuery->active()
                        ->orderBy('lovata_shopaholic_offers.id')
                        ->select(['lovata_shopaholic_offers.id', 'lovata_shopaholic_offers.product_id']);
                },
            ])
            ->get();

        $arEligible = [];
        foreach ($obGroups as $obGroup) {
            $sUsableCode = self::pickUsablePublicCode($obGroup);
            if ($sUsableCode === null) {
                continue;
            }

            $arProductIds = [];
            foreach ($obGroup->product as $obProduct) {
                $arProductIds[] = (int) $obProduct->id;
            }

            $arOffers = [];
            foreach ($obGroup->offer as $obOffer) {
                $arOffers[] = [
                    'id' => (int) $obOffer->id,
                    'product_id' => (int) $obOffer->product_id,
                ];
            }

            $arEligible[(int) $obGroup->id] = [
                'code' => $sUsableCode,
                'product_ids' => $arProductIds,
                'offers' => $arOffers,
            ];
        }

        return $arEligible;
    }

    /**
     * Mirrors Lovata\CouponsShopaholic\Classes\Helper\CouponHelper::checkByUsage().
     */
    private static function pickUsablePublicCode(CouponGroup $obGroup): ?string
    {
        /** @var Collection<int, Coupon> $obCoupons */
        $obCoupons = $obGroup->coupon;
        foreach ($obCoupons as $obCoupon) {
            $sCode = trim((string) $obCoupon->code);
            if ($sCode === '') {
                continue;
            }

            if ($obCoupon->max_usage !== null) {
                $iMaxUsage = (int) $obCoupon->max_usage;
            } elseif ($obGroup->max_usage_per_user !== null) {
                $iMaxUsage = (int) $obGroup->max_usage_per_user;
            } else {
                $iMaxUsage = (int) ($obGroup->max_usage ?? 0);
            }

            $iUsageCount = (int) ($obCoupon->effective_usage_count ?? 0);
            if ($iMaxUsage === 0 || ($iMaxUsage > 0 && $iUsageCount < $iMaxUsage)) {
                return $sCode;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $arSections
     * @param  array<int, array{code: string, product_ids: list<int>, offers: list<array{id: int, product_id: int}>}>  $arGroups
     * @return list<int>
     */
    private static function collectCandidateProductIds(array $arSections, array $arGroups): array
    {
        $arIds = [];

        foreach ($arSections as $arSection) {
            foreach ($arSection['coupon_group_ids'] as $iGroupId) {
                if (! isset($arGroups[$iGroupId])) {
                    continue;
                }

                foreach ($arGroups[$iGroupId]['product_ids'] as $iProductId) {
                    $arIds[$iProductId] = $iProductId;
                }

                foreach ($arGroups[$iGroupId]['offers'] as $arOffer) {
                    if ($arOffer['product_id'] > 0) {
                        $arIds[$arOffer['product_id']] = $arOffer['product_id'];
                    }
                }
            }

            foreach ($arSection['products'] as $arRow) {
                if (! $arRow['is_hidden']) {
                    $arIds[$arRow['product_id']] = $arRow['product_id'];
                }
            }
        }

        return array_values($arIds);
    }

    /**
     * Batch-primes active, non-trashed ProductItems keyed by product ID.
     *
     * @param  list<int>  $arProductIds
     * @return array<int, ProductItem>
     */
    private static function primeActiveProducts(array $arProductIds): array
    {
        if ($arProductIds === []) {
            return [];
        }

        $arActive = [];
        foreach (ProductCollection::make($arProductIds)->active()->all() as $obProduct) {
            if ($obProduct instanceof ProductItem && $obProduct->isNotEmpty() && $obProduct->isActive()) {
                $arActive[(int) $obProduct->id] = $obProduct;
            }
        }

        return $arActive;
    }

    /**
     * Batch-primes all active OfferItems across active products keyed by offer ID.
     *
     * @param  array<int, ProductItem>  $arActiveProducts
     * @return array<int, OfferItem>
     */
    private static function primeActiveOffers(array $arActiveProducts): array
    {
        $arOfferIds = [];
        foreach ($arActiveProducts as $obProduct) {
            foreach ((array) $obProduct->offer_id_list as $mOfferId) {
                $iOfferId = (int) $mOfferId;
                if ($iOfferId > 0) {
                    $arOfferIds[$iOfferId] = $iOfferId;
                }
            }
        }

        if ($arOfferIds === []) {
            return [];
        }

        $arOffers = [];
        foreach (OfferCollection::make(array_values($arOfferIds))->all() as $obOffer) {
            if ($obOffer instanceof OfferItem && $obOffer->isNotEmpty() && $obOffer->isActive()) {
                $arOffers[(int) $obOffer->id] = $obOffer;
            }
        }

        return $arOffers;
    }

    /**
     * @param  array<string, mixed>  $arSection
     * @param  array<int, array{code: string, product_ids: list<int>, offers: list<array{id: int, product_id: int}>}>  $arGroups
     * @param  array<int, ProductItem>  $arActiveProducts
     * @param  array<int, OfferItem>  $arActiveOffers
     * @return list<array{product: ProductItem, offer: OfferItem, coupon: string}>
     */
    private static function buildSectionCards(
        array $arSection,
        array $arGroups,
        array $arActiveProducts,
        array $arActiveOffers
    ): array {
        /** @var array<int, array{product_id: int, offer_id: int, coupon: string, from_group: bool}> $arEntries */
        $arEntries = [];

        foreach ($arSection['coupon_group_ids'] as $iGroupId) {
            if (! isset($arGroups[$iGroupId])) {
                continue;
            }

            $arGroup = $arGroups[$iGroupId];

            foreach ($arGroup['product_ids'] as $iProductId) {
                if (! isset($arActiveProducts[$iProductId])) {
                    continue;
                }

                foreach ((array) $arActiveProducts[$iProductId]->offer_id_list as $mOfferId) {
                    $iOfferId = (int) $mOfferId;
                    if (! isset($arActiveOffers[$iOfferId]) || isset($arEntries[$iOfferId])) {
                        continue;
                    }

                    $arEntries[$iOfferId] = [
                        'product_id' => $iProductId,
                        'offer_id' => $iOfferId,
                        'coupon' => $arGroup['code'],
                        'from_group' => true,
                    ];
                }
            }

            foreach ($arGroup['offers'] as $arOfferRef) {
                $iProductId = $arOfferRef['product_id'];
                $iOfferId = $arOfferRef['id'];

                if (! isset($arActiveProducts[$iProductId], $arActiveOffers[$iOfferId]) || isset($arEntries[$iOfferId])) {
                    continue;
                }

                $arEntries[$iOfferId] = [
                    'product_id' => $iProductId,
                    'offer_id' => $iOfferId,
                    'coupon' => $arGroup['code'],
                    'from_group' => true,
                ];
            }
        }

        foreach ($arSection['products'] as $arRow) {
            $iProductId = $arRow['product_id'];

            if ($arRow['is_hidden']) {
                foreach ($arEntries as $iOfferId => $arEntry) {
                    if ($arEntry['product_id'] === $iProductId) {
                        unset($arEntries[$iOfferId]);
                    }
                }

                continue;
            }

            if (! isset($arActiveProducts[$iProductId])) {
                continue;
            }

            foreach ((array) $arActiveProducts[$iProductId]->offer_id_list as $mOfferId) {
                $iOfferId = (int) $mOfferId;
                if (! isset($arActiveOffers[$iOfferId])) {
                    continue;
                }

                if (isset($arEntries[$iOfferId])) {
                    if ($arRow['coupon_code'] !== '') {
                        $arEntries[$iOfferId]['coupon'] = $arRow['coupon_code'];
                    } elseif (! $arEntries[$iOfferId]['from_group']) {
                        $arEntries[$iOfferId]['coupon'] = $arSection['coupon_code'];
                    }

                    continue;
                }

                $arEntries[$iOfferId] = [
                    'product_id' => $iProductId,
                    'offer_id' => $iOfferId,
                    'coupon' => $arRow['coupon_code'] !== '' ? $arRow['coupon_code'] : $arSection['coupon_code'],
                    'from_group' => false,
                ];
            }
        }

        $arCards = [];
        foreach ($arEntries as $arEntry) {
            $arCards[] = [
                'product' => $arActiveProducts[$arEntry['product_id']],
                'offer' => $arActiveOffers[$arEntry['offer_id']],
                'coupon' => $arEntry['coupon'],
            ];
        }

        return $arCards;
    }

    /**
     * @return list<int>
     */
    private static function normalizeIntList(mixed $mValue): array
    {
        if (is_string($mValue)) {
            $mDecoded = StudentOfferDefaults::decodeRepeaterValue($mValue);
            $mValue = $mDecoded ?? [];
        }

        if (! is_array($mValue)) {
            return [];
        }

        $arIds = [];
        foreach ($mValue as $mItem) {
            $iId = (int) $mItem;
            if ($iId > 0) {
                $arIds[$iId] = $iId;
            }
        }

        return array_values($arIds);
    }

    private static function currentUserID(): ?int
    {
        try {
            $iUserID = (int) UserHelper::instance()->getUserID();

            return $iUserID > 0 ? $iUserID : null;
        } catch (Throwable) {
            return null;
        }
    }
}
