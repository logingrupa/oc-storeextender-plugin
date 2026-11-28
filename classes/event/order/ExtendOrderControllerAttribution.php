<?php

declare(strict_types=1);

namespace Logingrupa\StoreExtender\Classes\Event\Order;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Logingrupa\StoreExtender\Classes\Helper\OrderAttributionContext;
use Lovata\OrdersShopaholic\Controllers\Orders;
use Lovata\OrdersShopaholic\Models\Order;

/**
 * Adds a non-searchable Channel column and an indexed WHERE EXISTS Channel filter scope
 * to the Shopaholic Orders backend list.
 */
class ExtendOrderControllerAttribution
{
    public const SCOPE_NAME = 'order_channel';

    /** @var array<string, string> */
    private const CHANNEL_LABELS = [
        OrderAttributionContext::CHANNEL_WEB => 'Web',
        OrderAttributionContext::CHANNEL_AI_REFERRAL => 'AI Referral',
        OrderAttributionContext::CHANNEL_AGENT_BROWSER => 'AI Browser Agent',
        OrderAttributionContext::CHANNEL_AGENT_API => 'AI Agent (Handoff)',
        OrderAttributionContext::CHANNEL_AGENT_B2B => 'AI Agent (B2B)',
        OrderAttributionContext::CHANNEL_BACKEND => 'Backend',
        OrderAttributionContext::CHANNEL_CONSOLE => 'Console',
    ];

    /**
     * @param  mixed  $obEvent  untyped: October passes PriorityDispatcher
     */
    public function subscribe($obEvent): void
    {
        Order::extend(function (Order $obOrder): void {
            $obOrder->addDynamicMethod('scopeFilterOrderChannel', function ($obQuery, $mChannels) {
                return self::applyChannelFilter($obQuery, $mChannels);
            });
            $obOrder->addDynamicMethod('getOrderChannelDisplayAttribute', function () use ($obOrder): string {
                return self::formatChannelColumn($obOrder->property);
            });
        });

        $obEvent->listen('backend.list.extendColumns', function ($obWidget): void {
            if (!$obWidget->getController() instanceof Orders || !$obWidget->model instanceof Order) {
                return;
            }

            $obWidget->addColumns([
                'order_channel_display' => [
                    'label' => 'Channel',
                    'type' => 'text',
                    'searchable' => false,
                    'sortable' => false,
                    'select' => false,
                ],
            ]);
        });

        $obEvent->listen('backend.filter.extendScopes', function ($obFilter): void {
            if (!$obFilter->getController() instanceof Orders) {
                return;
            }

            $obFilter->addScopes([
                self::SCOPE_NAME => [
                    'label' => 'Channel',
                    'type' => 'group',
                    'options' => self::CHANNEL_LABELS,
                    'modelScope' => 'filterOrderChannel',
                ],
            ]);
        });
    }

    public static function formatChannelColumn(mixed $mProperty): string
    {
        if (!is_array($mProperty)) {
            return 'Unknown';
        }

        $sChannel = is_string($mProperty[OrderAttributionHandler::PROPERTY_CHANNEL] ?? null)
            ? $mProperty[OrderAttributionHandler::PROPERTY_CHANNEL]
            : '';
        if ($sChannel === '') {
            return 'Unknown';
        }

        $sLabel = self::CHANNEL_LABELS[$sChannel] ?? $sChannel;
        $sClient = OrderAttributionContext::sanitizeClientSlug($mProperty[OrderAttributionHandler::PROPERTY_CLIENT] ?? null);

        return $sClient !== null ? $sLabel . ' (' . $sClient . ')' : $sLabel;
    }

    /**
     * Filters orders through the indexed projection table rather than scanning MEDIUMTEXT JSON.
     *
     * @param  mixed  $obQuery
     * @param  mixed  $mChannels
     * @return mixed
     */
    public static function applyChannelFilter($obQuery, $mChannels)
    {
        $arSelected = self::normalizeChannels($mChannels);
        if (empty($arSelected) || !Schema::hasTable(OrderAttributionContext::PROJECTION_TABLE)) {
            return $obQuery;
        }

        $sOrderTable = $obQuery->getModel()->getTable();
        $sProjectionTable = OrderAttributionContext::PROJECTION_TABLE;

        return $obQuery->whereExists(static function ($obSubQuery) use ($sOrderTable, $sProjectionTable, $arSelected): void {
            $obSubQuery->select(DB::raw(1))
                ->from($sProjectionTable)
                ->whereColumn($sProjectionTable . '.order_id', $sOrderTable . '.id')
                ->whereIn($sProjectionTable . '.order_channel', $arSelected);
        });
    }

    /**
     * @return list<string>
     */
    private static function normalizeChannels(mixed $mChannels): array
    {
        $arRaw = is_array($mChannels) ? $mChannels : (is_string($mChannels) ? [$mChannels] : []);
        $arValid = [];
        foreach ($arRaw as $mKey => $mValue) {
            $sCandidate = is_string($mValue) ? $mValue : (is_string($mKey) ? $mKey : '');
            if (in_array($sCandidate, OrderAttributionContext::ALLOWED_CHANNEL_LIST, true)) {
                $arValid[] = $sCandidate;
            }
        }

        return array_values(array_unique($arValid));
    }
}
