<?php namespace Logingrupa\StoreExtender\Classes\Pickup;

/**
 * DPD Baltics pickup stations and parcel shops (EE, LV, LT), one public JSON.
 *
 * Row: parcelShopId, companyName, street (house number included since 2025),
 * houseNo, city (uppercase), zipCode (digits only), countryCode.
 */
class DpdPickupPointFeed extends PickupPointFeed
{
    const URL = 'https://dpdbaltics.com/PickupParcelShopData.json';

    /** Countries whose postal codes are written with the ISO prefix ("LV-3601"). */
    const PREFIXED_ZIP_COUNTRY_LIST = ['LV', 'LT'];

    public function getCarrier(): string
    {
        return self::CARRIER_DPD;
    }

    public function getUrl(): string
    {
        return self::URL;
    }

    public function normalizeRow(array $arRow): ?array
    {
        $sId = $this->stringField($arRow, 'parcelShopId');
        $sCity = $this->stringField($arRow, 'city');
        $sCountry = strtoupper($this->stringField($arRow, 'countryCode'));
        if ($sId === '' || $sCity === '' || preg_match('/^[A-Z]{2}$/', $sCountry) !== 1) {
            return null;
        }

        $sZip = $this->stringField($arRow, 'zipCode');
        if ($sZip !== '' && in_array($sCountry, self::PREFIXED_ZIP_COUNTRY_LIST, true)) {
            $sZip = $sCountry.'-'.$sZip;
        }

        return [
            'id'      => $sId,
            'name'    => $this->stringField($arRow, 'companyName'),
            'address' => trim($this->stringField($arRow, 'street').' '.$this->stringField($arRow, 'houseNo')),
            'city'    => $this->titleCase($sCity),
            'zip'     => $sZip === '' ? null : $sZip,
            'country' => $sCountry,
        ];
    }
}
