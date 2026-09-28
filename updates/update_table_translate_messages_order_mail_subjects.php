<?php namespace Logingrupa\StoreExtender\Updates;

use Schema;
use System\Models\SiteDefinition;
use RainLab\Translate\Models\Message;
use October\Rain\Database\Updates\Migration;

/**
 * Class UpdateTableTranslateMessagesOrderMailSubjects
 *
 * The order mail subjects used to be hand-typed in each shop's database row, so the shared
 * template had to carry one language. They are translated strings now, and only the .no shop
 * had the message rows. Seeds the missing ones for every locale the site serves.
 *
 * Existing values win: updateObservedMessages() merges without overwriting, so a subject a
 * shop already translated in the backend stays untouched.
 *
 * @package Logingrupa\StoreExtender\Updates
 */
class UpdateTableTranslateMessagesOrderMailSubjects extends Migration
{
    const MESSAGE_LIST = [
        'lv' => [
            'Your order No.'    => 'Jūsu pasūtījums Nr.',
            'has been received' => 'ir saņemts',
            'NEW ORDER'         => 'JAUNS PASŪTĪJUMS',
        ],
        'lt' => [
            'Your order No.'    => 'Jūsų užsakymas Nr.',
            'has been received' => 'gautas',
            'NEW ORDER'         => 'NAUJAS UŽSAKYMAS',
        ],
        'ru' => [
            'Your order No.'    => 'Ваш заказ №',
            'has been received' => 'получен',
            'NEW ORDER'         => 'НОВЫЙ ЗАКАЗ',
        ],
        'nb-no' => [
            'Your order No.'    => 'Din bestilling nr.',
            'has been received' => 'er mottatt',
            'NEW ORDER'         => 'NY BESTILLING',
        ],
        'de' => [
            'Your order No.'    => 'Ihre Bestellung Nr.',
            'has been received' => 'ist eingegangen',
            'NEW ORDER'         => 'NEUE BESTELLUNG',
        ],
    ];

    /**
     * Apply migration
     */
    public function up()
    {
        if (!Schema::hasTable('rainlab_translate_message_data') || !Schema::hasTable('system_site_definitions')) {
            return;
        }

        $obMessage = new Message();
        $arLocaleList = SiteDefinition::pluck('locale')->unique()->all();

        foreach ($arLocaleList as $sLocale) {
            $arMessageList = self::MESSAGE_LIST[$sLocale] ?? null;
            if ($arMessageList === null) {
                continue;
            }

            $obMessage->updateObservedMessages($sLocale, $arMessageList);
        }
    }
}
