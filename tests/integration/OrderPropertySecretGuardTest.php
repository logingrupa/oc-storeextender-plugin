<?php

use Lovata\OrdersShopaholic\Models\Order;

/**
 * Proves the credential guard is actually WIRED, not just correct in isolation.
 *
 * The unit test calls stripSecretFields() directly; this one builds a real Order
 * through the real plugin boot and fires the real model event, so a handler that
 * is never registered - or registered against the wrong event - fails here.
 *
 * No row is written: model.beforeSave is fired directly, which is the same hook
 * October's own Multisite trait binds, so no OrdersShopaholic migration chain is
 * needed on SQLite.
 */
class OrderPropertySecretGuardTest extends StoreExtenderPluginTestCase
{
    protected $autoMigrate = false;

    public function testBeforeSaveStripsPasswordFromProperty()
    {
        $obOrder = new Order();
        $obOrder->property = [
            'name'     => 'Anna',
            'email'    => 'a@b.lv',
            'password' => 'ShouldNeverPersist123',
        ];

        $obOrder->fireEvent('model.beforeSave');

        $arProperty = $obOrder->property;
        $this->assertIsArray($arProperty);
        $this->assertArrayNotHasKey('password', $arProperty, 'guard is not registered on model.beforeSave');
        $this->assertSame('a@b.lv', $arProperty['email']);
        $this->assertSame('Anna', $arProperty['name']);
    }

    public function testBeforeSaveStripsPasswordConfirmation()
    {
        $obOrder = new Order();
        $obOrder->property = [
            'email'                 => 'a@b.lv',
            'password_confirmation' => 'ShouldNeverPersist123',
        ];

        $obOrder->fireEvent('model.beforeSave');

        $this->assertArrayNotHasKey('password_confirmation', $obOrder->property);
    }

    public function testRawAttributeCarriesNoCredentialAfterEvent()
    {
        $obOrder = new Order();
        $obOrder->property = ['email' => 'a@b.lv', 'password' => 'ShouldNeverPersist123'];

        $obOrder->fireEvent('model.beforeSave');

        // property is jsonable, so the stored attribute is the encoded string that
        // would reach the database.
        $arAttributes = $obOrder->getAttributes();
        $this->assertStringNotContainsString('ShouldNeverPersist123', (string) $arAttributes['property']);
    }
}
