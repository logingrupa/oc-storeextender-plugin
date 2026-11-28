<?php

declare(strict_types=1);

namespace Logingrupa\StoreExtender\Classes\Event\Order;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;
use Logingrupa\StoreExtender\Classes\Helper\OrderAttributionContext;
use Lovata\OrdersShopaholic\Classes\Processor\OrderProcessor;
use Lovata\OrdersShopaholic\Models\Order;
use Throwable;

/**
 * Authoritatively stamps order_channel and agent_client on Order creation, protects those keys
 * from post-create overwrites, maintains the indexed attribution projection for backend filters,
 * and clears session handoff attribution only after transaction commit.
 */
class OrderAttributionHandler
{
    public const PROPERTY_CHANNEL = 'order_channel';
    public const PROPERTY_CLIENT = 'agent_client';

    /** @var array<int, array{order_channel: string, agent_client: ?string, verified: bool}> */
    private array $arPendingProjectionMap = [];

    /**
     * @param  mixed  $obEvent  untyped: October passes PriorityDispatcher
     */
    public function subscribe($obEvent): void
    {
        Order::extend(function (Order $obOrder): void {
            $obOrder->bindEvent('model.beforeCreate', function () use ($obOrder): void {
                $this->stampBeforeCreate($obOrder);
            });

            $obOrder->bindEvent('model.beforeUpdate', function () use ($obOrder): void {
                $this->preserveBeforeUpdate($obOrder);
            });

            $obOrder->bindEvent('model.afterCreate', function () use ($obOrder): void {
                $this->recordProjectionAfterCreate($obOrder);
            });
        });

        $obEvent->listen(OrderProcessor::EVENT_UPDATE_ORDER_AFTER_CREATE, function ($obOrder): void {
            if (!$obOrder instanceof Order) {
                return;
            }

            $obOrder->getConnection()->afterCommit(static function (): void {
                try {
                    if (Session::isStarted()) {
                        Session::forget(OrderAttributionContext::SESSION_KEY);
                    }
                } catch (Throwable) {
                    // Stateless/console contexts may have no active session store.
                }
            });
        });
    }

    /**
     * Trusted correction seam for downgrading an already-completed handoff inside the order transaction.
     */
    public static function downgradeToWebInsideTransaction(Order $obOrder): void
    {
        $iOrderId = (int) $obOrder->getKey();
        if ($iOrderId < 1) {
            return;
        }

        $obContext = App::make(OrderAttributionContext::class);
        $iPriorAllowed = $obContext->iAllowedCorrectionOrderId;
        $obContext->iAllowedCorrectionOrderId = $iOrderId;

        try {
            self::writeRawAttribution($obOrder, OrderAttributionContext::CHANNEL_WEB, null);
            $obOrder->save();
            $obOrder->syncOriginal();

            if (Schema::hasTable(OrderAttributionContext::PROJECTION_TABLE)) {
                DB::table(OrderAttributionContext::PROJECTION_TABLE)->updateOrInsert(
                    ['order_id' => $iOrderId],
                    [
                        'order_channel' => OrderAttributionContext::CHANNEL_WEB,
                        'agent_client' => null,
                        'verified' => false,
                        'created_at' => $obOrder->created_at ?? Carbon::now(),
                    ]
                );
            }
        } finally {
            $obContext->iAllowedCorrectionOrderId = $iPriorAllowed;
        }
    }

    protected function stampBeforeCreate(Order $obOrder): void
    {
        $arResolved = $this->resolveTrustedAttribution();
        self::writeRawAttribution($obOrder, $arResolved['order_channel'], $arResolved['agent_client']);
        $this->arPendingProjectionMap[spl_object_id($obOrder)] = $arResolved;
    }

    protected function preserveBeforeUpdate(Order $obOrder): void
    {
        $obContext = App::make(OrderAttributionContext::class);
        if ($obContext->iAllowedCorrectionOrderId !== null && $obContext->iAllowedCorrectionOrderId === (int) $obOrder->getKey()) {
            return;
        }

        $arOriginal = self::decodeRawProperty($obOrder->getRawOriginal('property'));
        $sOriginalChannel = is_string($arOriginal[self::PROPERTY_CHANNEL] ?? null)
            ? $arOriginal[self::PROPERTY_CHANNEL]
            : null;
        if ($sOriginalChannel === null || !in_array($sOriginalChannel, OrderAttributionContext::ALLOWED_CHANNEL_LIST, true)) {
            return;
        }

        $sOriginalClient = OrderAttributionContext::sanitizeClientSlug($arOriginal[self::PROPERTY_CLIENT] ?? null);
        $arCurrent = is_array($obOrder->property) ? $obOrder->property : [];
        $sCurrentChannel = $arCurrent[self::PROPERTY_CHANNEL] ?? null;
        $sCurrentClient = $arCurrent[self::PROPERTY_CLIENT] ?? null;

        if ($sCurrentChannel === $sOriginalChannel && $sCurrentClient === $sOriginalClient) {
            return;
        }

        self::writeRawAttribution($obOrder, $sOriginalChannel, $sOriginalClient);
    }

