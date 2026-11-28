<?php

declare(strict_types=1);

require_once __DIR__ . '/../StoreExtenderPluginTestCase.php';
require_once __DIR__ . '/../doubles/OrderStubTables.php';
require_once __DIR__ . '/../doubles/ShippingLadderStubTables.php';
require_once __DIR__ . '/../../updates/create_table_order_attributions.php';

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Session;
use Logingrupa\StoreExtender\Classes\Event\Order\ExtendOrderControllerAttribution;
use Logingrupa\StoreExtender\Classes\Event\Order\OrderAttributionHandler;
use Logingrupa\StoreExtender\Classes\Helper\OrderAttributionContext;
use Logingrupa\StoreExtender\Classes\Middleware\AiReferrerCaptureMiddleware;
use Logingrupa\StoreExtender\Updates\CreateTableOrderAttributions;
use Lovata\OrdersShopaholic\Classes\Processor\CartProcessor;
use Lovata\OrdersShopaholic\Classes\Processor\OrderProcessor;
use Lovata\OrdersShopaholic\Models\Order;

/**
 * Covers server-side order attribution stamping, anti-spoofing, beforeUpdate immutability,
 * trusted downgrade seam, AI referrer capture, post-commit session cleanup, and indexed backend filtering.
 */
class OrderAttributionTest extends StoreExtenderPluginTestCase
{
    protected $autoMigrate = false;

    public function setUp(): void
    {
        parent::setUp();

        OrderStubTables::create();
        ShippingLadderStubTables::create();
        (new CreateTableOrderAttributions())->up();
        app(OrderAttributionContext::class)->clear();
    }

    public function tearDown(): void
    {
        CartProcessor::$iTestCartID = null;
        CartProcessor::forgetInstance();
        app(OrderAttributionContext::class)->clear();
        if (Session::isStarted()) {
            Session::forget(OrderAttributionContext::SESSION_KEY);
        }
        (new CreateTableOrderAttributions())->down();

        parent::tearDown();
    }

    public function testSpoofedBrowserOrderPropertyIsOverwrittenWithoutClobberingOutOfStock(): void
    {
        $obOrder = Order::create([
            'status_id' => 1,
            'site_id' => 1,
            'property' => [
                'order_channel' => 'agent_b2b',
                'agent_client' => 'spoofed_client',
                'out_of_stock' => '1',
            ],
        ]);

        $obReloaded = Order::findOrFail($obOrder->id);
        $this->assertSame('web', $obReloaded->property['order_channel'] ?? null);
        $this->assertArrayNotHasKey('agent_client', (array) $obReloaded->property);
        $this->assertSame('1', $obReloaded->property['out_of_stock'] ?? null);

        $obRow = DB::table(CreateTableOrderAttributions::TABLE)->where('order_id', $obOrder->id)->first();
        $this->assertNotNull($obRow);
        $this->assertSame('web', $obRow->order_channel);
        $this->assertNull($obRow->agent_client);
    }

    public function testTrustedContextStampsB2bAttributionAndBeforeUpdatePreservesItAcrossSaves(): void
    {
        app(OrderAttributionContext::class)->set(
            OrderAttributionContext::CHANNEL_AGENT_B2B,
            'nc_no_restock',
            true,
            true
        );

        $obOrder = Order::create([
            'status_id' => 1,
            'site_id' => 1,
            'shipping_price' => 5.0,
        ]);
        app(OrderAttributionContext::class)->clear();

        // Simulate a later 1C status/shipping save and an attempted property overwrite.
        $obOrder->status_id = 2;
        $obOrder->shipping_price = 0.0;
        $obOrder->property = ['order_channel' => 'web', 'agent_client' => 'tampered', 'note' => '1c'];
        $obOrder->save();

        $obReloaded = Order::findOrFail($obOrder->id);
        $this->assertSame('agent_b2b', $obReloaded->property['order_channel'] ?? null);
        $this->assertSame('nc_no_restock', $obReloaded->property['agent_client'] ?? null);
        $this->assertSame('1c', $obReloaded->property['note'] ?? null);
    }

    public function testTrustedDowngradeUpdatesBothOrderAndProjectionAndSurvivesSubsequentSave(): void
    {
        app(OrderAttributionContext::class)->set(
            OrderAttributionContext::CHANNEL_AGENT_API,
            'mcp',
            true
        );
        $obOrder = Order::create(['status_id' => 1, 'site_id' => 1]);
        app(OrderAttributionContext::class)->clear();

        OrderAttributionHandler::downgradeToWebInsideTransaction($obOrder);

        // Simulate OrderProcessor's post-commit second save().
        $obOrder->save();

        $obReloaded = Order::findOrFail($obOrder->id);
        $this->assertSame('web', $obReloaded->property['order_channel'] ?? null);
        $this->assertArrayNotHasKey('agent_client', (array) $obReloaded->property);

        $obRow = DB::table(CreateTableOrderAttributions::TABLE)->where('order_id', $obOrder->id)->first();
        $this->assertNotNull($obRow);
        $this->assertSame('web', $obRow->order_channel);
        $this->assertNull($obRow->agent_client);
    }

