<?php

require_once __DIR__.'/../StoreExtenderUserPluginTestCase.php';
require_once __DIR__.'/../doubles/CheckoutStubTables.php';

use Backend\Widgets\Form as BackendFormWidget;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Kharanenka\Helper\Result;
use Logingrupa\StoreExtender\Classes\Event\User\ExtendUserController;
use Logingrupa\StoreExtender\Classes\Event\User\UserAddressProfileHandler;
use Logingrupa\StoreExtender\Classes\Helper\LegacyPickupAddressParser;
use Logingrupa\StoreExtender\Classes\Helper\UserAddressHelper;
use Logingrupa\StoreExtender\Models\UserProperty;
use Lovata\OrdersShopaholic\Classes\Processor\CartProcessor;
use Lovata\OrdersShopaholic\Classes\Processor\OrderProcessor;
use Lovata\OrdersShopaholic\Classes\Store\UserAddressListStore;
use Lovata\OrdersShopaholic\Components\MakeOrder;
use Lovata\OrdersShopaholic\Models\Order;
use Lovata\OrdersShopaholic\Models\Status;
use Lovata\OrdersShopaholic\Models\UserAddress;
use Lovata\Shopaholic\Classes\Helper\CurrencyHelper;
use Lovata\Shopaholic\Classes\Helper\TaxHelper;
use Lovata\Shopaholic\Classes\Item\OfferItem;
use Lovata\Shopaholic\Classes\Item\ProductItem;
use Lovata\Shopaholic\Models\Settings;
use Lovata\Toolbox\Classes\Helper\UserHelper;
use October\Rain\Element\ElementHolder;
use RainLab\User\Controllers\Users as RainLabUsersController;
use RainLab\User\Models\Setting;
use RainLab\User\Models\User;

class UserAddressTest extends StoreExtenderUserPluginTestCase
{
    /** @var array<int, array<int, string>> */
    protected $arQueryWithList = [];

    public function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessUserPlugin('RainLab.User');

        Settings::clearInternalCache();
        Setting::clearInternalCache();
        CheckoutStubTables::create();
        CheckoutStubTables::seed();

        if (! Schema::hasTable('lovata_orders_shopaholic_user_addresses')) {
            Schema::create('lovata_orders_shopaholic_user_addresses', function ($obTable) {
                $obTable->increments('id');
                $obTable->integer('user_id')->unsigned();
                $obTable->string('type');
                $obTable->string('country')->nullable();
                $obTable->string('state')->nullable();
                $obTable->string('city')->nullable();
                $obTable->string('street')->nullable();
                $obTable->string('house')->nullable();
                $obTable->string('building')->nullable();
                $obTable->string('flat')->nullable();
                $obTable->integer('floor')->nullable();
                $obTable->string('address1')->nullable();
                $obTable->string('address2')->nullable();
                $obTable->string('postcode')->nullable();
                $obTable->timestamps();
            });
        }

