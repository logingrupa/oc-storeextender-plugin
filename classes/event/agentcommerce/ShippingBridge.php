<?php

declare(strict_types=1);

namespace Logingrupa\StoreExtender\Classes\Event\AgentCommerce;

use Logingrupa\StoreExtender\Classes\Pickup\PickupPointRepository;

/**
 * String-event bridge attaching disk-cached DPD/Omniva pickup points to AgentCommerce
 * shipping options. Reads strictly via PickupPointRepository::readStored() (zero live HTTP).
 */
class ShippingBridge
{
    /**
     * @param  mixed  $obEvent  untyped: October passes PriorityDispatcher
     */
    public function subscribe($obEvent): void
    {
        $obEvent->listen('agentcommerce.shipping.extendOptions', function ($arOptions, $arQuery): array {
            if (!is_array($arOptions)) {
                return [];
            }

            return $this->attachStoredPickupPoints($arOptions, is_array($arQuery) ? $arQuery : []);
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $arOptions
     * @param  array<string, mixed>  $arQuery
     * @return list<array<string, mixed>>
     */
    public function attachStoredPickupPoints(array $arOptions, array $arQuery): array
    {
        $sCountry = is_string($arQuery['country'] ?? null) ? strtoupper(trim($arQuery['country'])) : '';
        $sCarrierFilter = is_string($arQuery['carrier'] ?? null) ? strtolower(trim($arQuery['carrier'])) : '';
        $iPointLimit = is_numeric($arQuery['pickup_limit'] ?? null) ? max(1, min(100, (int) $arQuery['pickup_limit'])) : 25;

        $obRepo = new PickupPointRepository();
        $arLoadedFeeds = [];

        $arResult = [];
        foreach ($arOptions as $arOption) {
            $sCode = strtolower((string) ($arOption['code'] ?? ''));
            $sCarrier = $this->detectCarrierFromCode($sCode);

            if ($sCarrierFilter !== '' && $sCarrier !== $sCarrierFilter) {
                $arResult[] = $arOption;
                continue;
            }

            if ($sCarrier !== null) {
                if (!array_key_exists($sCarrier, $arLoadedFeeds)) {
                    $arLoadedFeeds[$sCarrier] = $obRepo->readStored($sCarrier);
                }

                $arStored = $arLoadedFeeds[$sCarrier];
                if (is_array($arStored) && is_array($arStored['points'] ?? null)) {
                    $arPoints = $arStored['points'];
                    if ($sCountry !== '' && preg_match('/\A[A-Z]{2}\z/', $sCountry) === 1) {
                        $arPoints = array_values(array_filter(
                            $arPoints,
                            static fn (mixed $mPoint): bool => is_array($mPoint) && strtoupper((string) ($mPoint['country'] ?? '')) === $sCountry
                        ));
                    }
                    $arOption['pickup_points'] = array_slice($arPoints, 0, $iPointLimit);
                }
            }

            $arResult[] = $arOption;
        }

        return $arResult;
    }

    private function detectCarrierFromCode(string $sCode): ?string
    {
        if (str_contains($sCode, 'omniva')) {
            return 'omniva';
        }
        if (str_contains($sCode, 'dpd')) {
            return 'dpd';
        }

        return null;
    }
}
