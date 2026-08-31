<?php namespace Logingrupa\StoreExtender\Classes\Event\User;

use Lang;
use Validator;

use Lovata\Toolbox\Classes\Helper\UserHelper;

/**
 * Class RainLabRegistrationHandler
 * @package Logingrupa\StoreExtender\Classes\Event\User
 *
 * Creates the account for RainLab's registration component.
 *
 * RainLab\User\Components\Registration::createNewUser() hard codes a four field whitelist -
 * first_name, last_name, email, password - and requires first_name. The shop's register form
 * posts neither name, and it does post phone and property[...], which carry the B2B invoicing
 * payload and the school code that decides the user's price group. Left to the component,
 * every registration fails on the missing name and every property field is silently dropped.
 *
 * rainlab.user.beforeRegister is the documented seam for exactly this: a listener that
 * returns a user replaces createNewUser().
 *
 * Email, username and the password policy are not re-validated here: the model rules already
 * cover them and October's Validation trait raises the same ValidationException keys the form
 * renders. The security answer is checked here rather than on the model because RainLab's User
 * has no way to carry the translated messages, and because it is an anti-bot question on this
 * one form rather than an invariant of every account.
 */
class RainLabRegistrationHandler
{
    const BUDDIES_PLUGIN_NAME = 'Lovata.Buddies';
    const EVENT_BEFORE_REGISTER = 'rainlab.user.beforeRegister';
    const SECURITY_ANSWER = '5';

    /** Posted fields that map straight onto the user. "name" is aliased onto first_name. */
    const FIELD_LIST = ['name', 'last_name', 'email', 'password', 'password_confirmation', 'phone', 'property'];

    /**
     * Add listeners
     * @param \Illuminate\Events\Dispatcher $obEvent
     */
    public function subscribe($obEvent)
    {
        if (UserHelper::instance()->getPluginName() == self::BUDDIES_PLUGIN_NAME) {
            return;
        }

        $obEvent->listen(self::EVENT_BEFORE_REGISTER, function ($obComponent, &$arInput) {
            return $this->createUser($arInput);
        });
    }

    /**
     * @param array $arInput the raw registration POST
     * @return \RainLab\User\Models\User
     */
    protected function createUser($arInput)
    {
        $sUserModelClass = UserHelper::instance()->getUserModel();
        if (empty($sUserModelClass)) {
            throw new \LogicException('No user plugin is active, registration cannot create an account');
        }

        $this->assertSecurityAnswer($arInput);

        // The component only defaults this when it runs its own createNewUser().
        if (!array_key_exists('password_confirmation', $arInput)) {
            $arInput['password_confirmation'] = $arInput['password'] ?? '';
        }

        $arFieldList = [];
        foreach (self::FIELD_LIST as $sField) {
            if (!array_key_exists($sField, $arInput)) {
                continue;
            }

            $arFieldList[$sField] = $arInput[$sField];
        }

        return $sUserModelClass::create($arFieldList);
    }

    /**
     * The register form's anti-bot question. Keyed "property.security" so the existing
     * data-validate-for element renders the message.
     *
     * @param array $arInput
     * @return void
     */
    protected function assertSecurityAnswer($arInput)
    {
        Validator::make(
            $arInput,
            ['property.security' => 'required|in:'.self::SECURITY_ANSWER],
            [
                'property.security.required' => Lang::get('logingrupa.storeextender::lang.message.e_security_required'),
                'property.security.in' => Lang::get('logingrupa.storeextender::lang.message.e_security_in'),
            ]
        )->validate();
    }
}
