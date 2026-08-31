<?php namespace Logingrupa\StoreExtender\Classes\Event\User;

/**
 * Class ExtendRainLabUserModel
 * @package Logingrupa\StoreExtender\Classes\Event\User
 *
 * Gives RainLab\User\Models\User the Buddies-shaped surface the store already depends on:
 * the three ported columns, the merge semantics of "property", and a "name" alias onto
 * "first_name".
 *
 * The alias exists because the checkout POST field names are a frozen contract - they are
 * copied verbatim into the order property snapshot, and 35224 historical orders read back
 * "name" and "last_name" on the printed invoice. Mapping happens here rather than by
 * renaming form fields.
 *
 * October resolves mutators through methodExists(), which sees dynamic methods, so the
 * aliases below behave exactly like declared mutators.
 */
class ExtendRainLabUserModel
{
    const PHONE_DELIMITER = ',';

    /**
     * Apply the extension to a RainLab user model instance
     * @param \RainLab\User\Models\User $obElement
     */
    public function extend($obElement)
    {
        $this->addAttributes($obElement);
        $this->relaxValidationRules($obElement);
        $this->addNameAlias($obElement);
        $this->addPhoneAccessors($obElement);
        $this->addPropertyMerge($obElement);
    }

    /**
     * RainLab requires first_name; Buddies never did, and 3147 of the 13821 ported accounts
     * carry no name at all. Left required, every one of them becomes unsaveable - profile
     * edit, checkout phone update and backend save alike.
     *
     * @param \RainLab\User\Models\User $obElement
     */
    protected function relaxValidationRules($obElement)
    {
        $obElement->rules['first_name'] = ['nullable', 'string', 'max:255'];
    }

    /**
     * Columns added by update_table_users_add_buddies_columns
     * @param \RainLab\User\Models\User $obElement
     */
    protected function addAttributes($obElement)
    {
        // Both properties are protected on the RainLab model; these are the public setters.
        $obElement->mergeFillable([
            'name',
            'phone',
            'phone_list',
            'property',
        ]);

        $obElement->addJsonable(['property', 'viewed_products']);
    }

    /**
     * "name" reads and writes "first_name"
     * @param \RainLab\User\Models\User $obElement
     */
    protected function addNameAlias($obElement)
    {
        $obElement->addDynamicMethod('getNameAttribute', function () use ($obElement) {
            return $obElement->first_name;
        });

        $obElement->addDynamicMethod('setNameAttribute', function ($sValue) use ($obElement) {
            $obElement->first_name = $sValue;
        });
    }

    /**
     * "phone" carries a delimited list and derives "phone_short", same as Buddies
     * @param \RainLab\User\Models\User $obElement
     */
    protected function addPhoneAccessors($obElement)
    {
        $obElement->addDynamicMethod('setPhoneAttribute', function ($sValue) use ($obElement) {
            $obElement->setRawPhone((string) $sValue);
        });

        $obElement->addDynamicMethod('setRawPhone', function ($sValue) use ($obElement) {
            $arAttributeList = $obElement->getAttributes();
            $arAttributeList['phone'] = $sValue;
            $arAttributeList['phone_short'] = preg_replace('%[^\d,+]%', '', $sValue);

            $obElement->setRawAttributes($arAttributeList, false);
        });

        $obElement->addDynamicMethod('getPhoneListAttribute', function () use ($obElement) {
            return $this->splitPhone((string) $obElement->phone);
        });

        $obElement->addDynamicMethod('setPhoneListAttribute', function ($arValue) use ($obElement) {
            if (empty($arValue) || !is_array($arValue)) {
                return;
            }

            $arPhoneList = [];
            foreach ($arValue as $sValue) {
                $sValue = trim((string) $sValue);
                if ($sValue === '') {
                    continue;
                }

                $arPhoneList[] = $sValue;
            }

            $obElement->phone = implode(self::PHONE_DELIMITER, $arPhoneList);
        });
    }

    /**
     * Assigning "property" merges into the stored array instead of replacing it, matching
     * Lovata\Toolbox\Traits\Models\SetPropertyAttributeTrait. Partial forms - the checkout
     * form posts a subset - must not wipe the keys they do not carry.
     *
     * Bound through model.beforeSetAttribute because a trait cannot be mixed in at runtime.
     *
     * @param \RainLab\User\Models\User $obElement
     */
    protected function addPropertyMerge($obElement)
    {
        $obElement->bindEvent('model.beforeSetAttribute', function ($sKey, $arValue) use ($obElement) {
            if ($sKey != 'property') {
                return null;
            }

            if (is_string($arValue)) {
                $arValue = json_decode($arValue, true);
            }

            $arStored = $obElement->property;
            if (!is_array($arStored)) {
                $arStored = [];
            }

            // The Toolbox trait ignores an empty assignment outright - it can add or
            // overwrite a key, never remove one, so an empty value keeps the stored set.
            if (empty($arValue) || !is_array($arValue)) {
                return empty($arStored) ? null : $arStored;
            }

            if (empty($arStored)) {
                return $arValue;
            }

            return array_merge($arStored, $arValue);
        });
    }

    /**
     * @param string $sPhone
     * @return array
     */
    protected function splitPhone($sPhone)
    {
        if ($sPhone === '') {
            return [];
        }

        $arResult = [];
        foreach (explode(self::PHONE_DELIMITER, $sPhone) as $sPhoneNumber) {
            $sPhoneNumber = trim($sPhoneNumber);
            if ($sPhoneNumber === '') {
                continue;
            }

            $arResult[] = $sPhoneNumber;
        }

        return $arResult;
    }
}
