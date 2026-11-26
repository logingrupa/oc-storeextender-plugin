<?php

declare(strict_types=1);

namespace Logingrupa\StoreExtender\Classes\StudentOffers;

use Lovata\CouponsShopaholic\Models\CouponGroup;
use Lovata\Shopaholic\Models\Product;
use System\Classes\PluginManager;

/**
 * Memoized dropdown/taglist option providers for Theme Options forms.
 */
class StudentOfferOptionsProvider
{
    /** @var array<int, string>|null */
    private static ?array $arProductOptions = null;

    /** @var array<int, string>|null */
    private static ?array $arCouponGroupOptions = null;

    /**
     * @return array<int, string>
     */
    public static function getProductOptions(): array
    {
        if (self::$arProductOptions !== null) {
            return self::$arProductOptions;
        }

        $arRows = Product::query()
            ->orderBy('name')
            ->toBase()
            ->get(['id', 'name']);

        $arOptions = [];
        foreach ($arRows as $obRow) {
            $iId = (int) $obRow->id;
            $sName = trim((string) ($obRow->name ?? ''));
            $arOptions[$iId] = $sName !== '' ? sprintf('%s (#%d)', $sName, $iId) : sprintf('#%d', $iId);
        }

        return self::$arProductOptions = $arOptions;
    }

    /**
     * @return array<int, string>
     */
    public static function getCouponGroupOptions(): array
    {
        if (self::$arCouponGroupOptions !== null) {
            return self::$arCouponGroupOptions;
        }

        if (! PluginManager::instance()->exists('Lovata.CouponsShopaholic') || ! class_exists(CouponGroup::class)) {
            return self::$arCouponGroupOptions = [];
        }

        $obGroups = CouponGroup::query()
            ->with([
                'coupon' => static function ($obQuery): void {
                    $obQuery->where('hidden', false)
                        ->whereNull('user_id')
                        ->orderBy('id')
                        ->select(['id', 'group_id', 'code']);
                },
            ])
            ->orderByDesc('active')
            ->orderBy('name')
            ->get(['id', 'name', 'active']);

        $arOptions = [];
        foreach ($obGroups as $obGroup) {
            $arCodes = [];
            foreach ($obGroup->coupon as $obCoupon) {
                $sCode = trim((string) $obCoupon->code);
                if ($sCode !== '') {
                    $arCodes[] = $sCode;
                }
            }

            $sLabel = trim((string) $obGroup->name);
            if ($arCodes !== []) {
                $sLabel .= ' ('.implode(', ', array_slice($arCodes, 0, 3)).(count($arCodes) > 3 ? ', ...' : '').')';
            }
            if (! $obGroup->active) {
                $sLabel .= ' [inactive]';
            }

            $arOptions[(int) $obGroup->id] = $sLabel;
        }

        return self::$arCouponGroupOptions = $arOptions;
    }

    public static function resetCache(): void
    {
        self::$arProductOptions = null;
        self::$arCouponGroupOptions = null;
    }
}