    protected function recordProjectionAfterCreate(Order $obOrder): void
    {
        $iObjectId = spl_object_id($obOrder);
        $arResolved = $this->arPendingProjectionMap[$iObjectId] ?? null;
        unset($this->arPendingProjectionMap[$iObjectId]);

        $iOrderId = (int) $obOrder->getKey();
        if ($arResolved === null || $iOrderId < 1) {
            return;
        }

        try {
            if (!Schema::hasTable(OrderAttributionContext::PROJECTION_TABLE)) {
                return;
            }

            DB::table(OrderAttributionContext::PROJECTION_TABLE)->updateOrInsert(
                ['order_id' => $iOrderId],
                [
                    'order_channel' => $arResolved['order_channel'],
                    'agent_client' => $arResolved['agent_client'],
                    'verified' => $arResolved['verified'],
                    'created_at' => $obOrder->created_at ?? Carbon::now(),
                ]
            );
        } catch (Throwable $obException) {
            Log::warning('Order attribution projection write failed.', [
                'order_id' => $iOrderId,
                'exception' => $obException::class,
            ]);
        }
    }

    /**
     * @return array{order_channel: string, agent_client: ?string, verified: bool}
     */
    private function resolveTrustedAttribution(): array
    {
        $obContext = App::make(OrderAttributionContext::class);
        if ($obContext->hasAttribution() && $obContext->sOrderChannel !== null) {
            return [
                'order_channel' => $obContext->sOrderChannel,
                'agent_client' => $obContext->sAgentClient,
                'verified' => $obContext->bVerified,
            ];
        }

        $arSession = $this->readSessionAttribution();
        if ($arSession !== null) {
            return $arSession;
        }

        if (App::runningInBackend()) {
            return ['order_channel' => OrderAttributionContext::CHANNEL_BACKEND, 'agent_client' => null, 'verified' => true];
        }

        if (App::runningInConsole() && !App::runningUnitTests()) {
            return ['order_channel' => OrderAttributionContext::CHANNEL_CONSOLE, 'agent_client' => null, 'verified' => true];
        }

        return ['order_channel' => OrderAttributionContext::CHANNEL_WEB, 'agent_client' => null, 'verified' => true];
    }

    /**
     * @return array{order_channel: string, agent_client: ?string, verified: bool}|null
     */
    private function readSessionAttribution(): ?array
    {
        try {
            if (!Session::isStarted()) {
                return null;
            }

            $arStored = Session::get(OrderAttributionContext::SESSION_KEY);
        } catch (Throwable) {
            return null;
        }

        if (!is_array($arStored)) {
            return null;
        }

        $sChannel = $arStored['order_channel'] ?? null;
        if (!is_string($sChannel) || !in_array($sChannel, OrderAttributionContext::ALLOWED_CHANNEL_LIST, true)) {
            return null;
        }

        return [
            'order_channel' => $sChannel,
            'agent_client' => OrderAttributionContext::sanitizeClientSlug($arStored['agent_client'] ?? null),
            'verified' => !empty($arStored['verified']),
        ];
    }

    /**
     * Rewrites the raw property JSON attribute directly so removing a spoofed agent_client
     * bypasses Toolbox SetPropertyAttributeTrait's merge-only setter.
     */
    private static function writeRawAttribution(Order $obOrder, string $sChannel, ?string $sClient): void
    {
        $arProperty = is_array($obOrder->property) ? $obOrder->property : [];
        $arProperty[self::PROPERTY_CHANNEL] = $sChannel;
        if ($sClient !== null) {
            $arProperty[self::PROPERTY_CLIENT] = $sClient;
        } else {
            unset($arProperty[self::PROPERTY_CLIENT]);
        }

        $arAttributeList = $obOrder->getAttributes();
        $arAttributeList['property'] = json_encode($arProperty, JSON_UNESCAPED_UNICODE);
        $obOrder->setRawAttributes($arAttributeList);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeRawProperty(mixed $mRaw): array
    {
        if (is_array($mRaw)) {
            return $mRaw;
        }
        if (!is_string($mRaw) || trim($mRaw) === '') {
            return [];
        }

        $mDecoded = json_decode($mRaw, true);

        return is_array($mDecoded) ? $mDecoded : [];
    }
}
