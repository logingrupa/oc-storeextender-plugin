<?php namespace Logingrupa\StoreExtender\Updates;

use Db;
use Lang;
use Site;
use Schema;
use Carbon\Carbon;
use October\Rain\Database\Updates\Migration;
use Lovata\OrdersShopaholic\Classes\Item\StatusItem;

/**
 * Class UpdateTableOrderStatusesPaymentPending
 *
 * Online payments waited in a status that meant something else: .lt Paysera and .no Vipps in
 * "new" (bank transfer wording), .lv PayPal in "payment failed". They get a status of their
 * own, payment-pending, as the before-status of every method with a gateway. The canceled
 * and failed statuses lose the "PayPal" in their names, Paysera and Vipps land there too,
 * and the sort order follows the order's life: sent comes before complete.
 *
 * Names are the base text in the primary site's language plus a translation row for every
 * other site language, the way RainLab Translate stores them.
 *
 * @package Logingrupa\StoreExtender\Updates
 */
class UpdateTableOrderStatusesPaymentPending extends Migration
{
    const STATUS_TABLE = 'lovata_orders_shopaholic_statuses';
    const METHOD_TABLE = 'lovata_orders_shopaholic_payment_methods';
    const TRANSLATE_TABLE = 'rainlab_translate_attributes';
    const STATUS_MODEL = 'Lovata\OrdersShopaholic\Models\Status';

    const PAYMENT_PENDING = 'payment-pending';
    const PAYMENT_PENDING_COLOR = '#f39c12';

    const SORT_ORDER_MAP = [
        'new' => 1,
        self::PAYMENT_PENDING => 2,
        'in_progress' => 3,
        'new-payment-received' => 4,
        'new-payment-canceled' => 5,
        'new-payment-error' => 6,
        'sent' => 7,
        'complete' => 8,
        'canceled' => 9,
    ];

    const PAYPAL_NAMED_CODE_LIST = ['new-payment-canceled', 'new-payment-error'];

    /**
     * Apply migration
     */
    public function up()
    {
        if (!Schema::hasTable(self::STATUS_TABLE) || !Schema::hasTable(self::METHOD_TABLE)) {
            return;
        }

        $iPendingId = $this->findOrCreatePaymentPendingStatus();
        $this->removePayPalFromNames();
        $this->applySortOrder();
        $this->pointGatewayMethodsAt($iPendingId);
        $this->clearUnknownStatusIds();

        foreach (Db::table(self::STATUS_TABLE)->pluck('id') as $iStatusId) {
            StatusItem::clearCache($iStatusId);
        }
    }

    /**
     * @return int
     */
    protected function findOrCreatePaymentPendingStatus(): int
    {
        $iExistingId = Db::table(self::STATUS_TABLE)->where('code', self::PAYMENT_PENDING)->value('id');
        if (!empty($iExistingId)) {
            return (int) $iExistingId;
        }

        $sBaseLocale = $this->getBaseLocale();
        $iStatusId = Db::table(self::STATUS_TABLE)->insertGetId([
            'code' => self::PAYMENT_PENDING,
            'name' => $this->getStatusText('name', $sBaseLocale),
            'preview_text' => $this->getStatusText('preview_text', $sBaseLocale),
            'color' => self::PAYMENT_PENDING_COLOR,
            'sort_order' => self::SORT_ORDER_MAP[self::PAYMENT_PENDING],
            'is_user_show' => 0,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        if (!Schema::hasTable(self::TRANSLATE_TABLE)) {
            return (int) $iStatusId;
        }

        foreach ($this->getTranslationLocaleList($sBaseLocale) as $sLocale) {
            Db::table(self::TRANSLATE_TABLE)->insert([
                'model_type' => self::STATUS_MODEL,
                'model_id' => (string) $iStatusId,
                'locale' => $sLocale,
                'attribute_data' => json_encode([
                    'name' => $this->getStatusText('name', $sLocale),
                    'preview_text' => $this->getStatusText('preview_text', $sLocale),
                ], JSON_UNESCAPED_UNICODE),
            ]);
        }

        return (int) $iStatusId;
    }

    /**
     * Base names and every translation row of the canceled and failed statuses.
     */
    protected function removePayPalFromNames()
    {
        $arStatusIdList = Db::table(self::STATUS_TABLE)->whereIn('code', self::PAYPAL_NAMED_CODE_LIST)->pluck('id')->all();

        foreach (Db::table(self::STATUS_TABLE)->whereIn('id', $arStatusIdList)->get() as $obRow) {
            Db::table(self::STATUS_TABLE)->where('id', $obRow->id)->update(['name' => $this->withoutPayPal($obRow->name)]);
        }

        if (!Schema::hasTable(self::TRANSLATE_TABLE)) {
            return;
        }

        $obTranslationList = Db::table(self::TRANSLATE_TABLE)
            ->where('model_type', self::STATUS_MODEL)
            ->whereIn('model_id', array_map('strval', $arStatusIdList))
            ->get();
        foreach ($obTranslationList as $obRow) {
            $arData = (array) json_decode((string) $obRow->attribute_data, true);
            if (isset($arData['name'])) {
                $arData['name'] = $this->withoutPayPal((string) $arData['name']);
            }

            Db::table(self::TRANSLATE_TABLE)->where('id', $obRow->id)
                ->update(['attribute_data' => json_encode($arData, JSON_UNESCAPED_UNICODE)]);
        }
    }

    protected function applySortOrder()
    {
        foreach (self::SORT_ORDER_MAP as $sCode => $iSortOrder) {
            Db::table(self::STATUS_TABLE)->where('code', $sCode)->update(['sort_order' => $iSortOrder]);
        }
    }

    /**
     * @param int $iPendingId
     */
    protected function pointGatewayMethodsAt(int $iPendingId)
    {
        Db::table(self::METHOD_TABLE)
            ->whereNotNull('gateway_id')
            ->where('gateway_id', '!=', '')
            ->update(['before_status_id' => $iPendingId]);
    }

    /**
     * Bank transfer carried 0 in its status columns, an id no status has.
     */
    protected function clearUnknownStatusIds()
    {
        foreach (['before_status_id', 'after_status_id', 'cancel_status_id', 'fail_status_id'] as $sColumn) {
            Db::table(self::METHOD_TABLE)->where($sColumn, 0)->update([$sColumn => null]);
        }
    }

    /**
     * @param string $sName
     * @return string
     */
    protected function withoutPayPal(string $sName): string
    {
        return trim(preg_replace('/\s+/u', ' ', str_ireplace('paypal', '', $sName)));
    }

    /**
     * @return string
     */
    protected function getBaseLocale(): string
    {
        $obPrimarySite = Site::getPrimarySite();

        return $obPrimarySite ? (string) $obPrimarySite->hard_locale : (string) config('app.locale');
    }

    /**
     * @param string $sBaseLocale
     * @return array every other site language, enabled or not
     */
    protected function getTranslationLocaleList(string $sBaseLocale): array
    {
        $arLocaleList = Site::listSites()->map(fn ($obSite) => (string) $obSite->hard_locale)->unique()->all();

        return array_values(array_diff($arLocaleList, [$sBaseLocale]));
    }

    /**
     * @param string $sField
     * @param string $sLocale
     * @return string
     */
    protected function getStatusText(string $sField, string $sLocale): string
    {
        return Lang::get('logingrupa.storeextender::order_status.payment_pending.'.$sField, [], $sLocale);
    }
}
