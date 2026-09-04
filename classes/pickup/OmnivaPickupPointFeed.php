<?php namespace Logingrupa\StoreExtender\Classes\Pickup;

/**
 * Omniva parcel machines and post offices (EE, LV, LT), one public JSON.
 *
 * Row: ZIP is Omniva's own location id, not a postal code. A0_NAME country,
 * A1_NAME region, A2_NAME municipality or town, A3_NAME town or village,
 * A5_NAME street, A7_NAME house number.
 */
class OmnivaPickupPointFeed extends PickupPointFeed
{
    const URL = 'https://www.omniva.lv/locations.json';

    public function getCarrier(): string
    {
        return self::CARRIER_OMNIVA;
    }

    public function getUrl(): string
    {
        return self::URL;
    }

    public function normalizeRow(array $arRow): ?array
    {
        $sId = $this->stringField($arRow, 'ZIP');
        $sCountry = strtoupper($this->stringField($arRow, 'A0_NAME'));
        $sCity = $this->stringField($arRow, 'A3_NAME') ?: $this->stringField($arRow, 'A2_NAME') ?: $this->stringField($arRow, 'A1_NAME');
        if ($sId === '' || $sCity === '' || preg_match('/^[A-Z]{2}$/', $sCountry) !== 1) {
            return null;
        }

        return [
            'id'      => $sId,
            'name'    => $this->stringField($arRow, 'NAME'),
            'address' => trim($this->stringField($arRow, 'A5_NAME').' '.$this->stringField($arRow, 'A7_NAME')),
            'city'    => $this->titleCase($sCity),
            'zip'     => null,
            'country' => $sCountry,
        ];
    }
}