    public function testAiReferrerMiddlewareCapturesReferrerAndNeverOverwritesVerifiedHandoff(): void
    {
        $obMiddleware = new AiReferrerCaptureMiddleware();
        $obSession = app('session.store');
        $obSession->start();

        $obRequest = Request::create('/lv/p/gel-polish', 'GET', [], [], [], [
            'HTTP_REFERER' => 'https://chatgpt.com/c/123',
        ]);
        $obRequest->setLaravelSession($obSession);
        $obMiddleware->handle($obRequest, static fn () => 'ok');

        $arStored = $obSession->get(OrderAttributionContext::SESSION_KEY);
        $this->assertSame('ai_referral', $arStored['order_channel'] ?? null);
        $this->assertSame('chatgpt', $arStored['agent_client'] ?? null);
        $this->assertFalse($arStored['verified'] ?? true);

        // Once a verified handoff is present, a subsequent AI referrer does not overwrite it.
        $obSession->put(OrderAttributionContext::SESSION_KEY, [
            'order_channel' => OrderAttributionContext::CHANNEL_AGENT_API,
            'agent_client' => 'mcp',
            'verified' => true,
            'handoff_id' => 77,
        ]);
        $obSecondRequest = Request::create('/lv/p/gel-polish?utm_source=perplexity', 'GET');
        $obSecondRequest->setLaravelSession($obSession);
        $obMiddleware->handle($obSecondRequest, static fn () => 'ok');

        $arAfter = $obSession->get(OrderAttributionContext::SESSION_KEY);
        $this->assertSame('agent_api', $arAfter['order_channel'] ?? null);
        $this->assertSame('mcp', $arAfter['agent_client'] ?? null);
    }

    public function testSessionAttributionIsRetainedOnRollbackAndClearedOnCommit(): void
    {
        Session::start();
        Session::put(OrderAttributionContext::SESSION_KEY, [
            'order_channel' => OrderAttributionContext::CHANNEL_AGENT_API,
            'agent_client' => 'mcp',
            'verified' => true,
        ]);

        try {
            DB::transaction(static function (): void {
                $obOrder = Order::create(['status_id' => 1, 'site_id' => 1]);
                Event::fire(OrderProcessor::EVENT_UPDATE_ORDER_AFTER_CREATE, $obOrder);
                throw new \RuntimeException('checkout rolled back');
            });
        } catch (\RuntimeException) {
            // Expected rollback.
        }

        $this->assertNotNull(Session::get(OrderAttributionContext::SESSION_KEY));

        DB::transaction(static function (): void {
            $obOrder = Order::create(['status_id' => 1, 'site_id' => 1]);
            Event::fire(OrderProcessor::EVENT_UPDATE_ORDER_AFTER_CREATE, $obOrder);
        });

        $this->assertNull(Session::get(OrderAttributionContext::SESSION_KEY));

        // Next ordinary order in the same session stamps web.
        $obNextOrder = Order::create(['status_id' => 1, 'site_id' => 1]);
        $this->assertSame('web', $obNextOrder->property['order_channel'] ?? null);
    }

    public function testIndexedChannelFilterAndFormatterWorkAcrossChannelsAndLegacyRows(): void
    {
        $obWebOrder = Order::create(['status_id' => 1, 'site_id' => 1]);

        app(OrderAttributionContext::class)->set(OrderAttributionContext::CHANNEL_AGENT_B2B, 'nc_lt_restock', true, true);
        $obB2bOrder = Order::create(['status_id' => 1, 'site_id' => 1]);
        app(OrderAttributionContext::class)->clear();

        $arFilteredIds = ExtendOrderControllerAttribution::applyChannelFilter(
            Order::query(),
            [OrderAttributionContext::CHANNEL_AGENT_B2B]
        )->pluck('id')->all();

        $this->assertSame([$obB2bOrder->id], $arFilteredIds);
        $this->assertNotContains($obWebOrder->id, $arFilteredIds);
        $this->assertSame('Unknown', ExtendOrderControllerAttribution::formatChannelColumn(null));
        $this->assertSame('AI Agent (B2B) (nc_lt_restock)', ExtendOrderControllerAttribution::formatChannelColumn($obB2bOrder->property));
    }
}