        $this->arQueryWithList = [OfferItem::$arQueryWith, ProductItem::$arQueryWith];
        OfferItem::$arQueryWith = [];
        ProductItem::$arQueryWith = [];
        Settings::set('create_new_user', '1');
        Result::setTrue();
    }

    public function tearDown(): void
    {
        [OfferItem::$arQueryWith, ProductItem::$arQueryWith] = $this->arQueryWithList;
        $this->forgetCheckoutState();
        Settings::clearInternalCache();
        Setting::clearInternalCache();
        Result::setTrue();

        parent::tearDown();
    }

    public function test_save_update_and_clear_both_addresses_through_helper(): void
    {
        $obUser = $this->createAccount('addresses@example.com');

        $obShipping = UserAddressHelper::saveAddress($obUser, UserAddressHelper::TYPE_SHIPPING, [
            'address1' => ' Storgata 10 ',
            'address2' => 'Apt 4',
            'city' => 'Oslo',
            'state' => 'Oslo',
            'postcode' => '0150',
            'country' => 'Norway',
        ]);
        $obBilling = UserAddressHelper::saveAddress($obUser, UserAddressHelper::TYPE_BILLING, [
            'address1' => 'Karl Johans gate 1',
            'city' => 'Oslo',
            'postcode' => '0154',
            'country' => 'Norway',
        ]);

        $this->assertNotNull($obShipping);
        $this->assertNotNull($obBilling);
        $this->assertSame(2, UserAddress::where('user_id', $obUser->id)->count());

        $arCheckout = UserAddressHelper::getCheckoutAddresses($obUser);
        $this->assertTrue($arCheckout['shipping']['has_value']);
        $this->assertSame('Storgata 10', $arCheckout['shipping']['address1']);
        $this->assertSame('0150', $arCheckout['shipping']['postcode']);
        $this->assertTrue($arCheckout['billing']['has_value']);
        $this->assertSame('Karl Johans gate 1', $arCheckout['billing']['address1']);

        // Updating in place keeps the same row ID and does not create duplicates.
        $obUpdated = UserAddressHelper::saveAddress($obUser, UserAddressHelper::TYPE_SHIPPING, [
            'id' => $obShipping->id,
            'address1' => 'Storgata 22',
            'city' => 'Oslo',
            'postcode' => '0150',
            'country' => 'Norway',
        ]);
        $this->assertSame($obShipping->id, $obUpdated->id);
        $this->assertSame('Storgata 22', $obUpdated->fresh()->address1);
        $this->assertSame(2, UserAddress::where('user_id', $obUser->id)->count());

        // Clearing all billing fields removes the billing row rather than saving an empty one.
        $mCleared = UserAddressHelper::saveAddress($obUser, UserAddressHelper::TYPE_BILLING, [
            'id' => $obBilling->id,
            'address1' => '',
            'address2' => '',
            'city' => '',
            'state' => '',
            'postcode' => '',
            'country' => '',
        ]);
        $this->assertNull($mCleared);
        $this->assertSame(1, UserAddress::where('user_id', $obUser->id)->count());
        $this->assertFalse(UserAddressHelper::getCheckoutAddresses($obUser)['billing']['has_value']);
    }

    public function test_primary_shipping_lookup_preserves_pickup_addresses_and_cleans_glued_legacy_junk(): void
    {
        $obUser = $this->createAccount('pickup-keep@example.com');

        UserAddress::create([
            'user_id' => $obUser->id,
            'type' => UserAddressHelper::TYPE_SHIPPING,
            'address1' => '',
            'address2' => 'undefinedSelgas iela 2 , ROJA, LV3264, (Paku Skapis Maxima Roja)',
        ]);

        // Newer pickup-point row must win and have glued legacy "undefined" stripped cleanly.
        UserAddress::create([
            'user_id' => $obUser->id,
            'type' => UserAddressHelper::TYPE_SHIPPING,
            'address1' => '',
            'address2' => 'undefinedDundagas iela 18 , TALSI, LV3201, (Paku Skapis Rimi Talsi)',
            'city' => 'Talsi',
            'postcode' => 'LV-3201',
            'country' => 'LV',
        ]);

        $arShipping = UserAddressHelper::getCheckoutAddresses($obUser)['shipping'];
        $this->assertTrue($arShipping['has_value']);
        $this->assertSame('', $arShipping['address1']);
        $this->assertSame('Dundagas iela 18 , TALSI, LV3201, (Paku Skapis Rimi Talsi)', $arShipping['address2']);
        $this->assertSame('Talsi', $arShipping['city']);

        $this->assertSame('Ernesta Birznieka-Upīša iela 20A, Rīgā - BEZMAKSAS', UserAddressHelper::cleanValue('Ernesta Birznieka-Upīša iela 20A, Rīgā - BEZMAKSASundefined'));
        $this->assertSame('', UserAddressHelper::cleanValue('NaN'));
        $this->assertSame('Nantes', UserAddressHelper::cleanValue('Nantes'));
    }

    public function test_profile_update_event_saves_both_addresses_and_ignores_partial_payloads(): void
    {
        $obUser = $this->createAccount('profile-event@example.com');

        $mResponse = Event::fire(UserAddressProfileHandler::EVENT_UPDATE, [
            new stdClass,
            $obUser,
            [
                'first_name' => 'Anna',
                'shipping_address' => [
                    'address1' => 'Brivibas iela 10',
                    'city' => 'Riga',
                    'postcode' => 'LV-1010',
                    'country' => 'Latvia',
                ],
                'billing_address' => [
                    'address1' => 'Elizabetes iela 5',
                    'city' => 'Riga',
                    'postcode' => 'LV-1011',
                    'country' => 'Latvia',
                ],
            ],
        ], true);

        $this->assertNull($mResponse, 'profile update listener must not hijack ActionUpdateProfile redirect');
        $this->assertSame(2, UserAddress::where('user_id', $obUser->id)->count());

        // A partial profile update without address keys leaves existing addresses untouched.
        Event::fire(UserAddressProfileHandler::EVENT_UPDATE, [
            new stdClass,
            $obUser,
            ['first_name' => 'Anna-Maria'],
        ], true);

        $this->assertSame(2, UserAddress::where('user_id', $obUser->id)->count());
    }

    public function test_make_order_deduplicates_repeat_shipping_and_billing_addresses_and_skips_blank_rows(): void
    {
        $obUser = $this->createAccount('repeat-buyer@example.com');

        $arShipping = [
            'address1' => 'Storgata 5',
            'address2' => '',
            'city' => 'Oslo',
            'postcode' => '0155',
            'country' => 'Norway',
            'state' => 'Oslo',
        ];
        $arBilling = [
            'address1' => 'Fakturaveien 9',
            'address2' => '',
            'city' => 'Moss',
            'postcode' => '1528',
            'country' => 'Norway',
            'state' => 'Ostfold',
        ];

        $this->submitCheckoutWithAddresses($obUser, $arShipping, $arBilling);
        $this->assertTrue(Result::status(), (string) Result::message());
        $this->assertSame(2, UserAddress::where('user_id', $obUser->id)->count());

        // Second order with identical shipping & billing must reuse existing rows (no duplicates).
        $this->submitCheckoutWithAddresses($obUser, $arShipping, $arBilling);
        $this->assertTrue(Result::status(), (string) Result::message());
        $this->assertSame(2, UserAddress::where('user_id', $obUser->id)->count());

        // Switching to a pickup-point shipping address updates the user's single shipping row in place (still 1 shipping + 1 billing).
        $arPickup = [
            'address1' => '',
            'address2' => 'Gjeddeveien 18, 1528 Moss - GRATIS',
            'city' => 'Moss',
            'postcode' => '1528',
            'country' => 'NO',
            'state' => '',
        ];
        $this->submitCheckoutWithAddresses($obUser, $arPickup, []);
        $this->assertTrue(Result::status(), (string) Result::message());
        $this->assertSame(2, UserAddress::where('user_id', $obUser->id)->count());

        $this->submitCheckoutWithAddresses($obUser, $arPickup, []);
        $this->assertTrue(Result::status(), (string) Result::message());
        $this->assertSame(2, UserAddress::where('user_id', $obUser->id)->count());

        $obPickupOrder = Order::orderByDesc('id')->first();
        $this->assertSame('Gjeddeveien 18, 1528 Moss - GRATIS', $obPickupOrder->property['shipping_address2']);
        $this->assertSame('', $obPickupOrder->property['shipping_address1']);
        $this->assertSame('Moss', $obPickupOrder->property['shipping_city']);

        // Blank address arrays leave existing shipping and billing rows untouched and create no new rows.
        $this->submitCheckoutWithAddresses(
            $obUser,
            ['address1' => '', 'address2' => '', 'city' => '', 'postcode' => '', 'country' => '', 'state' => ''],
            []
        );
        $this->assertTrue(Result::status(), (string) Result::message());
        $this->assertSame(2, UserAddress::where('user_id', $obUser->id)->count());
        $this->assertSame('Fakturaveien 9', UserAddressHelper::getCheckoutAddresses($obUser)['billing']['address1']);
    }

    public function test_user_merge_reassigns_orders_and_deduplicates_addresses(): void
    {
        $obLeading = $this->createAccount('leading@example.com');
        $obMerged = $this->createAccount('merged@example.com');

        UserAddress::create([
            'user_id' => $obLeading->id,
            'type' => UserAddressHelper::TYPE_SHIPPING,
            'address1' => 'Same Street 1',
            'city' => 'Oslo',
            'postcode' => '0150',
            'country' => 'Norway',
        ]);
        // Duplicate of leading user's address
        UserAddress::create([
            'user_id' => $obMerged->id,
            'type' => UserAddressHelper::TYPE_SHIPPING,
            'address1' => 'Same Street 1',
            'city' => 'Oslo',
            'postcode' => '0150',
            'country' => 'Norway',
        ]);
        // Distinct billing address that should move to leading user
        UserAddress::create([
            'user_id' => $obMerged->id,
            'type' => UserAddressHelper::TYPE_BILLING,
            'address1' => 'Billing Road 9',
            'city' => 'Bergen',
            'postcode' => '5003',
            'country' => 'Norway',
        ]);

        $obStatus = Status::getFirstByCode(Status::STATUS_NEW);
        $obOrder = Order::create([
            'user_id' => $obMerged->id,
            'status_id' => $obStatus ? $obStatus->id : 1,
            'payment_method_id' => CheckoutStubTables::PAYMENT_METHOD_ID,
        ]);

        $obLeading->mergeUser($obMerged);

        $this->assertSame($obLeading->id, (int) $obOrder->fresh()->user_id);
        $this->assertSame(0, UserAddress::where('user_id', $obMerged->id)->count());
        $this->assertSame(2, UserAddress::where('user_id', $obLeading->id)->count());
    }

    public function test_automatic_migration_cleans_legacy_junk_and_keeps_one_latest_row_per_type(): void
    {
        $obUser = $this->createAccount('dedupe@example.com');

        // Older courier shipping row with legacy "NaN"
        UserAddress::create([
            'user_id' => $obUser->id,
            'type' => UserAddressHelper::TYPE_SHIPPING,
            'address1' => 'Main St 1',
            'address2' => 'NaN',
            'city' => 'Riga',
            'postcode' => 'LV-1010',
            'country' => 'Latvia',
        ]);
        // Empty/junk-only row
        UserAddress::create([
            'user_id' => $obUser->id,
            'type' => UserAddressHelper::TYPE_SHIPPING,
            'address2' => 'NaN',
        ]);
        // Older pickup-point row
        UserAddress::create([
            'user_id' => $obUser->id,
            'type' => UserAddressHelper::TYPE_SHIPPING,
            'address2' => 'undefinedSelgas iela 2 , ROJA, LV3264, (Paku Skapis Maxima Roja)',
        ]);
        // Latest shipping row (trailing legacy "undefined") — must be the single shipping row kept.
        UserAddress::create([
            'user_id' => $obUser->id,
            'type' => UserAddressHelper::TYPE_SHIPPING,
            'address2' => 'Ernesta Birznieka-Upīša iela 20A, Rīgā - BEZMAKSASundefined',
            'city' => 'Rīga',
            'postcode' => 'LV-1050',
            'country' => 'LV',
        ]);
        // Billing row — kept alongside shipping (1 shipping + 1 billing per user).
        UserAddress::create([
            'user_id' => $obUser->id,
            'type' => UserAddressHelper::TYPE_BILLING,
            'address1' => 'Billing Road 9',
            'city' => 'Rīga',
            'postcode' => 'LV-1011',
            'country' => 'LV',
        ]);

        require_once __DIR__.'/../../updates/update_table_user_addresses_clean_and_dedupe.php';
        (new \Logingrupa\StoreExtender\Updates\UpdateTableUserAddressesCleanAndDedupe)->up();

        $obKept = UserAddress::where('user_id', $obUser->id)->orderBy('id')->get();
        $this->assertCount(2, $obKept, 'migration must keep only 1 latest shipping and 1 latest billing row per user');
        $this->assertSame(UserAddressHelper::TYPE_SHIPPING, $obKept[0]->type);
        $this->assertSame('Ernesta Birznieka-Upīša iela 20A, Rīgā - BEZMAKSAS', $obKept[0]->address2);
        $this->assertSame(UserAddressHelper::TYPE_BILLING, $obKept[1]->type);
        $this->assertSame('Billing Road 9', $obKept[1]->address1);
    }

    public function test_parse_legacy_addresses_script_splits_fields_while_preserving_address2_for_1c(): void
    {
        // Unit coverage across all real historical DB formats.
        $this->assertSame(
            ['address1' => 'Dundagas iela 18', 'city' => 'Talsi', 'postcode' => 'LV-3201', 'country' => 'LV'],
            LegacyPickupAddressParser::parse('undefinedDundagas iela 18 , TALSI, LV3201, (Paku Skapis Rimi Talsi)')
        );
        $this->assertSame(
            ['address1' => 'Skolas iela 4e', 'city' => 'Salaspils', 'postcode' => 'LV-2121', 'country' => 'LV'],
            LegacyPickupAddressParser::parse('undefinedSkolas iela 4e , SALASPILS, LV2121, (PS Rimi Salaspils (NOSLOGOTS))')
        );
        $this->assertSame(
            ['address1' => 'Muldavas iela 3a', 'city' => 'Dobele', 'postcode' => '', 'country' => 'LV'],
            LegacyPickupAddressParser::parse('undefinedDobele, Muldavas iela 3a (Paku Skapis TC Forums), Muldavas iela 3a (Paku Skapis TC Forums)')
        );
        $this->assertSame(
            ['address1' => 'Raiņa iela32', 'city' => 'Cēsis', 'postcode' => '', 'country' => 'LV'],
            LegacyPickupAddressParser::parse('undefinedCēsis, Raiņa iela32 (Paku Bode VIRŠI Cēsis), Raiņa iela32 (Paku Bode VIRŠI Cēsis)')
        );
        $this->assertSame(
            ['address1' => 'Ernesta Birznieka-Upīša iela 20A', 'city' => 'Rīga', 'postcode' => 'LV-1050', 'country' => 'LV'],
            LegacyPickupAddressParser::parse('Ernesta Birznieka-Upīša iela 20A, Rīgā - BEZMAKSASundefined')
        );
        $this->assertSame(
            ['address1' => 'Gjeddeveien 18', 'city' => 'Moss', 'postcode' => '1528', 'country' => 'NO'],
            LegacyPickupAddressParser::parse('Gjeddeveien 18, 1528 Moss - GRATIS')
        );
        $this->assertSame(
            ['address1' => 'Šv. Gertrūdos g. 6', 'city' => 'Kaunas', 'postcode' => 'LT-44260', 'country' => 'LT'],
            LegacyPickupAddressParser::parse('Šv. Gertrūdos g. 6, Kaunas - NEMOKAMAI')
        );
        $this->assertSame(
            ['address1' => '', 'city' => '', 'postcode' => '', 'country' => 'LV'],
            LegacyPickupAddressParser::parse('undefinedMadonas Maxima XX pakomāts')
        );

        // Command dry-run leaves DB untouched; real run backfills empty columns and preserves address2 + existing values.
        $obUser = $this->createAccount('legacy-split@example.com');
        $obLocker = UserAddress::create([
            'user_id' => $obUser->id,
            'type' => UserAddressHelper::TYPE_SHIPPING,
            'address2' => 'undefinedDundagas iela 18 , TALSI, LV3201, (Paku Skapis Rimi Talsi)',
        ]);
        $obExistingStreet = UserAddress::create([
            'user_id' => $obUser->id,
            'type' => UserAddressHelper::TYPE_SHIPPING,
            'address1' => 'Custom Street 99',
            'address2' => 'Dundagas iela 18 , TALSI, LV3201, (Paku Skapis Rimi Talsi)',
        ]);

        Artisan::call('storeextender:parse-legacy-addresses', ['--dry-run' => true]);
        $this->assertNull($obLocker->fresh()->city);

        Artisan::call('storeextender:parse-legacy-addresses');

        $obLocker->refresh();
        $this->assertSame('Dundagas iela 18', $obLocker->address1);
        $this->assertSame('Talsi', $obLocker->city);
        $this->assertSame('LV-3201', $obLocker->postcode);
        $this->assertSame('LV', $obLocker->country);
        $this->assertSame('Dundagas iela 18 , TALSI, LV3201, (Paku Skapis Rimi Talsi)', $obLocker->address2);

        // Existing non-empty address1 must never be overwritten.
        $obExistingStreet->refresh();
        $this->assertSame('Custom Street 99', $obExistingStreet->address1);
        $this->assertSame('Talsi', $obExistingStreet->city);
        $this->assertSame('LV-3201', $obExistingStreet->postcode);
    }

    public function test_backend_user_address_and_preview_extensions_are_wired(): void
    {
        $obAddress = new UserAddress(['type' => UserAddressHelper::TYPE_BILLING]);
        $this->assertArrayHasKey(UserAddressHelper::TYPE_SHIPPING, $obAddress->getTypeOptions());
        $this->assertArrayHasKey(UserAddressHelper::TYPE_BILLING, $obAddress->getTypeOptions());
        $this->assertSame('Billing address', $obAddress->type_label);

        $arTabs = array_collapse(Event::fire(ExtendUserController::EVENT_EXTEND_PREVIEW_TABS));
        $this->assertSame('$/logingrupa/storeextender/views/user/_preview_addresses.php', $arTabs['Addresses'] ?? null);
        $this->assertSame('$/logingrupa/storeextender/views/user/_preview_orders.php', $arTabs['Orders'] ?? null);

        // Verify ExtendUserPropertyFieldHandler maps active properties onto Details on preview.
        UserProperty::create([
            'active' => true,
            'name' => 'Legal address',
            'code' => 'legal_address',
            'type' => 'input',
        ]);

        $obFakeWidget = new class extends BackendFormWidget
        {
            public $context = 'preview';

            public $isNested = false;

            public $model;

            /** @var array<string, array<string, mixed>> */
            public $arAddedTabFields = [];

            /** @var list<string> */
            public $arRemovedFields = [];

            public function __construct()
            {
                $this->model = new User;
            }

            public function getController(): RainLabUsersController
            {
                return new class extends RainLabUsersController
                {
                    public function __construct() {}
                };
            }

            public function addTabFields(array $fields): ElementHolder
            {
                $this->arAddedTabFields = array_merge($this->arAddedTabFields, $fields);

                return new ElementHolder;
            }

            public function removeField($name): bool
            {
                $this->arRemovedFields[] = (string) $name;

                return true;
            }
        };

        Event::fire('backend.form.extendFields', [$obFakeWidget]);
        $this->assertContains('groups', $obFakeWidget->arRemovedFields);
        $this->assertArrayHasKey('phone', $obFakeWidget->arAddedTabFields);
        $this->assertSame('User', $obFakeWidget->arAddedTabFields['phone']['tab']);
        $this->assertArrayHasKey('property[legal_address]', $obFakeWidget->arAddedTabFields);
        $this->assertSame('Details', $obFakeWidget->arAddedTabFields['property[legal_address]']['tab']);

        // Update context adds phone on Account and the Addresses relation tab on User edit form.
        $obFakeWidget->context = 'update';
        $obFakeWidget->arAddedTabFields = [];
        Event::fire('backend.form.extendFields', [$obFakeWidget]);
        $this->assertArrayHasKey('phone', $obFakeWidget->arAddedTabFields);
        $this->assertSame('Account', $obFakeWidget->arAddedTabFields['phone']['tab']);
        $this->assertArrayHasKey('address', $obFakeWidget->arAddedTabFields);
        $this->assertSame('$/logingrupa/storeextender/views/user/_address.htm', $obFakeWidget->arAddedTabFields['address']['path']);

        // Verify backend.list.extendColumns exposes searchable phone column on RainLab.User list.
        $obFakeList = new class
        {
            public $model;

            /** @var array<string, array<string, mixed>> */
            public $arAddedColumns = [];

            public function __construct()
            {
                $this->model = new User;
            }

            public function getController(): RainLabUsersController
            {
                return new class extends RainLabUsersController
                {
                    public function __construct() {}
                };
            }

            /** @param array<string, array<string, mixed>> $arColumns */
            public function addColumns(array $arColumns): void
            {
                $this->arAddedColumns = array_merge($this->arAddedColumns, $arColumns);
            }
        };

        Event::fire('backend.list.extendColumns', [$obFakeList]);
        $this->assertArrayHasKey('phone', $obFakeList->arAddedColumns);
        $this->assertTrue($obFakeList->arAddedColumns['phone']['searchable']);
    }

    protected function createAccount(string $sEmail): User
    {
        return User::create([
            'email' => $sEmail,
            'first_name' => 'Anna',
            'password' => 'Probe12345',
            'password_confirmation' => 'Probe12345',
        ]);
    }

    /**
     * @param  array<string, mixed>  $arShipping
     * @param  array<string, mixed>  $arBilling
     * @param  array<string, mixed>  $arOrderProperty
     */
    protected function submitCheckoutWithAddresses(User $obUser, array $arShipping, array $arBilling, array $arOrderProperty = []): void
    {
        $this->forgetCheckoutState();
        $sAuthFacadeClass = UserHelper::instance()->getAuthFacade();
        $sAuthFacadeClass::login($obUser);

        $iCartID = DB::table('lovata_orders_shopaholic_carts')->insertGetId(['user_id' => $obUser->id]);
        DB::table('lovata_orders_shopaholic_cart_positions')->insert([
            'cart_id' => $iCartID,
            'item_id' => CheckoutStubTables::OFFER_ID,
            'quantity' => 1,
        ]);
        CartProcessor::$iTestCartID = $iCartID;

        request()->replace([
            'order' => [
                'payment_method_id' => CheckoutStubTables::PAYMENT_METHOD_ID,
                'shipping_type_id' => CheckoutStubTables::SHIPPING_TYPE_ID,
                'property' => $arOrderProperty,
            ],
            'user' => [
                'email' => $obUser->email,
                'name' => 'Anna',
                'last_name' => 'Test',
                'phone' => '+4799887766',
            ],
            'shipping_address' => $arShipping,
            'billing_address' => $arBilling,
        ]);

        $obMakeOrder = new MakeOrder(null, ['mode' => 'ajax']);
        $obMakeOrder->init();
        $obMakeOrder->runAjaxHandler('onCreate');
    }

    protected function forgetCheckoutState(): void
    {
        $sAuthFacadeClass = UserHelper::instance()->getAuthFacade();
        $sAuthFacadeClass::logout();
        UserHelper::forgetInstance();
        UserAddressListStore::instance()->user->clear(1);
        CartProcessor::$iTestCartID = null;
        CartProcessor::forgetInstance();
        OrderProcessor::forgetInstance();
        TaxHelper::forgetInstance();
        CurrencyHelper::forgetInstance();
        Result::setTrue();
    }
}
