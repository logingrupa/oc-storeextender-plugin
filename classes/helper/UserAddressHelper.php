<?php

namespace Logingrupa\StoreExtender\Classes\Helper;

use Illuminate\Support\Facades\DB;
use Lang;
use Lovata\OrdersShopaholic\Classes\Store\UserAddressListStore;
use Lovata\OrdersShopaholic\Models\UserAddress;

/**
 * Single seam for reading, writing, and cleaning a customer's saved shipping
 * and billing addresses backed by Lovata\OrdersShopaholic\Models\UserAddress.
 *
 * All shipping addresses — both courier street addresses and pickup points /
 * parcel lockers — are treated as real shipping addresses and preserved as the
 * single source of truth across Backend, My Account, and Checkout.
 */
class UserAddressHelper
{
    public const TYPE_SHIPPING = UserAddress::ADDRESS_TYPE_SIPPING;

    public const TYPE_BILLING = UserAddress::ADDRESS_TYPE_BILLING;

    public const TABLE_NAME = 'lovata_orders_shopaholic_user_addresses';

    /** @var list<string> */
    public const EDITABLE_FIELDS = [
        'address1',
        'address2',
        'city',
        'state',
        'postcode',
        'country',
    ];

    /** @var list<string> */
    public const ADDRESS_COLUMNS = [
        'country',
        'state',
        'city',
        'street',
        'house',
        'building',
        'flat',
        'address1',
        'address2',
        'postcode',
    ];

    /** @var list<string> */
    private const LEGACY_JUNK_VALUES = ['undefined', 'nan', 'null'];

    /**
     * Valid address types and their localized labels for backend UI.
     *
     * @return array<string, string>
     */
    public static function getTypeOptions(): array
    {
        return [
            self::TYPE_SHIPPING => Lang::get('logingrupa.storeextender::lang.user_address.type_shipping'),
            self::TYPE_BILLING => Lang::get('logingrupa.storeextender::lang.user_address.type_billing'),
        ];
    }

    /**
     * Human-readable label for a UserAddress type value.
     */
    public static function getTypeLabel(?string $sType): string
    {
        $arOptions = self::getTypeOptions();

        return $arOptions[$sType ?? ''] ?? (string) $sType;
    }

    /**
     * Resolve the customer's most recently updated non-empty saved address for
     * the given type (including pickup-point / parcel-locker shipping rows).
     */
    public static function getPrimaryAddress(?object $obUser, string $sType): ?UserAddress
    {
        if (! is_object($obUser) || empty($obUser->id) || ! self::isValidType($sType)) {
            return null;
        }

        $obAddressList = UserAddress::where('user_id', (int) $obUser->id)
            ->where('type', $sType)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();

        foreach ($obAddressList as $obAddress) {
            if (self::hasAnyAddressValue(self::toFormData($obAddress))) {
                return $obAddress;
            }
        }

        return null;
    }

    /**
     * Both primary addresses (shipping + billing) normalized for Twig forms
     * and checkout prefill. Returns empty shapes without querying for guests.
     *
     * @return array{
     *     shipping: array{id: int|null, address1: string, address2: string, city: string, state: string, postcode: string, country: string, has_value: bool},
     *     billing: array{id: int|null, address1: string, address2: string, city: string, state: string, postcode: string, country: string, has_value: bool}
     * }
     */
    public static function getCheckoutAddresses(?object $obUser): array
    {
        if (! is_object($obUser) || empty($obUser->id)) {
            return [
                'shipping' => self::emptyFormData(),
                'billing' => self::emptyFormData(),
            ];
        }

        $arShipping = self::toFormData(self::getPrimaryAddress($obUser, self::TYPE_SHIPPING));
        $arBilling = self::toFormData(self::getPrimaryAddress($obUser, self::TYPE_BILLING));

        return [
            'shipping' => $arShipping,
            'billing' => $arBilling,
        ];
    }

    /**
     * Resolve the authenticated customer's most recent non-canceled order
     * shipping_type_id when it is still present in the active shipping list.
     * Returns null for guests (zero queries) or when no active match exists.
     *
     * @param  iterable<object>  $obActiveShippingList
     */
    public static function getLastShippingTypeId(?object $obUser, iterable $obActiveShippingList): ?int
    {
        if (! is_object($obUser) || empty($obUser->id)) {
            return null;
        }

        $arActiveIds = [];
        foreach ($obActiveShippingList as $obShippingType) {
            if (is_object($obShippingType) && ! empty($obShippingType->id)) {
                $arActiveIds[(int) $obShippingType->id] = true;
            }
        }

        if (empty($arActiveIds)) {
            return null;
        }

        $iShippingTypeId = DB::table('lovata_orders_shopaholic_orders as orders')
            ->leftJoin('lovata_orders_shopaholic_statuses as statuses', 'statuses.id', '=', 'orders.status_id')
            ->where('orders.user_id', (int) $obUser->id)
            ->whereNotNull('orders.shipping_type_id')
            ->where('orders.shipping_type_id', '>', 0)
            ->where(function ($obQuery): void {
                $obQuery->whereNull('statuses.code')
                    ->orWhere('statuses.code', '!=', OrderStatusCode::CANCELED);
            })
            ->orderByDesc('orders.id')
            ->value('orders.shipping_type_id');

        $iResolved = is_numeric($iShippingTypeId) ? (int) $iShippingTypeId : 0;

        return isset($arActiveIds[$iResolved]) ? $iResolved : null;
    }

