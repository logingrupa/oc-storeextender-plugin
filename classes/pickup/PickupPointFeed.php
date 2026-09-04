<?php namespace Logingrupa\StoreExtender\Classes\Pickup;

/**
 * One carrier's public pickup point feed: where it lives and how one raw row
 * becomes the shop's normalised point.
 *
 * Normalised point: id, name, address, city, zip (null when the feed carries
 * none), country (ISO 3166-1 alpha-2, uppercase).
 */
abstract class PickupPointFeed
{
    const CARRIER_DPD = 'dpd';
    const CARRIER_OMNIVA = 'omniva';

    /** @var array<string, class-string<PickupPointFeed>> */
    const FEED_CLASS_LIST = [
        self::CARRIER_DPD    => DpdPickupPointFeed::class,
        self::CARRIER_OMNIVA => OmnivaPickupPointFeed::class,
    ];

    /**
     * @param string $sCarrier
     * @return PickupPointFeed
     */
    public static function make(string $sCarrier): PickupPointFeed
    {
        $sClass = self::FEED_CLASS_LIST[$sCarrier] ?? null;
        if ($sClass === null) {
            throw new \InvalidArgumentException('Unknown pickup carrier "'.$sCarrier.'". Supported: '.implode(', ', array_keys(self::FEED_CLASS_LIST)));
        }

        return new $sClass();
    }

    abstract public function getCarrier(): string;

    abstract public function getUrl(): string;

    /**
     * Normalise one raw feed row; null when the row lacks an id, city or country.
     *
     * @param array $arRow
     * @return array|null
     */
    abstract public function normalizeRow(array $arRow): ?array;

    /**
     * Normalise the whole decoded feed, dropping unusable rows.
     *
     * @param array $arRowList
     * @return array
     */
    public function normalize(array $arRowList): array
    {
        $arPointList = [];
        foreach ($arRowList as $arRow) {
            if (!is_array($arRow)) {
                continue;
            }
            $arPoint = $this->normalizeRow($arRow);
            if ($arPoint !== null) {
                $arPointList[] = $arPoint;
            }
        }

        return $arPointList;
    }

    /**
     * Feed cities arrive in any case ("TUKUMS", "Rīga"); title case reads the same everywhere.
     *
     * @param string $sCity
     * @return string
     */
    protected function titleCase(string $sCity): string
    {
        return mb_convert_case(trim($sCity), MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * @param array  $arRow
     * @param string $sKey
     * @return string
     */
    protected function stringField(array $arRow, string $sKey): string
    {
        $mValue = $arRow[$sKey] ?? null;

        return is_scalar($mValue) ? trim((string) $mValue) : '';
    }
}