    /**
     * Normalize a UserAddress model into form-ready strings with legacy junk stripped.
     *
     * @return array{id: int|null, address1: string, address2: string, city: string, state: string, postcode: string, country: string, has_value: bool}
     */
    public static function toFormData(?UserAddress $obAddress): array
    {
        if (! $obAddress instanceof UserAddress) {
            return self::emptyFormData();
        }

        $sAddress1 = self::cleanValue($obAddress->address1);
        if ($sAddress1 === '') {
            $sAddress1 = self::composeLegacyStreet($obAddress);
        }

        $arData = [
            'id' => $obAddress->id ? (int) $obAddress->id : null,
            'address1' => $sAddress1,
            'address2' => self::cleanValue($obAddress->address2),
            'city' => self::cleanValue($obAddress->city),
            'state' => self::cleanValue($obAddress->state),
            'postcode' => self::cleanValue($obAddress->postcode),
            'country' => self::cleanValue($obAddress->country),
        ];
        $arData['has_value'] = self::hasAnyAddressValue($arData);

        return $arData;
    }

    /**
     * Save, update, or clear a user's single shipping or billing address from
     * frontend input, pruning any stray extra rows of that type.
     *
     * @param  array<string, mixed>  $arInput
     */
    public static function saveAddress(object $obUser, string $sType, array $arInput): ?UserAddress
    {
        if (empty($obUser->id) || ! self::isValidType($sType)) {
            return null;
        }

        $iUserId = (int) $obUser->id;
        $arNormalized = self::normalizeInput($arInput);
        $obTarget = self::resolveTargetAddress($obUser, $sType, $arInput);

        if (! self::hasAnyAddressValue($arNormalized)) {
            UserAddress::where('user_id', $iUserId)
                ->where('type', $sType)
                ->delete();
            self::clearAddressStore($iUserId);

            return null;
        }

        if ($obTarget instanceof UserAddress) {
            $obTarget->fill($arNormalized);
            $obTarget->save();
            $obTarget->touch();
        } else {
            $obTarget = UserAddress::create(array_merge($arNormalized, [
                'user_id' => $iUserId,
                'type' => $sType,
            ]));
        }

        UserAddress::where('user_id', $iUserId)
            ->where('type', $sType)
            ->where('id', '!=', $obTarget->id)
            ->delete();
        self::clearAddressStore($iUserId);

        return $obTarget;
    }

    /**
     * Automatically clean legacy JS "undefined"/"NaN" tokens, drop completely
     * blank rows, and keep at most one latest non-empty row per (user_id, type)
     * — one shipping address and one billing address per user.
     *
     * @return array{scanned: int, cleaned: int, deleted_empty: int, deleted_duplicates: int, kept: int}
     */
    public static function cleanAndDedupeTable(): array
    {
        $iScanned = 0;
        $iCleaned = 0;
        $iDeletedEmpty = 0;
        $iDeletedDuplicates = 0;
        $arSeenUserTypes = [];
        $arDeleteIds = [];
        $arTouchedUserIds = [];

        $obRows = DB::table(self::TABLE_NAME)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->cursor();

        foreach ($obRows as $obRow) {
            $iScanned++;
            $arUpdates = [];
            $bHasValue = false;

            foreach (self::ADDRESS_COLUMNS as $sColumn) {
                $mRaw = $obRow->{$sColumn} ?? null;
                $sClean = self::cleanValue($mRaw);
                if ($sClean !== '') {
                    $bHasValue = true;
                }

                $sRaw = is_scalar($mRaw) ? (string) $mRaw : '';
                if ($sRaw !== $sClean && ($mRaw !== null || $sClean !== '')) {
                    $arUpdates[$sColumn] = $sClean;
                }
            }

            $iFloor = is_numeric($obRow->floor ?? null) ? (int) $obRow->floor : null;
            if ($iFloor !== null && $iFloor > 0) {
                $bHasValue = true;
            }

            $iRowId = (int) $obRow->id;
            $iUserId = (int) $obRow->user_id;

            if (! $bHasValue) {
                $arDeleteIds[] = $iRowId;
                $arTouchedUserIds[$iUserId] = true;
                $iDeletedEmpty++;

                continue;
            }

            $sUserTypeKey = $iUserId.'|'.strtolower(trim((string) ($obRow->type ?? '')));

            if (isset($arSeenUserTypes[$sUserTypeKey])) {
                $arDeleteIds[] = $iRowId;
                $arTouchedUserIds[$iUserId] = true;
                $iDeletedDuplicates++;

                continue;
            }

            $arSeenUserTypes[$sUserTypeKey] = true;

            if (! empty($arUpdates)) {
                DB::table(self::TABLE_NAME)
                    ->where('id', $iRowId)
                    ->update($arUpdates);
                $arTouchedUserIds[$iUserId] = true;
                $iCleaned++;
            }
        }

        foreach (array_chunk($arDeleteIds, 500) as $arIdChunk) {
            DB::table(self::TABLE_NAME)->whereIn('id', $arIdChunk)->delete();
        }

        foreach (array_keys($arTouchedUserIds) as $iUserId) {
            self::clearAddressStore((int) $iUserId);
        }

        return [
            'scanned' => $iScanned,
            'cleaned' => $iCleaned,
            'deleted_empty' => $iDeletedEmpty,
            'deleted_duplicates' => $iDeletedDuplicates,
            'kept' => $iScanned - $iDeletedEmpty - $iDeletedDuplicates,
        ];
    }

    protected static function clearAddressStore(int $iUserId): void
    {
        if (class_exists(UserAddressListStore::class)) {
            UserAddressListStore::instance()->user->clear($iUserId);
        }
    }

    /**
     * Whether at least one editable address field carries a non-empty value.
     *
     * @param  array<string, mixed>  $arData
     */
    public static function hasAnyAddressValue(array $arData): bool
    {
        foreach (self::EDITABLE_FIELDS as $sField) {
            if (self::cleanValue($arData[$sField] ?? null) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * True when every meaningful address field on the row is blank or legacy junk.
     */
    public static function isEmptyRow(UserAddress $obAddress): bool
    {
        return ! self::hasAnyAddressValue(self::toFormData($obAddress));
    }

    /**
     * Clean a single address field value, stripping both standalone and glued
     * legacy JS "undefined"/"NaN" concatenation artifacts.
     */
    public static function cleanValue(mixed $mValue): string
    {
        if (! is_scalar($mValue)) {
            return '';
        }

        $sValue = trim((string) $mValue);
        if ($sValue === '' || in_array(strtolower($sValue), self::LEGACY_JUNK_VALUES, true)) {
            return '';
        }

        // Legacy JS concatenated `undefined + address` and `address + undefined` without spaces.
        $sValue = (string) preg_replace('/^(?:undefined|NaN)+|(?:undefined|NaN)+$/', '', $sValue);
        $sValue = (string) preg_replace('/\b(?:undefined|NaN)\b/', '', $sValue);
        $sValue = trim($sValue);

        if ($sValue === '' || in_array(strtolower($sValue), self::LEGACY_JUNK_VALUES, true)) {
            return '';
        }

        return $sValue;
    }

    /**
     * @param  array<string, mixed>  $arInput
     * @return array<string, string>
     */
    protected static function normalizeInput(array $arInput): array
    {
        $arResult = [];
        foreach (self::EDITABLE_FIELDS as $sField) {
            $arResult[$sField] = self::cleanValue($arInput[$sField] ?? null);
        }

        return $arResult;
    }

    /**
     * @param  array<string, mixed>  $arInput
     */
    protected static function resolveTargetAddress(object $obUser, string $sType, array $arInput): ?UserAddress
    {
        $iAddressId = isset($arInput['id']) && is_numeric($arInput['id']) ? (int) $arInput['id'] : 0;
        if ($iAddressId > 0) {
            $obById = UserAddress::where('user_id', (int) $obUser->id)
                ->where('type', $sType)
                ->find($iAddressId);
            if ($obById instanceof UserAddress) {
                return $obById;
            }
        }

        return self::getPrimaryAddress($obUser, $sType);
    }

    /**
     * @return array{id: int|null, address1: string, address2: string, city: string, state: string, postcode: string, country: string, has_value: bool}
     */
    protected static function emptyFormData(): array
    {
        return [
            'id' => null,
            'address1' => '',
            'address2' => '',
            'city' => '',
            'state' => '',
            'postcode' => '',
            'country' => '',
            'has_value' => false,
        ];
    }

    protected static function isValidType(string $sType): bool
    {
        return in_array($sType, [self::TYPE_SHIPPING, self::TYPE_BILLING], true);
    }

    protected static function composeLegacyStreet(UserAddress $obAddress): string
    {
        $arPartList = array_filter([
            self::cleanValue($obAddress->street),
            self::cleanValue($obAddress->house),
            self::cleanValue($obAddress->building),
            self::cleanValue($obAddress->flat),
        ], static fn (string $sPart): bool => $sPart !== '');

        return implode(' ', $arPartList);
    }
}
